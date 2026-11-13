<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillPaymentOutcome;
use App\Enums\BillPayment\ProviderFault;

/**
 * What a fulfiller said about one payment.
 *
 * `amount` and `currency` are what the provider says it delivered; the
 * service settles only when they match the payment. `token` is the only place
 * a token travels: it is written to the encrypted column on the payment and
 * nowhere else.
 *
 * `payload` is the provider reply kept for the audit trail, so it is
 * sanitised on the way in: keys that name a secret are dropped at any depth,
 * and any string that contains the token is redacted. A driver should still
 * pass only what support needs to read.
 */
readonly class BillPaymentResult
{
    public const REDACTED = '[redacted]';

    /**
     * Payload keys dropped wherever they appear, compared lower-cased with
     * everything but letters and digits removed, so `api_key`, `Api-Key` and
     * `apiKey` are one entry.
     */
    private const SENSITIVE_KEYS = [
        'token',
        'pin',
        'password',
        'secret',
        'clientsecret',
        'apikey',
        'xapikey',
        'accesstoken',
        'refreshtoken',
        'authorization',
        'secretkey',
        'secrethash',
        'apisecret',
        'webhooksecret',
        'verifhash',
        'rechargetoken',
        'pindetails',
    ];

    /** @var array<array-key, mixed> */
    public array $payload;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public BillPaymentOutcome $outcome,
        public ?string $providerTransactionId = null,
        public ?string $receiptReference = null,
        public ?string $token = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $message = null,
        array $payload = [],
        public ?ProviderFault $providerFault = null,
    ) {
        $this->payload = self::sanitise($payload, $token);
    }

    /**
     * The biller has been paid.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function completed(
        ?string $providerTransactionId = null,
        ?string $receiptReference = null,
        ?string $token = null,
        ?string $amount = null,
        ?string $currency = null,
        ?string $message = null,
        array $payload = [],
    ): self {
        return new self(
            BillPaymentOutcome::Completed,
            $providerTransactionId,
            $receiptReference,
            $token,
            $amount,
            $currency,
            $message,
            $payload,
        );
    }

    /**
     * Certain that nothing was charged. The customer is refunded.
     *
     * A refusal that is the provider account's own fault - its balance, its
     * credentials - names it in `$fault`, which pauses the provider (BP1).
     *
     * @param array<array-key, mixed> $payload
     */
    public static function failed(string $message, array $payload = [], ?ProviderFault $fault = null): self
    {
        return new self(BillPaymentOutcome::Failed, message: $message, payload: $payload, providerFault: $fault);
    }

    /**
     * Accepted, with the result still to come from an operator, a status
     * check or a webhook.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function pending(?string $message = null, ?string $providerTransactionId = null, array $payload = []): self
    {
        return new self(
            BillPaymentOutcome::Pending,
            providerTransactionId: $providerTransactionId,
            message: $message,
            payload: $payload,
        );
    }

    /**
     * No clear answer: the biller may or may not have been paid. Never
     * refunded automatically. A provider that answered with its own id for
     * the payment passes it, so the payment is bound to it even though the
     * outcome is unclear.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function unknown(string $message, array $payload = [], ?string $providerTransactionId = null): self
    {
        return new self(
            BillPaymentOutcome::Unknown,
            providerTransactionId: $providerTransactionId,
            message: $message,
            payload: $payload,
        );
    }

    /**
     * A payload with every secret-named key dropped at any depth, for audit
     * detail that does not come with a result, such as a provider notice.
     *
     * @param  array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    public static function sanitisedPayload(array $payload): array
    {
        return self::sanitise($payload, null);
    }

    /**
     * @param  array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function sanitise(array $payload, ?string $token): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(preg_replace('/[^a-z0-9]/', '', strtolower($key)), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitise($value, $token);

                continue;
            }

            $clean[$key] = $token !== null && $token !== '' && is_string($value) && str_contains($value, $token)
                ? self::REDACTED
                : $value;
        }

        return $clean;
    }
}
