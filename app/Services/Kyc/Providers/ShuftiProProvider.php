<?php

namespace App\Services\Kyc\Providers;

use App\Contracts\Kyc\HostedKycSessionInterface;
use App\Enums\Kyc\KycVerdict;
use App\Models\KycSubmission;
use App\Services\Kyc\KycHostedSession;
use App\Services\Kyc\KycVerificationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShuftiPro — global coverage. Basic-auth REST API with asynchronous callbacks.
 *
 * @see https://developers.shuftipro.com
 */
class ShuftiProProvider extends AbstractKycProvider implements HostedKycSessionInterface
{
    private const BASE_URL = 'https://api.shuftipro.com/';

    /**
     * Minutes a hosted verification link stays usable. ShuftiPro caps `ttl` at
     * 60, and takes it in minutes rather than seconds.
     */
    private const SESSION_TTL_MINUTES = 60;

    /**
     * The documented limit is 16MB and no page says whether it counts the
     * file or the base64, which is roughly a third larger. This stays under
     * it on either reading.
     */
    private const MAX_PROOF_BYTES = 12 * 1024 * 1024;

    public function verify(KycSubmission $submission): KycVerificationResult
    {
        if (! $this->hasCredentials(['client_id', 'secret_key'])) {
            return KycVerificationResult::review(__('ShuftiPro credentials are not configured.'));
        }

        $reference = $this->reference($submission);

        /*
         * Offsite, not onsite — and the only difference is that `proof` is
         * populated. There is no mode flag and no second endpoint.
         *
         * This used to send empty `document` and `face` objects, which is a
         * perfectly valid ONSITE request: ShuftiPro collects the documents
         * from the applicant itself and hands back a `verification_url`. We
         * threw that url away and returned the mapped event, so nobody was
         * ever sent anywhere and no verification could complete — while the
         * applicant's own documents sat on our disk the whole time.
         *
         * Docs: "In the offsite verification process, ShuftiPro's clients are
         * solely responsible for gathering all necessary proof from the end
         * user and then submitting it to ShuftiPro for verification."
         */
        $documents = $this->documents($submission);
        $idProof   = $this->proof($documents['id_document']);

        if ($idProof === null) {
            /*
             * No document, no offsite request. Falling through to an empty
             * `document` object would quietly reopen the onsite flow whose
             * url we discard, which is the defect this replaces.
             */
            return KycVerificationResult::review(
                __('No identity document was attached to this submission; routed to manual review.'),
                50,
                $reference
            );
        }

        $faceProof = $this->proof($documents['selfie']);

        try {
            $response = Http::withBasicAuth((string) $this->credential('client_id'), (string) $this->credential('secret_key'))
                ->acceptJson()
                ->timeout(60)
                ->post(self::BASE_URL, array_filter([
                    'reference'         => $reference,
                    'callback_url'      => route('webhooks.kyc', ['provider' => $this->config->code]),
                    'country'           => $submission->user->country ?? null,
                    'verification_mode' => 'image_only',
                    'document'          => [
                        'proof' => $idProof,
                        // Only the four the document service accepts; the longer
                        // list on the coverage page belongs to address verification.
                        'supported_types' => ['id_card', 'passport', 'driving_license'],
                        /*
                         * "0" because we hold one side. Setting it to "1"
                         * obliges us to supply the back in `additional_proof`
                         * and turns on front/back matching, which would fail
                         * every submission that only captured the front.
                         */
                        'backside_proof_required' => '0',
                    ],
                    /*
                     * Sent only when we actually have a selfie: an empty `face`
                     * object is the onsite request again.
                     *
                     * check_duplicate_request stays off. It enables
                     * cross-customer face matching, which is a decision about
                     * other people's biometrics, not one to inherit from a
                     * documentation sample.
                     */
                    'face' => $faceProof === null ? null : [
                        'proof'                   => $faceProof,
                        'check_duplicate_request' => 0,
                    ],
                ], fn (mixed $value): bool => $value !== null));

            /*
             * The verdict is in THIS response, not only on the webhook:
             * "In case of off-site verification, verification.accepted or
             * verification.declined is returned in event when request is
             * valid after verification." The callback stays wired for the
             * later `verification.status.changed`.
             */
            return $this->mapEvent($response->json('event'), $reference, $response->json() ?? []);
        } catch (Throwable $e) {
            Log::warning('ShuftiPro verify failed: '.$e->getMessage());

            return KycVerificationResult::review(__('Provider unavailable; routed to manual review.'), 50, $reference);
        }
    }

