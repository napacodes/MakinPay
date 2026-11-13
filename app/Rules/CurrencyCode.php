<?php

declare(strict_types=1);

namespace App\Rules;

use App\Constants\CurrencyType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A currency code has a shape, and which shape depends on what it names.
 *
 * ISO 4217 defines a fiat code as exactly three letters — no digits, no
 * punctuation, no length of its own choosing. The column accepted anything up
 * to ten characters, so "usd", "US Dollar" and "usd " were all storable, and a
 * lowercase code then read back through lookups that uppercase before
 * comparing.
 *
 * Crypto tickers are not ISO-governed and legitimately vary in length (BTC,
 * USDT, SHIB, MATIC), so they get a permissive but still bounded shape:
 * uppercase alphanumerics, two to ten characters. Digits are allowed because
 * real tickers use them.
 *
 * Deliberately NOT a membership test against resources/json/currencies.json.
 * That file lists 161 fiat codes where ISO 4217 has around 180 active ones, so
 * requiring membership would reject legitimate currencies — and the crypto
 * list ages the moment a new asset is listed. Shape is the part that is
 * actually a standard; the list is a convenience for the admin dropdown.
 */
class CurrencyCode implements ValidationRule
{
    public function __construct(private readonly ?string $type = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = is_string($value) ? trim($value) : '';

        if ($code === '') {
            $fail(__('The currency code is required.'));

            return;
        }

        if ($this->type === CurrencyType::CRYPTO) {
            if (preg_match('/^[A-Z0-9]{2,10}$/', $code) !== 1) {
                $fail(__('A crypto currency code must be 2 to 10 uppercase letters or digits, for example BTC or USDT.'));
            }

            return;
        }

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            $fail(__('A fiat currency code must be exactly three letters, as ISO 4217 defines it — for example USD or BDT.'));
        }
    }
}
