<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers\BsiCards;

use App\Exceptions\NotifyErrorException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Models\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transport for the BSICARDS card-issuing API.
 *
 * One host serves sandbox and live alike; the environment is the key's, so
 * `pk_test_…`/`sk_test_…` keys reach the sandbox and any other key the live
 * program. Every request authenticates with the two lowercase headers
 * `publickey` and `secretkey` — there is no token exchange and no signing.
 *
 * Nothing here logs a request or response body: card responses carry the PAN
 * and CVV, card creation carries identity documents, and the headers carry
 * the secret key. Logs name the endpoint and the HTTP status only.
 *
 * Reference: https://cards.bsigroup.tech/api-documentation
 */
final class BsiCardsClient
{
    public const GATEWAY_CODE = 'bsicards';

    public const BASE_URL = 'https://cards.bsigroup.tech/api/';

    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        private readonly string $publicKey,
        private readonly string $secretKey,
    ) {}

    /**
     * Build the client from the `bsicards` payment-gateway credentials.
     *
     * A refusal, not a failure: nothing has been sent, so a top-up attempted
     * without keys is refunded rather than parked for review.
     *
     * @throws NotifyErrorException when either key has not been entered
     */
    public static function fromGateway(): self
    {
        $credentials = PaymentGateway::getCredentials(self::GATEWAY_CODE);
        $publicKey   = $credentials['public_key'] ?? null;
        $secretKey   = $credentials['secret_key'] ?? null;

        if (! PaymentGateway::credentialIsFilled('public_key', $publicKey)
            || ! PaymentGateway::credentialIsFilled('secret_key', $secretKey)) {
            throw new NotifyErrorException(__('BSICARDS API credentials are not configured.'));
        }

        return new self(trim((string) $publicKey), trim((string) $secretKey));
    }

    /**
     * `sandbox` for BSICARDS test keys, `live` for every other key.
     */
    public function mode(): string
    {
        return str_starts_with(strtolower($this->publicKey), 'pk_test_') ? 'sandbox' : 'live';
    }

    /**
     * Whether a value presented by a caller is this account's secret key.
     *
     * BSICARDS webhooks carry no signature; they echo the secret key in a
     * header, so this constant-time comparison is the whole of their
     * authentication.
     */
    public function secretKeyMatches(?string $candidate): bool
    {
        $candidate = trim((string) $candidate);

        return $candidate !== '' && hash_equals($this->secretKey, $candidate);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws ProviderOutcomeUnknownException when the request never completed
     */
    public function postJson(string $path, array $body, int $timeout = 30): BsiCardsResponse
    {
        return $this->send($path, $timeout, fn (PendingRequest $http): Response => $http->asJson()->post($this->url($path), $body));
    }

    /**
     * A multipart POST, for the endpoints that take identity documents.
     *
     * @param array<string, scalar|null>                               $fields
     * @param array<string, array{contents: string, filename: string}> $files
     *
     * @throws ProviderOutcomeUnknownException when the request never completed
     */
    public function postMultipart(string $path, array $fields, array $files, int $timeout = 60): BsiCardsResponse
    {
        return $this->send($path, $timeout, function (PendingRequest $http) use ($path, $fields, $files): Response {
            $http = $http->asMultipart();

            foreach ($files as $name => $file) {
                $http = $http->attach($name, $file['contents'], $file['filename']);
            }

            return $http->post($this->url($path), array_map(static fn (mixed $value): string => (string) $value, $fields));
        });
    }

    /**
     * @param array<string, scalar> $query
     *
     * @throws ProviderOutcomeUnknownException when the request never completed
     */
    public function get(string $path, array $query = [], int $timeout = 15): BsiCardsResponse
    {
        return $this->send($path, $timeout, fn (PendingRequest $http): Response => $http->get($this->url($path), $query));
    }

    /**
     * @param callable(PendingRequest): Response $call
     *
     * @throws ProviderOutcomeUnknownException
     */
    private function send(string $path, int $timeout, callable $call): BsiCardsResponse
    {
        $http = Http::withHeaders([
            'publickey' => $this->publicKey,
            'secretkey' => $this->secretKey,
        ])
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            Log::warning('BSICARDS request did not complete', ['endpoint' => $path]);

            throw new ProviderOutcomeUnknownException(__('BSICARDS could not be reached for :endpoint.', ['endpoint' => $path]), 0, $e);
        }

        $answer = BsiCardsResponse::fromRaw($path, $response->status(), $response->body());

        if (! $answer->isSuccessful()) {
            Log::warning('BSICARDS answered without success', [
                'endpoint' => $path,
                'status'   => $answer->httpStatus,
                'code'     => $answer->bodyCode(),
            ]);
        }

        return $answer;
    }

    private function url(string $path): string
    {
        return self::BASE_URL.ltrim($path, '/');
    }
}