    /**
     * Asking for a verification with empty `document` and `face` objects — and
     * no proofs attached — makes ShuftiPro host the capture itself and hand
     * back a `verification_url` to send the applicant to.
     */
    public function createHostedSession(KycSubmission $submission, string $returnUrl): ?KycHostedSession
    {
        if (! $this->hasCredentials(['client_id', 'secret_key'])) {
            /*
             * The applicant sees the same "could not reach the verification
             * provider" for this as for a refusal, so this is the only place
             * an operator can learn the connection was never configured.
             */
            Log::warning('ShuftiPro hosted session skipped: client ID or secret key is not configured', [
                'provider' => $this->config->code,
            ]);

            return null;
        }

        /*
         * Always a new reference. ShuftiPro requires one "unique per request",
         * and a submission reopened after a rejection still carries the
         * reference of the attempt that was rejected - reusing it had every
         * resubmission refused for as long as the row existed.
         */
        $reference = $this->reference($submission);
        $country   = strtoupper(trim((string) $submission->user?->country));

        try {
            $response = Http::withBasicAuth((string) $this->credential('client_id'), (string) $this->credential('secret_key'))
                ->acceptJson()
                ->timeout(30)
                ->post(self::BASE_URL, array_filter([
                    'reference'    => $reference,
                    'callback_url' => route('webhooks.kyc', ['provider' => $this->config->code]),
                    'redirect_url' => $returnUrl,
                    // Omitted rather than sent as null for an account with no
                    // country on file; ShuftiPro wants an ISO 3166-1 alpha-2 code.
                    'country'           => strlen($country) === 2 ? $country : null,
                    'language'          => 'EN',
                    'ttl'               => self::SESSION_TTL_MINUTES,
                    'verification_mode' => 'any',
                    'document'          => ['supported_types' => ['id_card', 'passport', 'driving_license']],
                    'face'              => (object) [],
                ], fn (mixed $value): bool => $value !== null));

            $url = (string) $response->json('verification_url', '');

            if (! $response->successful() || $url === '') {
                /*
                 * ShuftiPro says why in `event` and `error.message` - most often
                 * that the site's domain is not registered for callback and
                 * redirect URLs in its Back Office. Logging only the HTTP status
                 * left an operator with "400" and nothing to act on.
                 */
                Log::warning('ShuftiPro returned no verification URL', [
                    'status'    => $response->status(),
                    'event'     => $response->json('event'),
                    'key'       => $response->json('error.key'),
                    'error'     => $response->json('error.message'),
                    'reference' => $reference,
                ]);

                return null;
            }

            return new KycHostedSession(
                url: $url,
                reference: $reference,
                expiresAt: now()->addMinutes(self::SESSION_TTL_MINUTES),
                payload: array_merge($response->json() ?? [], ['provider' => $this->config->code]),
            );
        } catch (Throwable $e) {
            Log::warning('ShuftiPro hosted session failed: '.$e->getMessage());

            return null;
        }
    }

    public function handleWebhook(Request $request): KycVerificationResult
    {
        if (! $this->signatureIsValid($request)) {
            return KycVerificationResult::review(__('Invalid ShuftiPro signature.'));
        }

        return $this->mapEvent(
            $request->input('event'),
            $request->input('reference'),
            $request->all()
        );
    }

    public function checkStatus(KycSubmission $submission): KycVerificationResult
    {
        if (! $this->hasCredentials(['client_id', 'secret_key']) || blank($submission->provider_reference)) {
            return KycVerificationResult::review();
        }

        try {
            $response = Http::withBasicAuth((string) $this->credential('client_id'), (string) $this->credential('secret_key'))
                ->acceptJson()
                ->timeout(20)
                ->post(self::BASE_URL.'status', ['reference' => $submission->provider_reference]);

            return $this->mapEvent($response->json('event'), $submission->provider_reference, $response->json() ?? []);
        } catch (Throwable $e) {
            Log::warning('ShuftiPro status check failed: '.$e->getMessage());

            return KycVerificationResult::review();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    /**
     * One stored document as the base64 data URI ShuftiPro's `proof` takes.
     *
     * The prefix is documented by example rather than by rule — the prose
     * asks only for "valid BASE64 encoded string", while every worked example
     * on every offsite page writes `data:image/png;base64,...`. Sending it is
     * the option that matches all of them, and the mime is taken from the
     * actual file rather than copied from the sample, since our applicants
     * upload jpegs far more often than pngs.
     */
    private function proof(?string $stored): ?string
    {
        $document = $this->readDocument($stored);

        if ($document === null) {
            return null;
        }

        /*
         * The documented ceiling is 16MB, and no page says whether it counts
         * the file or the base64 (which is about a third larger). 12MB of raw
         * bytes stays under it either way; a document over that goes to a
         * human rather than being sent and silently refused.
         */
        if ($document['size'] > self::MAX_PROOF_BYTES) {
            Log::warning('ShuftiPro proof skipped: '.$document['size'].' bytes exceeds the safe offsite ceiling.');

            return null;
        }

        return 'data:'.$document['mime'].';base64,'.base64_encode($document['bytes']);
    }

    private function mapEvent(?string $event, ?string $reference, array $payload): KycVerificationResult
    {
        $payload['provider'] = $this->config->code;

        return match ($event) {
            'verification.accepted' => new KycVerificationResult(KycVerdict::Clear, 5, $reference, null, $payload['verification_result'] ?? [], $payload),
            'verification.declined' => new KycVerificationResult(KycVerdict::Rejected, 90, $reference, $payload['declined_reason'] ?? __('Verification declined.'), [], $payload),
            'review.pending', 'verification.cancelled' => new KycVerificationResult(KycVerdict::Review, 55, $reference, __('Provider flagged the case for review.'), [], $payload),
            default => KycVerificationResult::pending($reference, $payload),
        };
    }

    private function signatureIsValid(Request $request): bool
    {
        $provided = (string) $request->header('Signature', '');
        $secret   = (string) $this->credential('secret_key');

        if ($provided === '' || $secret === '') {
            return false;
        }

        /*
         * ShuftiPro signs two ways depending on the account's age: "SHA256 of
         * response + SHA256(secret_key)" for clients registered on or after
         * 15 March 2023, and "response + secret_key" for older ones that have
         * not rotated their key. Checking only the newer form sent every
         * verdict from an older account to manual review as "Invalid
         * signature". Both still need the secret, so neither is weaker.
         */
        $body    = $request->getContent();
        $current = hash('sha256', $body.hash('sha256', $secret));
        $legacy  = hash('sha256', $body.$secret);

        return hash_equals($current, $provided) || hash_equals($legacy, $provided);
    }
}
