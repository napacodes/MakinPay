<?php

namespace App\Services\Kyc\Providers;

use App\Contracts\Kyc\HostedKycSessionInterface;
use App\Enums\Kyc\KycVerdict;
use App\Models\KycSubmission;
use App\Services\Kyc\KycHostedSession;
use App\Services\Kyc\KycVerificationResult;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sumsub — enterprise-grade global KYC/AML. App-token + signed requests, with
 * an applicant lifecycle finalised asynchronously via the `applicantReviewed`
 * webhook.
 *
 * @see https://docs.sumsub.com
 */
class SumsubProvider extends AbstractKycProvider implements HostedKycSessionInterface
{
    /**
     * How long a generated WebSDK link stays usable. Sumsub caps this per
     * account; an hour is long enough for someone to find their documents and
     * short enough that an abandoned link does not linger.
     */
    private const SESSION_TTL_SECONDS = 3600;

    private function baseUrl(): string
    {
        return $this->config->isSandbox()
            ? 'https://api.sumsub.com'
            : 'https://api.sumsub.com';
    }

    public function verify(KycSubmission $submission): KycVerificationResult
    {
        if (! $this->hasCredentials(['app_token', 'secret_key'])) {
            return KycVerificationResult::review(__('Sumsub credentials are not configured.'));
        }

        $reference = $this->reference($submission);

        /*
         * Creating the applicant was the whole of this method, and creating an
         * applicant verifies nothing. No document ever left this server, and
         * nothing asked Sumsub to look at one — so every submission returned
         * Pending against a job that could not produce a verdict.
         *
         * The real sequence is four steps, and the last is the one that is
         * easiest to miss: "The applicant cannot be moved to the pending
         * status for a check if one of the verification steps is not active",
         * and uploading images does not by itself start a review.
         */
        $country = $this->iso3($submission->user?->country);

        if ($country === null) {
            /*
             * Never guessed. Sumsub wants alpha-3 and rejects a document whose
             * country disagrees with it (differentDocTypeOrCountry), which
             * leaves the applicant waiting on a verdict that never comes. An
             * unmapped country is a job for a person; config/kyc.php `iso3` is
             * where an operator adds their own.
             */
            return KycVerificationResult::review(
                __('This country is not mapped for automated verification; routed to manual review.'),
                50,
                $reference
            );
        }

        $documents  = $this->documents($submission);
        $idDocument = $this->readDocument($documents['id_document']);

        if ($idDocument === null) {
            return KycVerificationResult::review(
                __('No identity document was attached to this submission; routed to manual review.'),
                50,
                $reference
            );
        }

        $level = (string) $this->credential('level_name', 'basic-kyc-level');

        try {
            // 1. The applicant. 409 means it already exists, which a retry is.
            $applicant = $this->signedRequest(
                'POST',
                '/resources/applicants?levelName='.rawurlencode($level),
                json_encode(['externalUserId' => 'DK-KYC-'.$submission->id]) ?: '{}'
            );

            if (! $applicant->successful() && $applicant->status() !== 409) {
                return KycVerificationResult::review(__('Sumsub rejected the request.'), 55, $reference);
            }

            $applicantId = $this->applicantIdFor($applicant, 'DK-KYC-'.$submission->id);

            if ($applicantId === null) {
                return KycVerificationResult::review(__('Sumsub gave no applicant id.'), 55, $reference);
            }

            // 2. The document itself. This is the step that transmits bytes.
            $upload = $this->uploadDocument($applicantId, $country, $idDocument);

            if (! $upload->successful()) {
                Log::warning('Sumsub document upload failed: HTTP '.$upload->status());

                return KycVerificationResult::review(__('Sumsub would not accept the document.'), 55, $applicantId);
            }

            // 3. The selfie, when the submission carries one.
            $selfie = $this->readDocument($documents['selfie']);

            if ($selfie !== null) {
                $this->uploadDocument($applicantId, $country, $selfie, 'SELFIE');
            }

            /*
             * 4. Ask for the check. Without this the images sit on the
             * applicant and no review is ever performed — the quiet failure
             * that makes everything look almost right.
             */
            $this->signedRequest('POST', '/resources/applicants/'.$applicantId.'/status/pending?reason='.rawurlencode('Submitted from DigiKash'), '');

            // The verdict arrives on the webhook, keyed by this applicant id.
            return KycVerificationResult::pending($applicantId, ['provider' => $this->config->code]);
        } catch (Throwable $e) {
            Log::warning('Sumsub verify failed: '.$e->getMessage());

            return KycVerificationResult::review(__('Provider unavailable; routed to manual review.'), 50, $reference);
        }
    }

