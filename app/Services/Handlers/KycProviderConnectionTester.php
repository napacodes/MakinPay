<?php

namespace App\Services\Handlers;

use App\Models\KycProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class KycProviderConnectionTester
{
    /**
     * @param  array<string, mixed>                                        $credentials
     * @return array{status: 'success'|'warning'|'error', message: string}
     */
    public function test(string $driver, array $credentials, string $mode, ?KycProvider $provider = null): array
    {
        $credentials = $this->resolveCredentials($credentials, $provider);
        $missing     = $this->missingCredentials($driver, $credentials);

        if ($missing !== []) {
            return [
                'status'  => 'error',
                'message' => __('Add the required credentials first: :fields', [
                    'fields' => implode(', ', $missing),
                ]),
            ];
        }

        return match ($driver) {
            'smile_id' => $this->testSmileId($credentials, $mode),
            'shufti'   => $this->testShuftiPro($credentials, $mode),
            'sumsub'   => $this->testSumsub($credentials, $mode),
            default    => [
                'status'  => 'warning',
                'message' => __('Automated connection testing is not available for this provider.'),
            ],
        };
    }

    /**
     * @param  array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function resolveCredentials(array $credentials, ?KycProvider $provider): array
    {
        $saved = $provider?->credentials ?? [];

        return array_replace($saved, $credentials);
    }

    /**
     * @param  array<string, mixed> $credentials
     * @return array<int, string>
     */
    private function missingCredentials(string $driver, array $credentials): array
    {
        $required = match ($driver) {
            'smile_id' => ['partner_id' => __('Partner ID'), 'api_key' => __('API Key')],
            'shufti'   => ['client_id' => __('Client ID'), 'secret_key' => __('Secret Key')],
            'sumsub'   => ['app_token' => __('App Token'), 'secret_key' => __('Secret Key')],
            default    => [],
        };

        return collect($required)
            ->filter(fn (string $label, string $key): bool => blank($credentials[$key] ?? null))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $credentials */
    private function testSmileId(array $credentials, string $mode): array
    {
        $timestamp = now()->toIso8601String();
        $signature = base64_encode(hash_hmac(
            'sha256',
            $timestamp.(string) $credentials['partner_id'].'sid_request',
            (string) $credentials['api_key'],
            true
        ));
        $baseUrl = $mode === 'sandbox'
            ? 'https://testapi.smileidentity.com/v1/'
            : 'https://api.smileidentity.com/v1/';

        $response = Http::acceptJson()
            ->timeout(15)
            ->connectTimeout(10)
            ->post($baseUrl.'job_status', [
                'partner_id' => $credentials['partner_id'],
                'signature'  => $signature,
                'timestamp'  => $timestamp,
                'job_id'     => 'DK-KYC-CONNECTION-CHECK',
                'user_id'    => 'connection-check',
            ]);

        return $this->responseResult($response, 'Smile ID', $mode, [400, 404, 422]);
    }

    /** @param array<string, mixed> $credentials */
    private function testShuftiPro(array $credentials, string $mode): array
    {
        $response = Http::withBasicAuth(
            (string) $credentials['client_id'],
            (string) $credentials['secret_key']
        )->acceptJson()
            ->timeout(15)
            ->connectTimeout(10)
            ->post('https://api.shuftipro.com/status', [
                'reference' => 'DK-KYC-CONNECTION-CHECK',
            ]);

        return $this->responseResult($response, 'ShuftiPro', $mode, [400, 404, 422]);
    }

    /** @param array<string, mixed> $credentials */
    private function testSumsub(array $credentials, string $mode): array
    {
        $path      = '/resources/applicants?limit=1';
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac(
            'sha256',
            $timestamp.'GET'.$path,
            (string) $credentials['secret_key']
        );

        $response = Http::withHeaders([
            'X-App-Token'      => (string) $credentials['app_token'],
            'X-App-Access-Ts'  => $timestamp,
            'X-App-Access-Sig' => $signature,
        ])->acceptJson()
            ->timeout(15)
            ->connectTimeout(10)
            ->get('https://api.sumsub.com'.$path);

        return $this->responseResult($response, 'Sumsub', $mode);
    }

    /**
     * @param  array<int, int>                                             $acceptedProbeStatuses
     * @return array{status: 'success'|'warning'|'error', message: string}
     */
    private function responseResult(Response $response, string $provider, string $mode, array $acceptedProbeStatuses = []): array
    {
        if ($response->successful() || in_array($response->status(), $acceptedProbeStatuses, true)) {
            return [
                'status'  => 'success',
                'message' => __(':provider :mode connection passed. The API accepted these credentials.', [
                    'provider' => $provider,
                    'mode'     => $mode,
                ]),
            ];
        }

        if ($response->status() === 429 || $response->serverError()) {
            return [
                'status'  => 'warning',
                'message' => __(':provider received the request but is temporarily unavailable. Try again shortly.', [
                    'provider' => $provider,
                ]),
            ];
        }

        return [
            'status'  => 'error',
            'message' => __(':provider rejected the :mode credentials (:status): :message', [
                'provider' => $provider,
                'mode'     => $mode,
                'status'   => $response->status(),
                'message'  => $this->responseMessage($response),
            ]),
        ];
    }

    private function responseMessage(Response $response): string
    {
        $message = $response->json('message')
            ?? $response->json('error.message')
            ?? $response->json('error')
            ?? $response->json('description');

        if (is_scalar($message) && trim((string) $message) !== '') {
            return Str::limit(trim((string) $message), 240);
        }

        $body = trim(strip_tags($response->body()));

        return $body !== ''
            ? Str::limit($body, 240)
            : __('The provider did not return an error message.');
    }
}
