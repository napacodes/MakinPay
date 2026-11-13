<?php

namespace App\Support;

class GatewayCredentialLabel
{
    /**
     * Provider keys whose humanised form would read poorly or hide the
     * credential's real meaning.
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        'hmac'            => 'HMAC Key',
        'hmac_key'        => 'HMAC Key',
        'certificate_sn'  => 'Certificate Serial Number',
        'stripe_key'      => 'Publishable Key',
        'stripe_secret'   => 'Secret Key',
        'payment_methods' => 'Integration IDs',
        'test_msisdn'     => 'Test MSISDN',
        'web_id'          => 'Website ID',
        'receive_address' => 'Receiving Address',
        'phone'           => 'Phone Number',
        'country'         => 'Country Code',
        'timeout'         => 'Request Timeout (seconds)',
        'endpoint'        => 'Endpoint Path',
        'token'           => 'API Token',
    ];

    /**
     * Word fragments that must stay upper-cased when a key is humanised.
     *
     * @var array<int, string>
     */
    private const ACRONYMS = [
        'api', 'id', 'ids', 'url', 'uri', 'ip', 'ipn', 'sn', 'sms', 'otp', 'pin',
        'msisdn', 'jwt', 'uuid', 'vat', 'iban', 'bic', 'hmac', 'kyc', 'sdk', 'ussd',
    ];

    /**
     * Human readable name for a raw provider credential key.
     */
    public static function label(string $key): string
    {
        $normalized = self::normalize($key);

        if (isset(self::OVERRIDES[$normalized])) {
            return self::OVERRIDES[$normalized];
        }

        $words = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return $key;
        }

        return implode(' ', array_map(
            fn (string $word): string => in_array($word, self::ACRONYMS, true)
                ? strtoupper($word)
                : ucfirst($word),
            $words
        ));
    }

    /**
     * Classifies a credential so the UI can flag sensitive values, endpoints
     * and public identifiers without hard-coding a list per provider.
     *
     * @return 'endpoint'|'public'|'identifier'|'secret'|'value'
     */
    public static function kind(string $key): string
    {
        $normalized = self::normalize($key);

        return match (true) {
            (bool) preg_match('/(^|_)(url|uri|endpoint|host|domain|server)(_|$)/', $normalized)                           => 'endpoint',
            (bool) preg_match('/(^|_)(public|publishable)(_|$)/', $normalized)                                            => 'public',
            (bool) preg_match('/(^|_)(id|ids|code|user|username|email|phone|msisdn|account)(_|$)/', $normalized)          => 'identifier',
            (bool) preg_match('/(secret|token|password|passphrase|hmac|signature|private|certificate|key)/', $normalized) => 'secret',
            default                                                                                                       => 'value',
        };
    }

    /**
     * Whether a credential key holds a boolean on/off setting rather than a
     * value the provider issued (a key, a secret, an account id, ...).
     *
     * Matched by name rather than by the stored value's PHP type: a toggle
     * saved through the admin form round-trips through HTTP as the string
     * "0"/"1", not a native bool, so `is_bool($value)` stops recognising it
     * as a toggle the moment it is saved once. Every boolean credential in
     * this codebase is named exactly `sandbox` or ends in `_enabled`
     * (`connect_enabled`, `remittance_enabled`, ...), so that convention is
     * the reliable signal.
     */
    public static function isToggle(string $key): bool
    {
        $normalized = self::normalize($key);

        return $normalized === 'sandbox' || str_ends_with($normalized, '_enabled');
    }

    private static function normalize(string $key): string
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', trim($key)) ?? $key;

        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $spaced) ?? $spaced);
    }
}
