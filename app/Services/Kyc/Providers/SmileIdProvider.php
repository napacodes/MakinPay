<?php

namespace App\Services\Kyc\Providers;

use App\Enums\Kyc\KycVerdict;
use App\Models\KycSubmission;
use App\Services\Kyc\KycVerificationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * Smile ID — Africa-first identity verification (Document Verification + KYC).
 * Uses partner_id + api_key with an HMAC signature over the request timestamp.
 *
 * @see https://docs.usesmileid.com
 */
class SmileIdProvider extends AbstractKycProvider
{
    /**
     * Smile ID result codes that mean the identity was confirmed.
     *
     * @var array<int, string>
     */
    private const APPROVED_CODES = ['1012', '1020', '0810'];

    /**
     * Result codes that mean a hard rejection.
     *
     * @var array<int, string>
     */
    private const REJECTED_CODES = ['0810', '1013', '1014', '0911'];

    /** JobType::DOCUMENT_VERIFICATION in Smile ID's own SDK. */
    private const JOB_TYPE_DOCUMENT_VERIFICATION = 6;

    /**
     * ImageType::ID_CARD_IMAGE_STRING and ::SELFIE_IMAGE_STRING. The value
     * says both which image this is and that it arrived base64 rather than as
     * a file inside the archive.
     */
    private const IMAGE_ID_CARD_BASE64 = 3;

    private const IMAGE_SELFIE_BASE64 = 2;

    /** The SDK names the archive this, and info.json repeats the name inside it. */
    private const ARCHIVE_NAME = 'selfie.zip';

    private function baseUrl(): string
    {
        return $this->config->isSandbox()
            ? 'https://testapi.smileidentity.com/v1/'
            : 'https://api.smileidentity.com/v1/';
    }

    public function verify(KycSubmission $submission): KycVerificationResult
    {
        if (! $this->hasCredentials(['partner_id', 'api_key'])) {
            return KycVerificationResult::review(__('Smile ID credentials are not configured.'));
        }

        $reference = $this->reference($submission);

        /*
         * This called `async_id_verification` with job_type 6, which was wrong
         * twice over and in neither case for the reason you would guess.
         *
         * That endpoint is the ID API: an ID-NUMBER lookup against an
         * authority database. Smile ID's own PHP SDK validates it as
         * accepting job_type 5 (ENHANCED_KYC) and only that, and it transmits
         * no images at all — it merges an id_info array of id_type, id_number
         * and name. We sent it job_type 6 and never sent an ID number, so it
         * could not have verified anything even on its own terms.
         *
         * Document verification (job_type 6) is a different product with a
         * different shape: ask for an upload slot, then PUT a zip containing
         * an info.json to the pre-signed S3 url it hands back. The verdict
         * arrives later on the callback, which is why nothing about the old
         * call looked broken from here.
         */
        $documents  = $this->documents($submission);
        $idDocument = $this->readDocument($documents['id_document']);
        $selfie     = $this->readDocument($documents['selfie']);

        if ($idDocument === null || $selfie === null) {
            /*
             * Both, not either. Smile ID: "Selfie images are required for all
             * job types unless the user has previously been enrolled with a
             * selfie image and use_enrolled_image is set to true." We do not
             * enrol, so a submission without both is a job that would be
             * refused after the documents had already been transmitted.
             */
            return KycVerificationResult::review(
                __('Smile ID needs both an identity document and a selfie; routed to manual review.'),
                50,
                $reference
            );
        }

        $timestamp     = now()->toIso8601String();
        $signature     = $this->signature($timestamp);
        $partnerParams = [
            'job_id'   => $reference,
            'user_id'  => (string) $submission->user_id,
            'job_type' => self::JOB_TYPE_DOCUMENT_VERIFICATION,
            // handleWebhook() reads this back to find the submission again.
            'reference' => $reference,
        ];

        try {
            // 1. Ask for somewhere to put it.
            $prep = Http::acceptJson()
                ->timeout(30)
                ->post($this->baseUrl().'upload', [
                    'signature'          => $signature,
                    'timestamp'          => $timestamp,
                    'callback_url'       => route('webhooks.kyc', ['provider' => $this->config->code]),
                    'file_name'          => self::ARCHIVE_NAME,
                    'model_parameters'   => '',
                    'partner_params'     => $partnerParams,
                    'smile_client_id'    => $this->credential('partner_id'),
                    'source_sdk'         => 'digikash-rest',
                    'source_sdk_version' => '1.0.0',
                ]);

            $uploadUrl = (string) $prep->json('upload_url', '');

            if (! $prep->successful() || $uploadUrl === '') {
                Log::warning('Smile ID prep upload gave no upload_url: HTTP '.$prep->status());

                return KycVerificationResult::review(__('Smile ID rejected the request.'), 55, $reference);
            }

            // 2. Send the documents.
            $sent = $this->putArchive($uploadUrl, $this->infoJson(
                $prep->json() ?? [],
                $partnerParams,
                $timestamp,
                $signature,
                $submission,
                $idDocument,
                $selfie,
            ));

            if (! $sent) {
                return KycVerificationResult::review(__('Smile ID would not accept the documents.'), 55, $reference);
            }

            // The verdict arrives on the callback, keyed by this reference.
            return KycVerificationResult::pending($reference, ['provider' => $this->config->code]);
        } catch (Throwable $e) {
            Log::warning('Smile ID verify failed: '.$e->getMessage());

            return KycVerificationResult::review(__('Provider unavailable; routed to manual review.'), 50, $reference);
        }
    }

