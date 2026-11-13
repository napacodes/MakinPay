<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Validation\Rule;

/**
 * How long a person's identity may be, in one place.
 *
 * Every field here was `max:255` — the column width, not a decision — and the
 * email had no length rule at all. A merchant registered as
 * "@Blackcarpetrideshare&logistics" and the admin lists it appears in had no
 * chance: a 255-character username is not something a row, a card or a
 * notification can lay out.
 *
 * The numbers are not invented. Each is the limit a recognised standard
 * already sets for that field, so a name that fits a passport fits here, and
 * an address that a mail server will accept is an address this will store.
 *
 * These bound INPUT. They are deliberately not column widths: the columns
 * stay varchar(255) because this application ships to existing installations
 * whose rows already hold longer values, and narrowing a column truncates
 * customer data. Values the application generates for itself — the
 * marketplace commission account's 39-character `system_marketplace_*`
 * username — never pass through a form and are unaffected.
 */
final class IdentityRules
{
    /**
     * ICAO Doc 9303 gives the machine-readable name field of a passport 39
     * characters. This application verifies identity against those documents,
     * so a name that cannot fit on the document is a name it cannot check.
     */
    public const NAME_MAX = 39;

    /**
     * RFC 5321 §4.5.3.1.3: a forward/reverse path is at most 256 octets
     * including the angle brackets, which leaves 254 for the address itself.
     * The local part is separately capped at 64 octets by §4.5.3.1.1.
     */
    public const EMAIL_MAX = 254;

    public const EMAIL_LOCAL_MAX = 64;

    /**
     * No RFC governs usernames. 30 is the ceiling the large services settled
     * on — Instagram 30, Twitter 15, GitHub 39 — and it is what the identity
     * cells on this admin can show without truncating.
     */
    public const USERNAME_MAX = 30;

    /** Two characters is a handle; one is a collision waiting to happen. */
    public const USERNAME_MIN = 3;

    /**
     * A trading name, which is not a person's name and is allowed to be
     * longer — but not a paragraph.
     */
    public const BUSINESS_NAME_MAX = 100;

    /**
     * @return list<string>
     */
    public static function name(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:'.self::NAME_MAX];
    }

    /**
     * @return list<string>
     */
    public static function businessName(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:'.self::BUSINESS_NAME_MAX];
    }

    /**
     * @return list<mixed>
     */
    public static function email(?string $uniqueTable = null, mixed $ignoreId = null): array
    {
        $rules = ['required', 'string', 'email', 'max:'.self::EMAIL_MAX, 'regex:/^[^@]{1,'.self::EMAIL_LOCAL_MAX.'}@/'];

        if ($uniqueTable !== null) {
            $rules[] = $ignoreId === null
                ? Rule::unique($uniqueTable, 'email')
                : Rule::unique($uniqueTable, 'email')->ignore($ignoreId);
        }

        return $rules;
    }

    /**
     * A handle is letters, digits, dot, underscore and hyphen — the set every
     * major service allows and the only one that survives being put in a URL,
     * an @mention and a CSV export without escaping. The "&" in
     * "@Blackcarpetrideshare&logistics" is exactly what this turns away.
     *
     * @return list<mixed>
     */
    public static function username(?string $uniqueTable = null, mixed $ignoreId = null): array
    {
        $rules = [
            'required',
            'string',
            'min:'.self::USERNAME_MIN,
            'max:'.self::USERNAME_MAX,
            'regex:/^[A-Za-z0-9._-]+$/',
        ];

        if ($uniqueTable !== null) {
            $rules[] = $ignoreId === null
                ? Rule::unique($uniqueTable, 'username')
                : Rule::unique($uniqueTable, 'username')->ignore($ignoreId);
        }

        return $rules;
    }

    /**
     * PH7: the registered country, one of the list a customer may sign up
     * with. Tax rules read local or international from it and only an admin
     * may correct it afterwards, so two unknown letters are refused at the
     * door rather than stored.
     *
     * The code is read whatever its case, as the app has always sent it, so
     * the rule refuses no sign-up that went through before unless its country
     * was never one a customer could pick.
     *
     * @return list<mixed>
     */
    public static function country(): array
    {
        return ['required', 'string', 'size:2', static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! in_array(strtoupper($value), HomeCountry::selectableCodes(), true)) {
                $fail(__('Choose a country from the list.'));
            }
        }];
    }

    /**
     * Said once, so three registration forms cannot word it three ways.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'username.regex' => __('Usernames may use letters, numbers, dots, underscores and hyphens only.'),
            'email.regex'    => __('The part before the @ may be at most :count characters.', ['count' => self::EMAIL_LOCAL_MAX]),
            'country.size'   => __('Choose a country from the list.'),
        ];
    }
}
