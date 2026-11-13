<?php

declare(strict_types=1);

namespace App\Http\Requests\Currency;

use App\Constants\CurrencyType;
use App\Rules\CurrencyCode;
use App\Support\CurrencyPrecision;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and editing a currency.
 *
 * Lifted out of CurrencyController, which validated inline against the project
 * convention, and tightened in three places that were letting bad money
 * configuration into the table:
 *
 *  - `code` had no shape at all beyond `max:10`. See {@see CurrencyCode}.
 *  - `code` was checked for uniqueness on create and *not at all* on update,
 *    so editing one currency could give it the code of another. It is now
 *    unique ignoring the row being edited, which is what was meant.
 *  - `exchange_rate` had no `numeric` and no lower bound, so a blank, a word
 *    or a zero passed validation. Zero is not a rate: every conversion through
 *    it is either undefined or nonsense, which is exactly what RateGuard
 *    exists to refuse at transaction time. Refusing it at the form is cheaper
 *    and tells the operator immediately.
 */
class StoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Codes are stored and compared uppercase — `getCurrencyByCode()` and
     * `Money::decimals()` both uppercase before looking up — so "usd" would
     * have been written in a form nothing displays correctly and only a
     * case-insensitive collation kept resolvable.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }

        // A blank rate field is not a rate of null. `exchange_rate` is NOT NULL
        // with a default of 1, and ConvertEmptyStringsToNull rewrites the empty
        // input to null, so an admin who ticked "live rate" and left the box
        // empty had that null handed to Currency::create() and the insert blew
        // up on the constraint — after validation had passed. Dropping the key
        // lets the column default stand until `currency:refresh-rates` writes
        // the first real rate; RateGuard blocks conversions in the meantime,
        // which is the correct state for a currency that is not yet priced.
        if ($this->exists('exchange_rate') && in_array($this->input('exchange_rate'), [null, ''], true)) {
            $this->request->remove('exchange_rate');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currency = $this->route('currency');

        return [
            // Flag is optional — the controller falls back to a placeholder if
            // none is uploaded. This was 'required' on create once, which
            // produced a silent validation failure when the admin forgot to
            // pick a file: the modal closes on redirect-back and the toast was
            // easy to miss.
            'flag' => ['nullable', 'image', 'max:4096'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:10',
                new CurrencyCode($this->input('type')),
                Rule::unique('currencies', 'code')->ignore($currency),
            ],
            'symbol' => ['required', 'string', 'max:10'],
            'type'   => ['required', Rule::in(CurrencyType::getTypes())],

            // Only required when the operator is setting the rate by hand; a
            // live-rate currency gets its first rate from
            // `currency:refresh-rates`. Whatever is supplied must still be a
            // usable rate.
            'exchange_rate' => ['required_if:rate_live,0', 'nullable', 'numeric', 'gt:0'],
            'rate_live'     => ['boolean'],

            // Capped at the ledger's own scale — decimal(24,8) cannot store more.
            'decimal_places' => ['nullable', 'integer', 'min:0', 'max:'.CurrencyPrecision::MAX_SCALE],

            /*
             * How the amount is written rather than how it is stored. Both are
             * nullable here and defaulted in the controller, the same way
             * decimal_places is: the columns are NOT NULL with a default, and
             * ConvertEmptyStringsToNull would otherwise hand a null straight to
             * Currency::create() and blow up on the constraint after validation
             * had passed.
             */
            'symbol_position' => ['nullable', Rule::in([Money::POSITION_PREFIX, Money::POSITION_SUFFIX])],
            'number_grouping' => ['nullable', Rule::in([Money::GROUPING_STANDARD, Money::GROUPING_INDIAN])],

            // Roles is optional — the controller auto-fills sane defaults for
            // any role that is missing, so a create cannot silently fail just
            // because the toggle-collapsed body posted nothing.
            'roles' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'         => __('Pick a currency name from the dropdown after choosing a type.'),
            'code.required'         => __('Currency code is required.'),
            'code.unique'           => __('A currency with this code already exists.'),
            'symbol.required'       => __('Currency symbol is required.'),
            'type.required'         => __('Choose whether this is a fiat or crypto currency.'),
            'exchange_rate.gt'      => __('The exchange rate must be greater than zero.'),
            'exchange_rate.numeric' => __('The exchange rate must be a number.'),
            'symbol_position.in'    => __('Choose whether the symbol goes before or after the amount.'),
            'number_grouping.in'    => __('Choose either thousands or lakh and crore grouping.'),
        ];
    }
}
