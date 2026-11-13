<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which plugin credential fields are secrets, and how they move between the
 * admin form and storage.
 *
 * A secret is never written into the edit form. The form shows whether one is
 * saved; a blank submission keeps it, a typed value replaces it. On disk the
 * value is sealed (see {@see SecretValue}), and every reader goes through
 * {@see self::reveal()} so the rest of the application still sees plain text.
 */
final class PluginSecretFields
{
    /**
     * Credential keys that hold a secret. Public identifiers such as
     * `recaptcha_key`, `pusher_app_key` or `page_id` are deliberately absent.
     *
     * @var list<string>
     */
    public const KEYS = [
        'api_key',
        'access_token',
        'auth_token',
        'recaptcha_secret',
        'pusher_app_secret',
        'client_secret',
        'token',
        'secret',
        'secret_key',
        'secret_hash',
    ];

    public static function isSecret(string $key): bool
    {
        if (in_array($key, self::KEYS, true)) {
            return true;
        }

        foreach (['_secret', '_token', '_password'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a stored credential is a real value rather than blank or the
     * `api_key => "api_key"` placeholder the seeders ship with.
     */
    public static function hasStoredValue(string $key, mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $value = trim((string) $value);

        return $value !== '' && strcasecmp($value, $key) !== 0;
    }

    /**
     * Fill blank secret fields of a submission with the values already saved,
     * so an administrator can change the visible fields without retyping every
     * key.
     *
     * @param  array<string, mixed> $incoming
     * @param  array<string, mixed> $stored   Already revealed credentials.
     * @return array<string, mixed>
     */
    public static function withKeptSecrets(array $incoming, array $stored): array
    {
        foreach ($incoming as $key => $value) {
            if (! is_string($key) || $key === 'fields' || ! self::isSecret($key)) {
                continue;
            }

            if ((is_string($value) || $value === null) && trim((string) $value) === '' && array_key_exists($key, $stored)) {
                $incoming[$key] = $stored[$key];
            }
        }

        return $incoming;
    }

    /**
     * Seal every secret string in a credential array for storage.
     *
     * @param  array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public static function seal(array $credentials): array
    {
        foreach ($credentials as $key => $value) {
            if (is_string($key) && is_string($value) && self::isSecret($key)) {
                $credentials[$key] = SecretValue::seal($value);
            }
        }

        return $credentials;
    }

    /**
     * Reveal every sealed secret in a stored credential array.
     *
     * @param  array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public static function reveal(array $credentials): array
    {
        foreach ($credentials as $key => $value) {
            if (is_string($key) && is_string($value) && self::isSecret($key)) {
                $credentials[$key] = SecretValue::reveal($value);
            }
        }

        return $credentials;
    }
}
