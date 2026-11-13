<?php

namespace App\Services;

use App\Enums\EnvironmentMode;
use App\Jobs\DeliverMerchantWebhook;
use App\Models\Merchant;
use App\Models\MerchantWebhookDelivery;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every webhook the platform sends a merchant goes through here: recorded
 * first, delivered by a queued job, retried on a schedule, and left with a
 * row that says what happened.
 *
 * The URL is the one the merchant gave with the payment (`ipn_url`), falling
 * back to the merchant's account-level `webhook_url`, which existed as a
 * column but was never read. Both go through PublicWebhookUrlGuard at send
 * time, so a hostname that has since started resolving to a private address
 * is refused on that attempt rather than trusted from the day it was given.
 *
 * The signature is computed on every attempt from the stored bytes and the
 * merchant's current secret, so a secret rotated mid-schedule is honoured.
 */
class MerchantWebhookDispatcher
{
    public function __construct(private readonly PublicWebhookUrlGuard $urlGuard) {}

    /**
     * Record a webhook and queue its first attempt. Returns null, with the
     * reason logged, when there is nowhere safe to send it.
     *
     * @param array<string, mixed> $payload
     */
    public function queue(
        Merchant $merchant,
        ?Transaction $transaction,
        string $event,
        array $payload,
        EnvironmentMode $environment,
        ?string $url = null,
    ): ?MerchantWebhookDelivery {
        $url = trim((string) ($url ?: data_get($transaction?->trx_data, 'ipn_url') ?: $merchant->webhook_url));

        if ($url === '' || ! $this->urlGuard->hasSafeShape($url)) {
            Log::warning('Merchant webhook skipped: no public HTTPS endpoint to send to.', [
                'merchant_id' => $merchant->id,
                'trx_id'      => $transaction?->trx_id,
                'event'       => $event,
            ]);

            return null;
        }

        if ($this->secretFor($merchant, $environment) === '') {
            Log::warning('Merchant webhook skipped: the merchant has no API secret for this environment.', [
                'merchant_id' => $merchant->id,
                'trx_id'      => $transaction?->trx_id,
                'environment' => $environment->value,
            ]);

            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            Log::error('Merchant webhook skipped: payload could not be encoded.', [
                'merchant_id' => $merchant->id,
                'trx_id'      => $transaction?->trx_id,
            ]);

            return null;
        }

        $delivery = MerchantWebhookDelivery::query()->create([
            'merchant_id'     => $merchant->id,
            'transaction_id'  => $transaction?->id,
            'trx_id'          => $transaction?->trx_id,
            'event'           => $event,
            'environment'     => $environment->value,
            'url'             => $url,
            'payload'         => $encoded,
            'status'          => MerchantWebhookDelivery::STATUS_PENDING,
            'attempts'        => 0,
            'next_attempt_at' => now(),
        ]);

        DeliverMerchantWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    /**
     * One attempt. True when the merchant answered 2xx; false, with the row
     * updated to say why, otherwise.
     */
    public function deliver(MerchantWebhookDelivery $delivery): bool
    {
        if ($delivery->isDelivered()) {
            return true;
        }

        $merchant    = $delivery->merchant;
        $environment = EnvironmentMode::tryFrom((string) $delivery->environment) ?? EnvironmentMode::PRODUCTION;
        $secret      = $merchant instanceof Merchant ? $this->secretFor($merchant, $environment) : '';

        if ($secret === '') {
            return $this->recordFailure($delivery, null, 'No API secret for this environment');
        }

        $endpoint = $this->urlGuard->resolve($delivery->url);

        if ($endpoint === null || ! defined('CURLOPT_RESOLVE')) {
            return $this->recordFailure($delivery, null, 'Endpoint did not resolve to a public HTTPS address');
        }

        $attempt = $delivery->attempts + 1;

        try {
            $response = Http::withHeaders([
                'X-Signature'   => hash_hmac('sha256', $delivery->payload, $secret),
                'X-Environment' => $environment->value,
                'X-Event'       => $delivery->event,
                'X-Delivery-Id' => (string) $delivery->id,
                'X-Attempt'     => (string) $attempt,
                'Content-Type'  => 'application/json',
            ])->withOptions([
                'allow_redirects' => false,
                'connect_timeout' => 5,
                'timeout'         => 10,
                'curl'            => [
                    constant('CURLOPT_RESOLVE') => [$this->urlGuard->curlResolveEntry($endpoint)],
                ],
            ])->withBody($delivery->payload, 'application/json')->post($delivery->url);
        } catch (Throwable $e) {
            return $this->recordFailure($delivery, null, $e->getMessage());
        }

        if (! $response->successful()) {
            return $this->recordFailure($delivery, $response->status(), 'HTTP '.$response->status());
        }

        $delivery->forceFill([
            'attempts'         => $attempt,
            'status'           => MerchantWebhookDelivery::STATUS_DELIVERED,
            'last_status_code' => $response->status(),
            'last_error'       => null,
            'next_attempt_at'  => null,
            'delivered_at'     => now(),
        ])->save();

        return true;
    }

    /**
     * Give up: the schedule is exhausted, or nothing about this row can ever
     * succeed. The row stays, which is the point.
     */
    public function abandon(MerchantWebhookDelivery $delivery, ?string $reason = null): void
    {
        $delivery->forceFill([
            'status'          => MerchantWebhookDelivery::STATUS_FAILED,
            'next_attempt_at' => null,
            'last_error'      => $reason !== null ? mb_substr($reason, 0, 500) : $delivery->last_error,
        ])->save();

        Log::error('Merchant webhook abandoned after its final attempt.', [
            'delivery_id' => $delivery->id,
            'merchant_id' => $delivery->merchant_id,
            'trx_id'      => $delivery->trx_id,
            'event'       => $delivery->event,
            'attempts'    => $delivery->attempts,
            'status_code' => $delivery->last_status_code,
        ]);
    }

    private function recordFailure(MerchantWebhookDelivery $delivery, ?int $statusCode, string $error): bool
    {
        $delivery->forceFill([
            'attempts'         => $delivery->attempts + 1,
            'last_status_code' => $statusCode,
            'last_error'       => mb_substr($error, 0, 500),
        ])->save();

        Log::warning('Merchant webhook attempt failed.', [
            'delivery_id' => $delivery->id,
            'merchant_id' => $delivery->merchant_id,
            'trx_id'      => $delivery->trx_id,
            'event'       => $delivery->event,
            'attempt'     => $delivery->attempts,
            'status_code' => $statusCode,
        ]);

        return false;
    }

    private function secretFor(Merchant $merchant, EnvironmentMode $environment): string
    {
        return $environment === EnvironmentMode::SANDBOX
            ? (string) ($merchant->test_api_secret ?? '')
            : (string) ($merchant->getRawOriginal('api_secret') ?: '');
    }
}
