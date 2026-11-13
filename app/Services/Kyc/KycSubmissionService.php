<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Enums\KycReviewAction;
use App\Enums\KycStatus;
use App\Enums\KycVerificationMethod;
use App\Exceptions\KycSubmissionFailed;
use App\Exceptions\NotifyErrorException;
use App\Jobs\ProcessKycVerification;
use App\Models\Admin;
use App\Models\KycReviewLog;
use App\Models\KycSubmission;
use App\Models\KycTemplate;
use App\Models\User;
use App\Notifications\TemplateNotification;
use App\Support\KycDocumentDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sending a KYC submission, by form or by provider handover.
 *
 * Lifted out of the website's KycSubmissionController so the app submits
 * through exactly the same steps: the one-active-submission rule, the private
 * document disk, the fingerprints, the consent record, the audit trail, the
 * admin notice and the automation hand-off. Two copies of this would drift the
 * way every other copied flow in this codebase has.
 *
 * Two things changed on the way out, both of them the reason to do it here:
 *
 * ONE SUBMISSION AT A TIME, ENFORCED. kyc_submissions.user_id has no unique
 * index, and "is there an active one?" was read and then acted on with nothing
 * between them - so two submits arriving together, which is what a double tap
 * on a phone is, could both see none and both open one. Each path now locks the
 * applicant's user row first, so the second waits and then sees the first.
 * (SQLite ignores the lock, so the test suite cannot demonstrate it.)
 *
 * A FAULT NO LONGER SHOWS ITS INSIDES. The website rethrew every failure as
 * "Failed to submit KYC: " plus the raw message, which put driver errors in
 * front of the applicant and turned the ordinary "you already have one" refusal
 * into a failure. Refusals now keep their type; faults become KycSubmissionFailed
 * with one safe sentence. See that class for why the cause is not chained.
 */
final class KycSubmissionService
{
    public function __construct(
        private readonly KycDocumentFingerprint $fingerprint,
        private readonly KycJourneyResolver $journey,
    ) {}

    /**
     * Submit the template form.
     *
     * @param array<string, mixed> $credentials The validated answers, keyed by
     *                                          field label. A file field holds
     *                                          its UploadedFile.
     *
     * @throws NotifyErrorException A refusal the applicant can act on.
     * @throws KycSubmissionFailed  A fault; its message is safe to show.
     */
    public function submit(User $user, KycTemplate $template, array $credentials, ?string $note, ?string $ip): KycSubmission
    {
        // Documents written during this attempt. A rollback undoes the rows,
        // not the files, so a failed attempt removes these itself.
        $stored = [];

        try {
            $submission = DB::transaction(function () use ($user, $template, $credentials, $note, $ip, &$stored): KycSubmission {
                $existing = $this->lockAndReadCurrent($user);

                if ($existing && $existing->status !== KycStatus::REJECTED) {
                    throw new NotifyErrorException(__('You already have an active KYC submission.'));
                }

                $previousStatus = $existing?->status;

                if ($existing) {
                    $submission = $existing;
                    $submission->update(['notes' => $note, 'status' => KycStatus::PENDING]);
                } else {
                    $submission = KycSubmission::create([
                        'kyc_template_id' => $template->id,
                        'notes'           => $note,
                        'user_id'         => $user->id,
                        'status'          => KycStatus::PENDING,
                    ]);
                }

                $submissionData = [];

                foreach ($template->fields as $field) {
                    $value = $credentials[$field['label']] ?? null;

                    /*
                     * The private disk, never the trait's public one: that is
                     * right for an avatar and wrong for a passport, which it
                     * once left reachable by URL with no session behind it.
                     */
                    if (($field['type'] ?? null) === 'file' && $value instanceof UploadedFile) {
                        $value    = KycDocumentDisk::store($value);
                        $stored[] = $value;
                    }

                    $submissionData[$field['label']] = $value;
                }

                $submission->update(array_merge(
                    ['submission_data' => $submissionData],
                    $this->consentAttributes($ip),
                ));

                // Inside the transaction: digests describing a submission that
                // was rolled back would match a document nobody can open.
                $this->fingerprint->sync($submission);

                KycReviewLog::create([
                    'kyc_submission_id' => $submission->id,
                    'admin_id'          => null,
                    'action'            => $existing ? KycReviewAction::Resubmitted : KycReviewAction::Submitted,
                    'from_status'       => $previousStatus,
                    'to_status'         => KycStatus::PENDING,
                    'remarks'           => $note,
                ]);

                return $submission;
            });
        } catch (NotifyErrorException $refusal) {
            $this->forgetDocuments($stored);

            throw $refusal;
        } catch (Throwable $fault) {
            $this->forgetDocuments($stored);
            $this->logFault('KYC submission failed', $user, $fault);

            throw new KycSubmissionFailed(__('Your KYC submission could not be saved. Please try again.'));
        }

        $this->notifyAdmins($user, (string) $template->title);

        // The manual review queue stays the fallback for anything the
        // provider pipeline cannot clear.
        if (KycAutomationSettings::enabled()) {
            ProcessKycVerification::dispatch($submission->id)->afterCommit();
        }

        return $submission;
    }

