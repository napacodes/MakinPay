<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Reloadly;

use App\Exceptions\ProviderOutcomeUnknownException;
use App\Services\BillPayment\Drivers\Support\ProviderJson;
use App\Services\BillPayment\Drivers\Support\WireAmount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP side of Reloadly Utility Payments, and nothing else.
 *
 * Every call answers [HTTP status, decoded JSON or null]. A connection that
 * fails on any call is raised as ProviderOutcomeUnknownException, because
 * once a request may have left, nobody can say it was not processed. Read
 * calls are retried once on a dropped connection or a 5xx; a pay POST is
 * never retried, except for one resend after Reloadly rejects the token,
 * which it does before looking at the payment.
 *
 * The access token is cached per provider, per mode and per credential pair,
 * with the audience inside the hash, so a token the mobile recharge driver
 * cached for the topups audience can never be sent here.
 *
 * @link https://docs.reloadly.com/assets/api/utility-payments.json
 */
final class ReloadlyUtilitiesClient
{
    public const AUTH_URL = 'https://auth.reloadly.com/oauth/token';

    public const SANDBOX = 'https://utilities-sandbox.reloadly.com';

    public const LIVE = 'https://utilities.reloadly.com';

    public const ACCEPT = 'application/com.reloadly.utilities-v1+json';

    public const TOKEN_CACHE_PREFIX = 'bill_payment_reloadly_token:';

    public const PAY_TIMEOUT_DEFAULT = 15;

    public const PAY_TIMEOUT_MIN = 5;

    public const PAY_TIMEOUT_MAX = 20;

    public const STATUS_TIMEOUT = 10;

    public const CATALOGUE_TIMEOUT = 30;

    public const TOKEN_TIMEOUT = 8;

    public const CONNECT_TIMEOUT = 5;

    /** The longest a token is kept, in seconds, whatever Reloadly says. */
    public const TOKEN_TTL_MAX = 3000;

    /** Error codes that mean the request was refused for its token alone. */
    private const TOKEN_ERROR_CODES = ['INVALID_TOKEN', 'TOKEN_EXPIRED'];

    public function __construct(
        private readonly int $providerId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $sandbox,
        private readonly int $payTimeout = self::PAY_TIMEOUT_DEFAULT,
    ) {}

    /**
     * A client for one provider's credentials. The pay timeout comes from the
     * `timeout` credential, clamped so pay() always ends inside the
     * customer's request.
     *
     * @param array<string, mixed> $credentials
     */
    public static function fromCredentials(int $providerId, array $credentials, bool $sandbox): self
    {
        $timeout = $credentials['timeout'] ?? null;
        $timeout = is_numeric($timeout) ? (int) $timeout : self::PAY_TIMEOUT_DEFAULT;

        return new self(
            $providerId,
            trim((string) ($credentials['client_id'] ?? '')),
            trim((string) ($credentials['client_secret'] ?? '')),
            $sandbox,
            max(self::PAY_TIMEOUT_MIN, min(self::PAY_TIMEOUT_MAX, $timeout)),
        );
    }

    /**
     * Where a token for these credentials is cached. The audience is part of
     * the hash, and the secret is never in the key itself.
     */
    public static function tokenCacheKey(int $providerId, bool $sandbox, string $clientId, string $clientSecret): string
    {
        $audience = $sandbox ? self::SANDBOX : self::LIVE;

        return self::TOKEN_CACHE_PREFIX.$providerId.':'.($sandbox ? 'sandbox' : 'live').':'
            .substr(hash('sha256', $audience.'|'.$clientId.'|'.$clientSecret), 0, 16);
    }