    /**
     * Sumsub hosts the capture in its WebSDK, reached through a short-lived
     * link keyed to an applicant. The applicant has to exist first, so create
     * one and then ask for the link.
     *
     * $returnUrl is unused: the WebSDK link endpoint takes no redirect target,
     * and the verdict reaches us on the webhook rather than on a return trip.
     */
    public function createHostedSession(KycSubmission $submission, string $returnUrl): ?KycHostedSession
    {
        if (! $this->hasCredentials(['app_token', 'secret_key'])) {
            return null;
        }

        /*
         * Two identifiers, and they are not interchangeable.
         *
         * `externalUserId` is ours, and is what the WebSDK link is minted
         * against. `applicantId` is Sumsub's, and it is what every webhook
         * carries and what /resources/applicants/{id}/status answers to.
         *
         * This used to send `provider_reference` as the externalUserId and
         * store that same value straight back — so the column held a DK-KYC-
         * string while the applicantReviewed webhook arrived carrying an
         * applicantId. They never matched, ApplyKycWebhookResult found no
         * row, and a real GREEN verdict went on the floor while the applicant
         * sat PENDING for good.
         *
         * So the externalUserId is derived from the submission instead of
         * read back out of the column the applicantId now occupies.
         * Deterministic on purpose: a retry has to reach the same applicant,
         * and reference() appends randomness.
         */
        $externalUserId = 'DK-KYC-'.$submission->id;
        $level          = (string) $this->credential('level_name', 'basic-kyc-level');

        try {
            $applicant = $this->signedRequest(
                'POST',
                '/resources/applicants?levelName='.rawurlencode($level),
                json_encode(['externalUserId' => $externalUserId]) ?: '{}'
            );

            // 409 means this externalUserId already has an applicant — normal
            // when someone restarts an abandoned journey, and not a failure.
            if (! $applicant->successful() && $applicant->status() !== 409) {
                Log::warning('Sumsub applicant creation failed for hosted session: HTTP '.$applicant->status());

                return null;
            }

            $reference = $this->applicantIdFor($applicant, $externalUserId);

            if ($reference === null) {
                Log::warning('Sumsub gave no applicant id for '.$externalUserId.'; refusing to open a session whose verdict could not be matched.');

                return null;
            }

            // The link is minted against OUR id, not Sumsub's.
            $link = $this->signedRequest('POST', '/resources/sdkIntegrations/levels/'
                .rawurlencode($level).'/websdkLink'
                .'?externalUserId='.rawurlencode($externalUserId)
                .'&ttlInSecs='.self::SESSION_TTL_SECONDS, '{}');

            $url = (string) $link->json('url', '');

            if (! $link->successful() || $url === '') {
                Log::warning('Sumsub returned no WebSDK link: HTTP '.$link->status());

                return null;
            }

            return new KycHostedSession(
                url: $url,
                reference: $reference,
                expiresAt: now()->addSeconds(self::SESSION_TTL_SECONDS),
                payload: array_merge($link->json() ?? [], ['provider' => $this->config->code]),
            );
        } catch (Throwable $e) {
            Log::warning('Sumsub hosted session failed: '.$e->getMessage());

            return null;
        }
    }

    public function handleWebhook(Request $request): KycVerificationResult
    {
        if (! $this->signatureIsValid($request)) {
            return KycVerificationResult::review(__('Invalid Sumsub signature.'));
        }

        $reference           = $request->input('applicantId') ?? $request->input('externalUserId');
        $answer              = (string) $request->input('reviewResult.reviewAnswer', '');
        $rejectType          = (string) $request->input('reviewResult.reviewRejectType', '');
        $payload             = $request->all();
        $payload['provider'] = $this->config->code;

        return match (true) {
            $answer === 'GREEN'                          => new KycVerificationResult(KycVerdict::Clear, 6, $reference, null, [], $payload),
            $answer === 'RED' && $rejectType === 'FINAL' => new KycVerificationResult(KycVerdict::Rejected, 92, $reference, __('Applicant rejected.'), [], $payload),
            $answer === 'RED'                            => new KycVerificationResult(KycVerdict::Review, 60, $reference, __('Applicant asked to retry.'), [], $payload),
            default                                      => KycVerificationResult::pending($reference, $payload),
        };
    }

    public function checkStatus(KycSubmission $submission): KycVerificationResult
    {
        if (! $this->hasCredentials(['app_token', 'secret_key']) || blank($submission->provider_reference)) {
            return KycVerificationResult::review();
        }

        try {
            $response            = $this->signedRequest('GET', '/resources/applicants/'.$submission->provider_reference.'/status', '');
            $answer              = (string) $response->json('reviewResult.reviewAnswer', '');
            $payload             = $response->json() ?? [];
            $payload['provider'] = $this->config->code;

            return match ($answer) {
                'GREEN' => new KycVerificationResult(KycVerdict::Clear, 6, $submission->provider_reference, null, [], $payload),
                'RED'   => new KycVerificationResult(KycVerdict::Rejected, 92, $submission->provider_reference, __('Applicant rejected.'), [], $payload),
                default => KycVerificationResult::pending($submission->provider_reference, $payload),
            };
        } catch (Throwable $e) {
            Log::warning('Sumsub status check failed: '.$e->getMessage());

            return KycVerificationResult::review();
        }
    }

