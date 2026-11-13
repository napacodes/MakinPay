<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Flutterwave;

use App\Exceptions\ProviderOutcomeUnknownException;
use App\Services\BillPayment\Drivers\Support\ProviderJson;
use App\Services\BillPayment\Drivers\Support\WireAmount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP side of Flutterwave v3 bill payments, and nothing else.
 *
 * Every call answers [HTTP status, decoded JSON or null]. A connection that
 * fails on any call is raised as ProviderOutcomeUnknownException, because
 * once a request may have left, nobody can say it was not processed. Read
 * calls are retried once on a dropped connection or a 5xx; the pay POST is
 * never retried.
 *
 * One host serves test and live keys alike; the key itself decides the mode.
 * v4 has no bills endpoints, so this stays on v3.
 *
 * @link https://developer.flutterwave.com/docs/bill-payment
 */
final class FlutterwaveBillsClient
{
    public const BASE_URL = 'https://api.flutterwave.com/v3';

    public const TEST_KEY_PREFIX = 'FLWSECK_TEST';

    public const PAY_TIMEOUT_DEFAULT = 15;

    public const PAY_TIMEOUT_MIN = 5;

    public const PAY_TIMEOUT_MAX = 20;

    public const STATUS_TIMEOUT = 10;

    public const LOOKUP_TIMEOUT = 8;

    public const CATALOGUE_TIMEOUT = 30;

    public const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly string $apiSecret,
        private readonly int $payTimeout = self::PAY_TIMEOUT_DEFAULT,
    ) {}

    /**
     * A client for one provider's credentials. The pay timeout comes from the
     * `timeout` credential, clamped so pay() always ends inside the
     * customer's request.
     *
     * @param array<string, mixed> $credentials
     */
    public static function fromCredentials(array $credentials): self
    {
        $timeout = $credentials['timeout'] ?? null;
        $timeout = is_numeric($timeout) ? (int) $timeout : self::PAY_TIMEOUT_DEFAULT;

        return new self(
            trim((string) ($credentials['api_secret'] ?? '')),
            max(self::PAY_TIMEOUT_MIN, min(self::PAY_TIMEOUT_MAX, $timeout)),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiSecret !== '';
    }

    /** A FLWSECK_TEST… key is a sandbox key; anything else is live. */
    public function isSandbox(): bool
    {
        return str_starts_with($this->apiSecret, self::TEST_KEY_PREFIX);
    }

    public function mode(): string
    {
        return $this->isSandbox() ? 'sandbox' : 'live';
    }

    public function payTimeout(): int
    {
        return $this->payTimeout;
    }

    /**
     * POST /billers/{biller}/items/{item}/payment. Never retried.
     *
     * @param  array<string, mixed>                           $body
     * @return array{0: int, 1: array<array-key, mixed>|null}
     *
     * @throws ProviderOutcomeUnknownException when the connection failed after the request may have left
     */
    public function pay(string $billerCode, string $itemCode, array $body): array
    {
        return $this->send('POST', '/billers/'.rawurlencode($billerCode).'/items/'.rawurlencode($itemCode).'/payment', $body, $this->payTimeout, false);
    }

    /**
     * GET /bills/{tx_ref}?verbose=1.
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function bill(string $transactionReference): array
    {
        return $this->send('GET', '/bills/'.rawurlencode($transactionReference), ['verbose' => 1], self::STATUS_TIMEOUT, true);
    }

    /**
     * GET /bills/history with the given filters.
     *
     * @param  array<string, scalar>                          $query
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function history(array $query): array
    {
        return $this->send('GET', '/bills/history', $query, self::STATUS_TIMEOUT, true);
    }

    /**
     * GET /bill-items/{item}/validate?customer=...
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function validate(string $itemCode, string $customer): array
    {
        return $this->send('GET', '/bill-items/'.rawurlencode($itemCode).'/validate', ['customer' => $customer], self::LOOKUP_TIMEOUT, true);
    }

    /**
     * GET /top-bill-categories?country=...
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function categories(string $country): array
    {
        return $this->send('GET', '/top-bill-categories', ['country' => $country], self::CATALOGUE_TIMEOUT, true);
    }

    /**
     * GET /bills/{category}/billers?country=...
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function billers(string $category, string $country): array
    {
        return $this->send('GET', '/bills/'.rawurlencode($category).'/billers', ['country' => $country], self::CATALOGUE_TIMEOUT, true);
    }

    /**
     * GET /billers/{biller_code}/items.
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    public function items(string $billerCode): array
    {
        return $this->send('GET', '/billers/'.rawurlencode($billerCode).'/items', [], self::CATALOGUE_TIMEOUT, true);
    }

    /**
     * @param  array<string, mixed>                           $data
     * @return array{0: int, 1: array<array-key, mixed>|null}
     */
    private function send(string $method, string $path, array $data, int $timeout, bool $isRead): array
    {
        try {
            $request  = $this->request($timeout, $isRead);
            $response = $method === 'GET'
                ? $request->get(self::BASE_URL.$path, $data)
                : $request->withBody(WireAmount::json($data), 'application/json')->post(self::BASE_URL.$path);
        } catch (ConnectionException) {
            throw new ProviderOutcomeUnknownException('Flutterwave did not answer '.$method.' '.$path.'.');
        }

        return [$response->status(), ProviderJson::decode($response)];
    }

    private function request(int $timeout, bool $isRead): PendingRequest
    {
        $request = Http::withToken($this->apiSecret)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout);

        if ($isRead) {
            $request->retry(2, 300, static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
        }

        return $request;
    }
}