    /**
     * Hand the applicant to a provider's own capture flow.
     *
     * Nothing is uploaded here: a submission is opened to hang the eventual
     * verdict on, the provider is asked for a session, and the caller sends the
     * applicant to the address returned. The verdict arrives on the webhook.
     *
     * An applicant who already has a live session is given that one back
     * rather than refused - closing the tab should not strand them until a
     * reviewer acts.
     *
     * @return string Where to send the applicant.
     *
     * @throws NotifyErrorException
     */
    public function startHosted(User $user, string $returnUrl, ?string $ip): string
    {
        $provider = $this->journey->hostedProviderFor($user);

        if (! $provider) {
            throw new NotifyErrorException(__('Automated verification is not available for your account right now.'));
        }

        [$submission, $previousStatus, $created, $resumeUrl] = DB::transaction(function () use ($user, $ip): array {
            $existing = $this->lockAndReadCurrent($user);

            if ($existing && $existing->status !== KycStatus::REJECTED) {
                if ($existing->hasLiveHostedSession()) {
                    return [null, null, false, (string) $existing->provider_session_url];
                }

                throw new NotifyErrorException(__('You already have an active KYC submission.'));
            }

            $consent = $this->consentAttributes($ip);

            if ($existing) {
                $previousStatus = $existing->status;

                $existing->update(array_merge($consent, [
                    'status'              => KycStatus::PENDING,
                    'verification_method' => KycVerificationMethod::Auto,
                ]));

                return [$existing, $previousStatus, false, null];
            }

            $submission = KycSubmission::create(array_merge($consent, [
                // A hosted journey has no local form behind it.
                'kyc_template_id'     => null,
                'user_id'             => $user->id,
                'status'              => KycStatus::PENDING,
                'verification_method' => KycVerificationMethod::Auto,
            ]));

            return [$submission, null, true, null];
        });

        if ($resumeUrl !== null) {
            return $resumeUrl;
        }

        // Deliberately outside the transaction: a network call to a third party
        // must never hold a database lock open.
        $session = $provider->createHostedSession($submission, $returnUrl);

        if (! $session) {
            $this->abandonHostedSubmission($submission, $previousStatus, $created);

            throw new NotifyErrorException(__('We could not reach the verification provider. Please try again in a few minutes.'));
        }

        $submission->update([
            'provider'                    => $session->payload['provider'] ?? $provider->driver(),
            'provider_reference'          => $session->reference,
            'provider_session_url'        => $session->url,
            'provider_session_expires_at' => $session->expiresAt,
        ]);

        KycReviewLog::create([
            'kyc_submission_id' => $submission->id,
            'admin_id'          => null,
            'action'            => $previousStatus === KycStatus::REJECTED ? KycReviewAction::Resubmitted : KycReviewAction::Submitted,
            'from_status'       => $previousStatus,
            'to_status'         => KycStatus::PENDING,
            'remarks'           => __('Handed to :provider for automated verification.', ['provider' => $provider->providerName()]),
        ]);

        $this->notifyAdmins($user, $provider->providerName());

        return (string) $session->url;
    }

