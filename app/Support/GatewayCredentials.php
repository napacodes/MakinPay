<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\NotifyErrorException;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Log;

/**
 * Refuse to call a payment gateway that has not really been configured.
 *
 * Gateways ship with placeholder credentials so the admin can see the shape of
 * what to fill in — Block.io's `api_key` literally holds the string "api_key",
 * 2Checkout's hold "...". Nothing stopped the app treating those as real, so a
 * customer could complete the whole deposit flow and be handed the provider's
 * rejection instead of an answer. In the reported case Block.io returned an
 * empty body, and the error read "Failed to create Block.io payment address:"
 * with nothing after the colon.
 *
 * Failing here costs the customer one clear sentence; failing at the provider
 * costs them the transaction and tells them nothing.
 */
class GatewayCredentials
{
    /**
     * Values a gateway ships with that mean "not filled in yet".
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = ['...', '....', 'null', 'none', 'changeme', 'your_api_key'];

    /**
     * Credentials for a gateway, guaranteed to contain every key named.
     *
     * @param  list<string>         $required
     * @return array<string, mixed>
     *
     * @throws NotifyErrorException
     */
    public static function require(string $code, array $required): array
    {
        $gateway     = PaymentGateway::getByCode($code);
        $credentials = $gateway?->credentials ?? [];
        $name        = $gateway?->name ?: $code;

        $missing = array_values(array_filter(
            $required,
            fn (string $key): bool => self::isUnset($credentials[$key] ?? null, $key),
        ));

        if ($missing === []) {
            return $credentials;
        }

        // The operator needs the key names; the customer must not see them.
        Log::warning('Payment gateway is missing credentials.', [
            'gateway' => $code,
            'missing' => $missing,
        ]);

        throw new NotifyErrorException(__(
            ':gateway is not finished being set up, so this payment cannot be started. Please choose another payment method or contact support.',
            ['gateway' => $name]
        ));
    }

    /**
     * Whether a credential is absent, blank, or still the placeholder a gateway
     * ships with. A value equal to its own key name — `api_key => "api_key"` —
     * is the seeded placeholder, never a real secret.
     */
    private static function isUnset(mixed $value, string $key): bool
    {
        if ($value === null || is_array($value)) {
            return $value === null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return true;
        }

        if (strcasecmp($value, $key) === 0) {
            return true;
        }

        return in_array(strtolower($value), self::PLACEHOLDERS, true);
    }
}
