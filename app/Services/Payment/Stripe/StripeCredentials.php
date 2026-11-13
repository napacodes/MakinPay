<?php

declare(strict_types=1);

namespace App\Services\Payment\Stripe;

use App\Models\PaymentGateway;

/**
 * Single reader for the Stripe credentials stored on the `payment_gateways`
 * row. Every Stripe touchpoint (checkout, payouts, Issuing, Connect) resolves
 * its keys through here so the placeholder handling and the key aliases live
 * in exactly one place.
 */
class StripeCredentials
{
    /**
     * Seed values shipped by the migrations; they are configuration prompts,
     * not usable keys.
     *
     * @var array<int, string>
     */
    private const PLACEHOLDERS = [
        'sk_test_xxx',
        'pk_test_xxx',
    ];

    /**
     * @param array<string, mixed> $credentials
     */
    public function __construct(private readonly array $credentials) {}

    public static function fromGateway(): self
    {
        return new self(PaymentGateway::getCredentials('stripe'));
    }

    /**
     * First usable value among the given keys, or an empty string.
     */
    public function value(string ...$keys): string
    {
        foreach ($keys as $key) {
            if (! isset($this->credentials[$key]) || ! is_scalar($this->credentials[$key])) {
                continue;
            }

            $value = trim((string) $this->credentials[$key]);

            if (! $this->isPlaceholder($key, $value)) {
                return $value;
            }
        }

        return '';
    }

    public function secretKey(): string
    {
        return $this->value('stripe_secret', 'secret_key');
    }

    public function publishableKey(): string
    {
        return $this->value('stripe_key', 'publishable_key');
    }

    public function webhookSecret(): string
    {
        return $this->value('webhook_secret');
    }

    /**
     * Connect events are signed with the secret of the Connect endpoint, which
     * is a different endpoint (and secret) from the account webhook.
     */
    public function connectWebhookSecret(): string
    {
        return $this->value('connect_webhook_secret');
    }

    public function connectEnabled(): bool
    {
        return filter_var($this->credentials['connect_enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * `express` keeps onboarding and the payout dashboard on Stripe;
     * `standard` hands the vendor a full Stripe account of their own.
     */
    public function connectAccountType(): string
    {
        $type = strtolower($this->value('connect_account_type'));

        return in_array($type, ['express', 'standard'], true) ? $type : 'express';
    }

    /**
     * A value equal to its own key is the unfilled seed the migrations write.
     */
    private function isPlaceholder(string $key, string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $normalized = strtolower($value);

        return $normalized === strtolower($key)
            || in_array($normalized, self::PLACEHOLDERS, true)
            || str_starts_with($normalized, 'your_');
    }
}