    public function handleWebhook(Request $request): KycVerificationResult
    {
        if (! $this->signatureIsValid($request)) {
            return KycVerificationResult::review(__('Invalid Smile ID signature.'));
        }

        $reference = $request->input('PartnerParams.reference') ?? $request->input('job_id');

        return $this->mapResultCode(
            (string) $request->input('ResultCode', ''),
            $reference,
            $request->all()
        );
    }

    public function checkStatus(KycSubmission $submission): KycVerificationResult
    {
        if (! $this->hasCredentials(['partner_id', 'api_key']) || blank($submission->provider_reference)) {
            return KycVerificationResult::review();
        }

        $timestamp = now()->toIso8601String();

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->post($this->baseUrl().'job_status', [
                    'partner_id' => $this->credential('partner_id'),
                    'signature'  => $this->signature($timestamp),
                    'timestamp'  => $timestamp,
                    'job_id'     => $submission->provider_reference,
                    'user_id'    => (string) $submission->user_id,
                ]);

            return $this->mapResultCode((string) $response->json('result.ResultCode', ''), $submission->provider_reference, $response->json() ?? []);
        } catch (Throwable $e) {
            Log::warning('Smile ID status check failed: '.$e->getMessage());

            return KycVerificationResult::review();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapResultCode(string $code, ?string $reference, array $payload): KycVerificationResult
    {
        $payload['provider'] = $this->config->code;

        if (in_array($code, self::APPROVED_CODES, true)) {
            return new KycVerificationResult(KycVerdict::Clear, 8, $reference, null, [], $payload);
        }

        if (in_array($code, self::REJECTED_CODES, true)) {
            return new KycVerificationResult(KycVerdict::Rejected, 88, $reference, $payload['ResultText'] ?? __('Verification failed.'), [], $payload);
        }

        if ($code === '') {
            return KycVerificationResult::pending($reference, $payload);
        }

        return KycVerificationResult::review($payload['ResultText'] ?? null, 55, $reference);
    }

    /**
     * The info.json that goes inside the archive.
     *
     * Shape and key names taken from Smile ID's own PHP SDK
     * (SmileIdentityCore::configure_info_json), which is executable evidence
     * rather than a docs example: `server_information` is the prep-upload
     * response handed straight back, and the signature travels again inside
     * `misc_information`.
     *
     * @param  array<string, mixed>                          $server
     * @param  array<string, mixed>                          $partnerParams
     * @param  array{bytes: string, mime: string, size: int} $idDocument
     * @param  array{bytes: string, mime: string, size: int} $selfie
     * @return array<string, mixed>
     */
    private function infoJson(
        array $server,
        array $partnerParams,
        string $timestamp,
        string $signature,
        KycSubmission $submission,
        array $idDocument,
        array $selfie,
    ): array {
        /*
         * Base64 inline rather than files in the zip. The SDK supports both
         * and picks by whether the value looks like a filename; sending the
         * bytes inline means the applicant's passport is never written to
         * this server's temp directory as a loose image.
         *
         * The image_type_id encodes BOTH which image it is and which of the
         * two transports it arrived by — the string variants are the odd
         * numbers here, from the SDK's own ImageType constants.
         */
        $images = [
            ['image_type_id' => self::IMAGE_ID_CARD_BASE64, 'image' => base64_encode($idDocument['bytes']), 'file_name' => ''],
            ['image_type_id' => self::IMAGE_SELFIE_BASE64, 'image' => base64_encode($selfie['bytes']), 'file_name' => ''],
        ];

        return [
            'package_information' => [
                'apiVersion' => ['buildNumber' => 0, 'majorVersion' => 2, 'minorVersion' => 0],
                'language'   => 'php',
            ],
            'misc_information' => [
                'retry'           => 'false',
                'partner_params'  => $partnerParams,
                'file_name'       => self::ARCHIVE_NAME,
                'smile_client_id' => $this->credential('partner_id'),
                'callback_url'    => route('webhooks.kyc', ['provider' => $this->config->code]),
                'signature'       => $signature,
                'timestamp'       => $timestamp,
            ],
            /*
             * Country lets Smile ID pick the right document model. id_type is
             * left out on purpose: it is optional, and a wrong one is refused
             * outright, so classification by Smile ID beats a guess by us.
             */
            'id_info'            => array_filter(['country' => $submission->user?->country]),
            'images'             => $images,
            'server_information' => $server,
        ];
    }

    /**
     * Zip the info.json and PUT it to the pre-signed url.
     *
     * The url carries its own authorisation in the query string and expires,
     * so it is used exactly as handed over and never logged. The archive is
     * deleted whatever happens: it holds an identity document, and a crash
     * is not a reason to leave one in the temp directory.
     *
     * @param array<string, mixed> $infoJson
     */
    private function putArchive(string $uploadUrl, array $infoJson): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'dk-kyc-');

        if ($file === false) {
            return false;
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
                return false;
            }

            $zip->addFromString('info.json', json_encode($infoJson) ?: '{}');
            $zip->close();

            return Http::withBody(
                (string) file_get_contents($file),
                'application/zip'
            )->timeout(120)->put($uploadUrl)->successful();
        } finally {
            @unlink($file);
        }
    }

    private function signature(string $timestamp): string
    {
        return base64_encode(hash_hmac('sha256', $timestamp.(string) $this->credential('partner_id').'sid_request', (string) $this->credential('api_key'), true));
    }

    private function signatureIsValid(Request $request): bool
    {
        $timestamp = (string) $request->input('timestamp', '');
        $provided  = (string) $request->input('signature', '');

        if ($timestamp === '' || $provided === '') {
            return false;
        }

        return hash_equals($this->signature($timestamp), $provided);
    }
}