    /** The utilities host for this mode; it is also the token audience. */
    public function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX : self::LIVE;
    }

    public function mode(): string
    {
        return $this->sandbox ? 'sandbox' : 'live';
    }

    public function payTimeout(): int
    {
        return $this->payTimeout;
    }

    /**
     * A bearer token for the utilities audience, from the cache unless
     * $fresh asks for a new one.
     *
     * @throws ReloadlyTokenException
     */
    public function token(bool $fresh = false): string
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new ReloadlyTokenException('Reloadly credentials are not configured.');
        }

        $key = $this->cacheKey();

        if (! $fresh) {
            $cached = Cache::get($key);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TOKEN_TIMEOUT)
                ->post(self::AUTH_URL, [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type'    => 'client_credentials',
                    'audience'      => $this->baseUrl(),
                ]);
        } catch (ConnectionException) {
            throw new ReloadlyTokenException('The Reloadly auth host could not be reached.');
        }

        if (! $response->successful()) {
            throw new ReloadlyTokenException('Reloadly refused the credentials (HTTP '.$response->status().').');
        }

        $json  = ProviderJson::decode($response) ?? [];
        $token = $json['access_token']           ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw new ReloadlyTokenException('Reloadly returned no access token.');
        }

        $expiresIn = is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : 0;
        $ttl       = min($expiresIn - 300, self::TOKEN_TTL_MAX);

        if ($ttl > 0) {
            Cache::put($key, $token, $ttl);
        } else {
            Cache::forget($key);
        }

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * POST /pay. Never retried, except once after a token rejection.
     *
     * @param  array<string, mixed>                           $body
     * @return array{0: int, 1: array<array-key, mixed>|null}
     *
     * @throws ReloadlyTokenException          when no token can be had (before /pay, or for the one resend)
     * @throws ProviderOutcomeUnknownException when the connection failed after the request may have left
     */
    public function pay(array $body): array
    {
        return $this->send('POST', '/pay', $body, $this->payTimeout, false);
    }

    /**
     * GET /transactions/{id}.
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function transaction(string $id): array
    {
        return $this->send('GET', '/transactions/'.rawurlencode($id), [], self::STATUS_TIMEOUT, true);
    }

    /**
     * GET /transactions?referenceId=... (the newest few).
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function transactionsByReference(string $referenceId): array
    {
        return $this->send('GET', '/transactions', ['referenceId' => $referenceId, 'size' => 5], self::STATUS_TIMEOUT, true);
    }

    /**
     * GET /billers with the given filters.
     *
     * @param  array<string, scalar>                          $query
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function billers(array $query): array
    {
        return $this->send('GET', '/billers', $query, self::CATALOGUE_TIMEOUT, true);
    }

    /**
     * GET /accounts/balance.
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function balance(): array
    {
        return $this->send('GET', '/accounts/balance', [], self::STATUS_TIMEOUT, true);
    }

    private function cacheKey(): string
    {
        return self::tokenCacheKey($this->providerId, $this->sandbox, $this->clientId, $this->clientSecret);
    }

    /**
     * @param  array<string, mixed>                           $data
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    private function send(string $method, string $path, array $data, int $timeout, bool $isRead): array
    {
        $response = $this->attempt($method, $path, $data, $timeout, $isRead, $this->token());

        if ($response->status() === 401 && $this->isTokenRejection($response)) {
            $this->forgetToken();

            $response = $this->attempt($method, $path, $data, $timeout, $isRead, $this->token(true));
        }

        return [$response->status(), ProviderJson::decode($response)];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function attempt(string $method, string $path, array $data, int $timeout, bool $isRead, string $token): Response
    {
        try {
            $request = $this->request($timeout, $token, $isRead);

            return $method === 'GET'
                ? $request->get($this->baseUrl().$path, $data)
                : $request->withBody(WireAmount::json($data), 'application/json')->post($this->baseUrl().$path);
        } catch (ConnectionException) {
            throw new ProviderOutcomeUnknownException('Reloadly did not answer '.$method.' '.$path.'.');
        }
    }

    private function request(int $timeout, string $token, bool $isRead): PendingRequest
    {
        $request = Http::withToken($token)
            ->accept(self::ACCEPT)
            ->asJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout);

        if ($isRead) {
            $request->retry(2, 300, static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
        }

        return $request;
    }

    private function isTokenRejection(Response $response): bool
    {
        $code = (ProviderJson::decode($response) ?? [])['errorCode'] ?? null;

        return is_string($code) && in_array(strtoupper(trim($code)), self::TOKEN_ERROR_CODES, true);
    }
}