    /**
     * Serialise this applicant's submissions, then read the current one.
     *
     * Read after the lock and from the database, not from a relation loaded
     * earlier in the request - that copy is exactly the stale answer the lock
     * exists to prevent.
     */
    private function lockAndReadCurrent(User $user): ?KycSubmission
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        return $user->kycSubmission()->first();
    }

    /**
     * Undo an opened submission whose handover never happened.
     *
     * Nothing left the site, so a documentless PENDING row would only give a
     * reviewer something they cannot act on.
     */
    private function abandonHostedSubmission(KycSubmission $submission, ?KycStatus $previousStatus, bool $wasCreated): void
    {
        DB::transaction(function () use ($submission, $previousStatus, $wasCreated): void {
            if ($wasCreated) {
                $submission->delete();

                return;
            }

            $submission->update([
                'status'              => $previousStatus,
                'consent_accepted_at' => null,
                'consent_ip'          => null,
                'consent_text'        => null,
            ]);
        });
    }

    /**
     * The applicant's consent record for this submission.
     *
     * Empty while automated verification is off: the documents never leave the
     * site, so there is nothing to agree to. When it is on, the form request
     * has already enforced acceptance, and the wording shown is stored verbatim
     * so a later edit to the statement cannot rewrite history. Explicit nulls,
     * so a resubmission does not keep an earlier attempt's consent attached to
     * documents the applicant never agreed to share.
     *
     * @return array<string, mixed>
     */
    private function consentAttributes(?string $ip): array
    {
        if (! KycAutomationSettings::requiresConsent()) {
            return [
                'consent_accepted_at' => null,
                'consent_ip'          => null,
                'consent_text'        => null,
            ];
        }

        return [
            'consent_accepted_at' => now(),
            'consent_ip'          => $ip,
            'consent_text'        => KycAutomationSettings::consentText(),
        ];
    }

    /**
     * Tell reviewing admins, as a best-effort side effect. The submission is
     * already committed, so a missing permission or template must never undo it.
     */
    private function notifyAdmins(User $user, string $kycType): void
    {
        try {
            Notification::send(Admin::permission('kyc-notification')->get(), new TemplateNotification(
                identifier: 'kyc_admin_notify_submission',
                data: [
                    'user'     => $user->name,
                    'kyc_type' => $kycType,
                ],
                sender: $user,
                action: route('admin.kyc.pending'),
            ));
        } catch (Throwable $e) {
            Log::warning('KYC submission admin notification failed', ['exception' => $e::class]);
        }
    }

    /**
     * Remove the documents a failed attempt wrote.
     *
     * Best effort, one at a time: a file that will not delete must not stop
     * the others going, nor replace the refusal or fault the caller is owed.
     * The path is a generated name and safe to log; the document is not read.
     *
     * @param list<string> $paths
     */
    private function forgetDocuments(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                KycDocumentDisk::forget($path);
            } catch (Throwable $e) {
                Log::warning('KYC document from a failed submission could not be removed', [
                    'path'      => $path,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * Enough to find the fault, and nothing an identity document holds: a
     * database error raised here carries the submission's values in its
     * bindings, so the message itself is not written.
     */
    private function logFault(string $what, User $user, Throwable $fault): void
    {
        Log::error($what, [
            'user_id'   => $user->getKey(),
            'exception' => $fault::class,
            'at'        => basename($fault->getFile()).':'.$fault->getLine(),
        ]);
    }
}
