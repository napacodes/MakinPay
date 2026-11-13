<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A bill payment provider as an admin writes it.
 *
 * A provider's fixed fee names the one currency it is written in, and it
 * applies only to billers priced in that currency; it is never converted.
 * So a fixed fee above zero needs a currency, and is validated at that
 * currency's precision.
 */
class StoreBillPaymentProviderRequest extends FormRequest
{
    /**
     * How an API provider treats a customer account lookup: `advisory` blocks
     * only a definite "not found", `required` also blocks when the lookup is
     * unavailable, `off` never asks.
     *
     * @var list<string>
     */
    public const CUSTOMER_LOOKUP_POLICIES = ['advisory', 'required', 'off'];

    public const CUSTOMER_LOOKUP_DEFAULT = 'advisory';

    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->identityRules(), [
            'name'            => ['required', 'string', 'max:120'],
            'logo'            => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp,ico', 'max:2048'],
            'description'     => ['nullable', 'string', 'max:500'],
            'fee_percent'     => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'fee_fixed'       => ['nullable', 'numeric', 'min:0', 'decimal:0,'.Money::decimals($this->feeCurrency())],
            'fee_currency'    => [Rule::requiredIf(fn (): bool => $this->hasFixedFee()), 'nullable', 'string', 'max:10', 'exists:currencies,code'],
            'processing_note' => ['nullable', 'string', 'max:255'],
            'status'          => ['nullable', 'boolean'],
            'sort_order'      => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'config'                  => ['nullable', 'array'],
            'config.sync_countries'   => ['nullable', 'array', 'max:60'],
            'config.sync_countries.*' => ['string', 'regex:/^[A-Z]{2}$/', 'distinct'],
            'config.customer_lookup'  => ['nullable', 'string', Rule::in(self::CUSTOMER_LOOKUP_POLICIES)],
            'config.auto_sync'        => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique'           => __('Another provider already uses this code.'),
            'driver.in'             => __('The selected driver is not registered.'),
            'fee_currency.required' => __('Choose the currency the fixed fee is written in.'),
            'fee_currency.exists'   => __('Choose a currency the platform knows.'),
            'fee_percent.max'       => __('The percentage fee cannot be more than 100.'),

            'config.array'                     => __('The API settings could not be read. Reload the page and try again.'),
            'config.sync_countries.array'      => __('List the sync countries as two-letter country codes, separated by commas.'),
            'config.sync_countries.max'        => __('List at most 60 sync countries.'),
            'config.sync_countries.*.regex'    => __('Each sync country must be a two-letter country code, such as NG or KE.'),
            'config.sync_countries.*.string'   => __('Each sync country must be a two-letter country code, such as NG or KE.'),
            'config.sync_countries.*.distinct' => __('Each sync country can be listed once.'),
            'config.customer_lookup.in'        => __('Choose how customer accounts are checked: advisory, required or off.'),
            'config.customer_lookup.string'    => __('Choose how customer accounts are checked: advisory, required or off.'),
            'config.auto_sync.boolean'         => __('The daily sync is either on or off.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fee_percent'  => __('percentage fee'),
            'fee_fixed'    => __('fixed fee'),
            'fee_currency' => __('fee currency'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['code' => 'strtolower', 'fee_currency' => 'strtoupper'] as $field => $case) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalised[$field] = $case(trim($value));
            }
        }

        $config = $this->input('config');

        if (is_array($config) && array_key_exists('sync_countries', $config)) {
            $config['sync_countries'] = $this->countryList($config['sync_countries']);
            $normalised['config']     = $config;
        }

        $this->merge($normalised);
    }

    /**
     * The sync countries as a list, from either an array or the one text
     * field the form posts ("NG, ke gh"). Upper-cased and trimmed, blanks
     * dropped; anything that is still not two letters fails the rule.
     *
     * @return mixed a list<string> for a string or an array; anything else untouched, so the array rule refuses it
     */
    private function countryList(mixed $value): mixed
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $country): mixed => is_string($country) ? strtoupper(trim($country)) : $country, $value),
            static fn (mixed $country): bool => $country !== '',
        ));
    }

    /**
     * The code and the driver are chosen once, when the provider is created.
     *
     * @return array<string, list<mixed>>
     */
    protected function identityRules(): array
    {
        return [
            'code'   => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('bill_payment_providers', 'code')],
            'driver' => ['required', 'string', Rule::in(array_keys((array) config('bill_payments.drivers', [])))],
        ];
    }

    private function feeCurrency(): ?string
    {
        $currency = $this->input('fee_currency');

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;
    }

    private function hasFixedFee(): bool
    {
        $fee = $this->input('fee_fixed');

        return is_numeric($fee) && DecimalMoney::compare((string) $fee, '0') > 0;
    }
}
