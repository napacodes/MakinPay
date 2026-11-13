<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CustomerPhoneMode;
use Closure;

/**
 * The payer's phone number as a checkout collects it.
 *
 * ONE DEFINITION for every way in - the web deposit form, the app's deposit
 * endpoint, the merchant API, the hosted merchant checkout and the public
 * payment link - so a number one of them accepts is never refused by another,
 * and a gateway always reads the same shape out of `trx_data['customer_phone']`.
 *
 * Unlike PhoneIdentifier, a local number is kept: a login has to match one
 * stored account and cannot guess a country, but a payer typing the number
 * their mobile wallet is registered to knows their own country, and the
 * gateway that needs it international is told its country by its own
 * credentials.
 */
final class CustomerPhone
{
    public const int MIN_DIGITS = 8;

    public const int MAX_DIGITS = 15;

    /**
     * Gateways whose request carries the payer's phone when one is known.
     *
     * Only gateways that actually send it. Moneroo is deliberately absent: it
     * collects the number on its own hosted page, and a number it refused at
     * initialisation would fail a payment that works today.
     */
    private const array GATEWAYS_THAT_USE_IT = [
        'airtel',
        'flutterwave',
        'mercadopago',
        'mtn',
        'paymob',
    ];

    /**
     * Gateways that cannot open a payment without the payer's number: a
     * mobile-money collection is a prompt sent to one handset, and there is
     * no hosted page for the payer to type it on instead.
     *
     * @var array<int, string>
     */
    private const array GATEWAYS_THAT_NEED_IT = [
        'airtel',
        'mtn',
    ];

    /**
     * Countries whose leading zero is part of the subscriber number, not a
     * trunk prefix, keyed by ISO code with the dial code as the value: the
     * one list for checkout, registration (phoneKeepsTrunkZero()) and
     * sign-in. +242 06 851 1358 is dialled with the zero; dropping it there
     * sends a number one digit short.
     *
     * Checked against Google libphonenumber's metadata, which gives none of
     * these a national prefix and a national number that begins with the
     * zero: Italy's landlines (+39 06 ...), Côte d'Ivoire (ten digits since
     * 2021), Benin (ten digits beginning 01 since November 2024) and Congo.
     * Gabon is not one of them: since its November 2019 renumbering the zero
     * is a trunk prefix again (+241 62 12 34 56 is dialled 062 12 34 56 at
     * home), so keeping it sent current Gabonese numbers one digit long.
     *
     * @var array<string, string>
     */
    private const array ZERO_IS_PART_OF_THE_NUMBER = [
        'IT' => '39',
        'CI' => '225',
        'BJ' => '229',
        'CG' => '242',
    ];

    public static function modeForGateway(?string $gatewayCode): CustomerPhoneMode
    {
        $code = strtolower(trim((string) $gatewayCode));

        if (in_array($code, self::GATEWAYS_THAT_NEED_IT, true)) {
            return CustomerPhoneMode::Required;
        }

        if (in_array($code, self::GATEWAYS_THAT_USE_IT, true)) {
            return CustomerPhoneMode::Optional;
        }

        return CustomerPhoneMode::None;
    }

    /**
     * The typed number with its punctuation removed, or null if it is not a
     * phone number at all.
     *
     * A leading '+' or international '00' is kept as '+'; anything else is
     * kept as the digits typed, trunk zero included, because only the gateway
     * knows which country it is dialling into.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $typed = trim((string) $value);

        if ($typed === '' || preg_match('/^\+?[0-9\s().\-]+$/', $typed) !== 1) {
            return null;
        }

        $digits        = preg_replace('/\D+/', '', $typed) ?? '';
        $international = str_starts_with($typed, '+');

        if (! $international && str_starts_with($digits, '00')) {
            $digits        = substr($digits, 2);
            $international = true;
        }

        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        return ($international ? '+' : '').$digits;
    }

    /**
     * The number as international digits with no '+', the MSISDN form a
     * mobile-money API expects (256770123456), or null when it cannot be
     * known without guessing.
     *
     * A number typed with '+' carries its own country. A local one is read
     * against `$countryCode` (ISO 3166 alpha-2): the trunk zero is dropped and
     * the country's dial code put in front. Without a country, only a number
     * long enough to already hold a dial code is accepted as one.
     */
    public static function international(mixed $value, ?string $countryCode): ?string
    {
        $phone = self::normalize($value);

        if ($phone === null) {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            return substr($phone, 1);
        }

        $dialCode = self::dialCode($countryCode);

        if ($dialCode === null) {
            return strlen($phone) >= 11 && ! str_starts_with($phone, '0') ? $phone : null;
        }

        if (str_starts_with($phone, '0')) {
            return self::zeroIsPartOfTheNumber($countryCode)
                ? $dialCode.$phone
                : $dialCode.ltrim($phone, '0');
        }

        if (str_starts_with($phone, $dialCode) && strlen($phone) >= strlen($dialCode) + 8) {
            return $phone;
        }

        return $dialCode.$phone;
    }

    /**
     * The subscriber number without its country code (770123456), for an API
     * that takes the country separately. Null when the number belongs to a
     * different country than `$countryCode`, because the provider would charge
     * a stranger in its own country rather than refuse.
     */
    public static function national(mixed $value, ?string $countryCode): ?string
    {
        $dialCode      = self::dialCode($countryCode);
        $international = self::international($value, $countryCode);

        if ($dialCode === null || $international === null || ! str_starts_with($international, $dialCode)) {
            return null;
        }

        $national = substr($international, strlen($dialCode));

        return strlen($national) >= 6 ? $national : null;
    }

    /**
     * A country's dial code as digits ('256'), or null for an unknown country.
     */
    public static function dialCode(?string $countryCode): ?string
    {
        $code = strtoupper(trim((string) $countryCode));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) (getCountryByCode($code)['dial_code'] ?? '')) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * Whether a country's leading zero is part of the subscriber number
     * rather than a trunk prefix, by ISO code ('CI').
     */
    public static function zeroIsPartOfTheNumber(?string $countryCode): bool
    {
        return array_key_exists(strtoupper(trim((string) $countryCode)), self::ZERO_IS_PART_OF_THE_NUMBER);
    }

    /**
     * The same question by dial code ('225' or '+225'), for code that holds
     * only the dial code, as phoneKeepsTrunkZero() does.
     */
    public static function dialCodeKeepsItsZero(string $dialCode): bool
    {
        return in_array(ltrim(trim($dialCode), '+'), self::ZERO_IS_PART_OF_THE_NUMBER, true);
    }

    /**
     * Validation rules for a `customer_phone` field.
     *
     * @return array<int, mixed>
     */
    public static function rules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:32',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (self::normalize($value) === null) {
                    $fail(__('Enter a valid phone number, digits only, with the country code if you have one.'));
                }
            },
        ];
    }
}
