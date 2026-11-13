<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Fluent;
use Throwable;

/**
 * The operator's home country: the one admin setting (layer C,
 * `home_country`) that lets that country's customers sign in with the number
 * the way they dial it at home, and that a sign-up form starts on.
 *
 * ONE READER. Everything that asks "which country is home?" comes through
 * here, so an empty, unknown or malformed stored value means the same thing
 * everywhere: no home country, which is exactly how the platform behaved
 * before the setting existed. An upgraded installation has no row at all and
 * reads the same way, so nothing changes until an admin picks a country.
 *
 * No request-level memo on purpose: the value is one cached settings read,
 * and tests change it mid-test.
 */
final class HomeCountry
{
    private const string NONE_LABEL = 'None (international numbers only)';

    /**
     * @var list<string>|null
     */
    private static ?array $selectableCodes = null;

    /**
     * The home country as an upper-case ISO-2 code, or null when none is set.
     *
     * A stored value the country list does not know ('ZZ', 'Bangladesh') is
     * treated as unset rather than half-applied: a code with no dial code
     * would give the login nothing to read a local number against.
     */
    public static function code(): ?string
    {
        $code = strtoupper(trim((string) setting('home_country', '')));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || getCountryByCode($code) === null) {
            return null;
        }

        return $code;
    }

    /**
     * The home country's record, or null when none is set.
     *
     * @return array{code: string, name: string, dial_code: string}|null
     */
    public static function country(): ?array
    {
        $code = self::code();

        if ($code === null) {
            return null;
        }

        $country = getCountryByCode($code);

        return [
            'code'      => $code,
            'name'      => (string) ($country['name'] ?? ''),
            'dial_code' => (string) ($country['dial_code'] ?? ''),
        ];
    }

    /**
     * The home country's dial code as digits ('880'), or null.
     */
    public static function dialCode(): ?string
    {
        return CustomerPhone::dialCode(self::code());
    }

    /**
     * Whether a country code is the home country.
     *
     * The one comparison every caller shares, so "local" cannot drift between
     * the places that ask. False when no home country is set.
     */
    public static function includes(?string $countryCode): bool
    {
        $home = self::code();

        return $home !== null && strtoupper(trim((string) $countryCode)) === $home;
    }

    /**
     * Whether a country code is a KNOWN country other than the home one.
     *
     * Not the negation of includes(): with no home country set, or for a
     * blank or unknown code, the answer to both is "no" - which is "unknown",
     * not "international". Something charging international customers a levy
     * must not charge everyone on a site that never chose a home country.
     */
    public static function isForeign(?string $countryCode): bool
    {
        $home = self::code();
        $code = strtoupper(trim((string) $countryCode));

        if ($home === null || preg_match('/^[A-Z]{2}$/', $code) !== 1 || getCountryByCode($code) === null) {
            return false;
        }

        return $code !== $home;
    }

    /**
     * The home country shaped like getLocation(), so a sign-up form can start
     * on it without asking ip-api.com who the visitor is. Null when none is
     * set.
     */
    public static function location(): ?Fluent
    {
        $country = self::country();

        if ($country === null) {
            return null;
        }

        return new Fluent([
            'country_code' => $country['code'],
            'name'         => $country['name'],
            'dial_code'    => $country['dial_code'],
            'ip'           => request()->ip(),
        ]);
    }

    /**
     * Every country code the setting may hold, for its `in:` rule.
     *
     * READ WHILE THE CONFIG LOADS - config/settings.php builds the rule - so
     * this must not touch the Cache facade, setting() or anything else from
     * the container, and it must never throw: an exception here takes down
     * every request, the installer and the updater included. Under
     * `config:cache` the list is baked in, so a change to the country file
     * needs a `config:clear`.
     *
     * @return list<string>
     */
    public static function selectableCodes(): array
    {
        return self::$selectableCodes ??= self::codesFromFile(dirname(__DIR__, 2).'/resources/json/country_codes.json');
    }

    /**
     * The ISO-2 codes in a country file, or none for a missing or broken one.
     *
     * @return list<string>
     */
    public static function codesFromFile(string $path): array
    {
        try {
            $raw     = is_file($path) ? file_get_contents($path) : false;
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $codes = [];

        foreach ($decoded as $country) {
            $code = strtoupper(trim((string) (is_array($country) ? ($country['code'] ?? '') : '')));

            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * The admin select: None first, then every country, label => code.
     *
     * None has to be an option of its own because the settings select has no
     * blank entry; it submits '', which is stored empty and reads back as no
     * home country.
     *
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        $options = [__(self::NONE_LABEL) => ''];

        foreach (getCountries() as $country) {
            $code = strtoupper((string) ($country['code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $options[getCountryDisplayLabel($code) ?? title((string) ($country['name'] ?? $code))] = $code;
        }

        return $options;
    }
}