    /**
     * Sumsub's own id for this applicant — the one its webhooks carry.
     *
     * A fresh create returns it in the body. A 409 does not: that status only
     * says the externalUserId is already taken, so the id has to be asked for
     * by the identifier we do know. Getting this wrong is what stranded the
     * verdicts, so it returns null rather than guessing, and the caller
     * declines to open a session it could never match a result to.
     */
    private function applicantIdFor(Response $applicant, string $externalUserId): ?string
    {
        $id = (string) $applicant->json('id', '');

        if ($id !== '') {
            return $id;
        }

        $existing = $this->signedRequest(
            'GET',
            '/resources/applicants/-;externalUserId='.rawurlencode($externalUserId).'/one',
            ''
        );

        $id = (string) $existing->json('id', '');

        return $id !== '' ? $id : null;
    }

    /**
     * ISO 3166-1 alpha-3, or null when we do not know it.
     *
     * This application stores alpha-2 everywhere and Sumsub requires alpha-3.
     * The map is in config/kyc.php so an operator can extend it for their own
     * market; an absent country returns null and the caller sends the
     * submission to a human rather than guessing at a code.
     */
    private function iso3(?string $alpha2): ?string
    {
        if (blank($alpha2)) {
            return null;
        }

        return config('kyc.iso3.'.strtoupper($alpha2));
    }

    /**
     * POST one document to an applicant as multipart/form-data.
     *
     * @param array{bytes: string, mime: string, size: int} $document
     */
    private function uploadDocument(string $applicantId, string $country, array $document, ?string $type = null): Response
    {
        $metadata = array_filter([
            'idDocType' => $type ?? (string) $this->credential('id_doc_type', 'PASSPORT'),
            'country'   => $country,
            // Only meaningful for a two-sided document, and we hold one side.
            'idDocSubType' => $type === null ? 'FRONT_SIDE' : null,
        ]);

        return $this->signedMultipart(
            '/resources/applicants/'.$applicantId.'/info/idDoc',
            $metadata,
            $document,
        );
    }

    /**
     * A signed multipart request.
     *
     * The signature covers "the request body exactly as sent", and for a
     * multipart request that is the assembled body including its boundaries —
     * a detail the prose documentation never states. It is how Sumsub's own
     * published PHP client does it: it hands the serialized MultipartStream to
     * the same signer as any other body. So the body is built here rather than
     * left to the HTTP client, because bytes that are signed and bytes that
     * are sent have to be the same bytes.
     *
     * UNVERIFIED AGAINST A LIVE ACCOUNT. Every other step here is quoted from
     * the reference documentation; this one is taken from the vendor's sample
     * code. Prove it against sandbox before trusting it in production — a
     * mismatch is a 401 on every upload, which is at least loud.
     *
     * @param array<string, string>                         $metadata
     * @param array{bytes: string, mime: string, size: int} $document
     */
    private function signedMultipart(string $path, array $metadata, array $document): Response
    {
        $boundary = 'dk'.bin2hex(random_bytes(16));
        $eol      = "\r\n";

        $name = 'document.'.match ($document['mime']) {
            'image/png'       => 'png',
            'application/pdf' => 'pdf',
            'image/webp'      => 'webp',
            default           => 'jpg',
        };

        $body = '--'.$boundary.$eol
            .'Content-Disposition: form-data; name="metadata"'.$eol.$eol
            .(json_encode($metadata) ?: '{}').$eol
            .'--'.$boundary.$eol
            .'Content-Disposition: form-data; name="content"; filename="'.$name.'"'.$eol
            .'Content-Type: '.$document['mime'].$eol.$eol
            .$document['bytes'].$eol
            .'--'.$boundary.'--'.$eol;

        $ts = (string) now()->timestamp;

        return Http::withHeaders([
            'X-App-Token'      => (string) $this->credential('app_token'),
            'X-App-Access-Ts'  => $ts,
            'X-App-Access-Sig' => hash_hmac('sha256', $ts.'POST'.$path.$body, (string) $this->credential('secret_key')),
            'Content-Type'     => 'multipart/form-data; boundary='.$boundary,
            // Tells us the image was unreadable now, rather than via a RED verdict later.
            'X-Return-Doc-Warnings' => 'true',
        ])->timeout(60)->withBody($body, 'multipart/form-data; boundary='.$boundary)
            ->post($this->baseUrl().$path);
    }

    private function signedRequest(string $method, string $path, string $body): Response
    {
        $ts        = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $ts.strtoupper($method).$path.$body, (string) $this->credential('secret_key'));

        return Http::withHeaders([
            'X-App-Token'      => (string) $this->credential('app_token'),
            'X-App-Access-Ts'  => $ts,
            'X-App-Access-Sig' => $signature,
            'Content-Type'     => 'application/json',
        ])->timeout(30)->send($method, $this->baseUrl().$path, $body === '' ? [] : ['body' => $body]);
    }

    private function signatureIsValid(Request $request): bool
    {
        $provided = (string) $request->header('x-payload-digest', '');
        $secret   = (string) $this->credential('webhook_secret', $this->credential('secret_key'));

        if ($provided === '' || $secret === '') {
            return false;
        }

        $algo     = (string) $request->header('x-payload-digest-alg', 'HMAC_SHA256_HEX');
        $hashAlgo = str_contains($algo, 'SHA1') ? 'sha1' : 'sha256';
        $expected = hash_hmac($hashAlgo, $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
