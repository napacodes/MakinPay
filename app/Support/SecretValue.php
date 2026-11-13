<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Secrets at rest: the SMTP password and provider API keys are stored sealed
 * with the application key, so a copied settings row or a database export is
 * useless on another install.
 *
 * Every installation already holds these values in plain text, so reading is
 * tolerant: a value that is not a sealed payload comes back untouched, and
 * the next save seals it. Upgrades therefore change nothing until an
 * administrator saves the field again.
 */
final class SecretValue
{
    /**
     * Seal a plain secret for storage. Blank values stay blank so "not set"
     * remains distinguishable from a real value.
     */
    public static function seal(?string $plain): ?string
    {
        if ($plain === null || $plain === '' || self::isSealed($plain)) {
            return $plain;
        }

        return Crypt::encryptString($plain);
    }

    /**
     * Read a stored secret back. Legacy plain-text values pass straight
     * through; a payload sealed under a key this install no longer has is
     * reported once and treated as unset rather than handed on as garbage.
     */
    public static function reveal(mixed $stored): mixed
    {
        if (! is_string($stored) || ! self::isSealed($stored)) {
            return $stored;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::warning('A stored secret could not be decrypted with the current application key; treating it as unset.');

            return '';
        }
    }

    /**
     * Whether a string is a Laravel encrypter payload rather than a raw value.
     */
    public static function isSealed(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
