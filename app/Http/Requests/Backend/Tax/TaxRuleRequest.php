<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\Tax;

use App\Enums\Tax\TaxableType;
use App\Enums\Tax\TaxBase;
use App\Enums\Tax\TaxScope;
use App\Support\DecimalMoney;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin creates or edits one tax or levy rule.
 *
 * A rule needs a percent, a fixed amount, or both; one that is zero on both
 * would charge nothing and only clutter the list. Countries are required for
 * a country-scoped rule and dropped for any other scope. The percent is
 * capped at 100: a rule above that charges more tax than the whole base and
 * is a typo, not a rate.
 *
 * Charged by amount brackets instead, the rule needs at least one bracket,
 * limits that rise from one bracket to the next, an open limit ("and above")
 * on the last bracket only, and at least one bracket that charges something.
 * A row left wholly blank is ignored. The rule's own percent and fixed amount
 * are then stored as zero, so only the brackets charge.
 */
class TaxRuleRequest extends FormRequest
{
    public const NAME_MAX_LENGTH = 100;

    public const CHARGE_FLAT = 'flat';

    public const CHARGE_BRACKETS = 'brackets';

    public const MAX_BRACKETS = 20;

    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'transaction_type'   => ['required', Rule::enum(TaxableType::class)],
            'scope'              => ['required', Rule::enum(TaxScope::class)],
            'countries'          => ['nullable', 'array', Rule::requiredIf(fn (): bool => $this->input('scope') === TaxScope::Countries->value)],
            'countries.*'        => ['string', 'size:2', Rule::in(array_keys($this->countryOptions()))],
            'percent'            => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'fixed_amount'       => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,15}(\.\d{1,8})?$/'],
            'base'               => ['required', Rule::enum(TaxBase::class)],
            'charge_by'          => ['nullable', Rule::in([self::CHARGE_FLAT, self::CHARGE_BRACKETS])],
            'brackets'           => ['nullable', 'array', 'max:'.self::MAX_BRACKETS],
            'brackets.*'         => ['array'],
            'brackets.*.up_to'   => ['nullable', 'numeric', 'gt:0', 'regex:/^\d{1,15}(\.\d{1,8})?$/'],
            'brackets.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'brackets.*.fixed'   => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,15}(\.\d{1,8})?$/'],
            'is_active'          => ['nullable', 'boolean'],
            'sort_order'         => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'countries.required'       => __('Choose at least one country for a country rule.'),
            'countries.*.in'           => __('One of the countries is not valid.'),
            'percent.max'              => __('A percent may not be more than 100.'),
            'percent.regex'            => __('Enter the percent with at most four decimal places.'),
            'fixed_amount.regex'       => __('Enter the fixed amount as a plain number with at most eight decimal places.'),
            'brackets.max'             => __('A rule may have at most :max brackets.'),
            'brackets.*.up_to.gt'      => __('A bracket limit must be more than zero.'),
            'brackets.*.up_to.regex'   => __('Enter the limit as a plain number with at most eight decimal places.'),
            'brackets.*.percent.max'   => __('A percent may not be more than 100.'),
            'brackets.*.percent.regex' => __('Enter the percent with at most four decimal places.'),
            'brackets.*.fixed.regex'   => __('Enter the fixed amount as a plain number with at most eight decimal places.'),
        ];
    }

    /** Country codes are compared upper-case, however they were typed. */
    protected function prepareForValidation(): void
    {
        $countries = $this->input('countries');

        if (is_array($countries)) {
            $this->merge(['countries' => array_values(array_map(
                static fn (mixed $code): mixed => is_string($code) ? strtoupper(trim($code)) : $code,
                $countries,
            ))]);
        }

        $brackets = $this->input('brackets');

        if (is_array($brackets)) {
            $this->merge(['brackets' => array_values(array_filter(
                $brackets,
                static fn (mixed $row): bool => ! is_array($row) || collect(['up_to', 'percent', 'fixed'])
                    ->contains(static fn (string $key): bool => trim((string) ($row[$key] ?? '')) !== ''),
            ))]);
        }
    }

    public function chargesByBrackets(): bool
    {
        return $this->input('charge_by') === self::CHARGE_BRACKETS;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->chargesByBrackets()) {
                $this->validateBrackets($validator);

                return;
            }

            $percent = (string) $this->input('percent', '');
            $fixed   = (string) $this->input('fixed_amount', '');

            $charges = (is_numeric($percent) && (float) $percent > 0) || (is_numeric($fixed) && (float) $fixed > 0);

            if (! $charges) {
                $validator->errors()->add('percent', __('Set a percent, a fixed amount, or both.'));
            }
        });
    }

    /**
     * The brackets have to describe one amount scale: at least one bracket,
     * limits rising in the order typed, only the last one open, and something
     * charged somewhere. A row whose own fields failed is left to that error.
     */
    private function validateBrackets(Validator $validator): void
    {
        $brackets = array_values(array_filter((array) $this->input('brackets', []), 'is_array'));

        if ($brackets === []) {
            $validator->errors()->add('brackets', __('Add at least one bracket.'));

            return;
        }

        $last     = count($brackets) - 1;
        $previous = null;
        $charges  = false;

        foreach ($brackets as $index => $row) {
            $upTo    = trim((string) ($row['up_to'] ?? ''));
            $percent = trim((string) ($row['percent'] ?? ''));
            $fixed   = trim((string) ($row['fixed'] ?? ''));

            $charges = $charges
                || (is_numeric($percent) && DecimalMoney::compare($percent, '0') > 0)
                || (is_numeric($fixed) && DecimalMoney::compare($fixed, '0') > 0);

            if ($upTo === '') {
                if ($index !== $last) {
                    $validator->errors()->add("brackets.{$index}.up_to", __('Only the last bracket may be left without a limit.'));
                }

                continue;
            }

            if (! is_numeric($upTo)) {
                continue;
            }

            if ($previous !== null && DecimalMoney::compare($upTo, $previous) <= 0) {
                $validator->errors()->add("brackets.{$index}.up_to", __('Each limit must be higher than the one before it.'));
            }

            $previous = $upTo;
        }

        if (! $charges) {
            $validator->errors()->add('brackets', __('At least one bracket must charge a percent or a fixed amount.'));
        }
    }

    /**
     * The rule as it is stored: decimals as typed, countries only for a
     * country rule and upper-cased.
     *
     * @return array<string, mixed>
     */
    public function rule(): array
    {
        $data  = $this->validated();
        $scope = TaxScope::from((string) $data['scope']);

        return [
            'name'             => trim((string) $data['name']),
            'transaction_type' => (string) $data['transaction_type'],
            'scope'            => $scope->value,
            'countries'        => $scope === TaxScope::Countries
                ? array_values(array_unique(array_map(static fn (string $code): string => strtoupper($code), (array) $data['countries'])))
                : null,
            'percent'      => ! $this->chargesByBrackets() && filled($data['percent'] ?? null) ? (string) $data['percent'] : '0',
            'fixed_amount' => ! $this->chargesByBrackets() && filled($data['fixed_amount'] ?? null) ? (string) $data['fixed_amount'] : '0',
            'brackets'     => $this->chargesByBrackets() ? array_map(static fn (array $row): array => [
                'up_to'   => filled($row['up_to'] ?? null) ? (string) $row['up_to'] : null,
                'percent' => filled($row['percent'] ?? null) ? (string) $row['percent'] : '0',
                'fixed'   => filled($row['fixed'] ?? null) ? (string) $row['fixed'] : '0',
            ], array_values((array) ($data['brackets'] ?? []))) : null,
            'base'       => (string) $data['base'],
            'is_active'  => $this->boolean('is_active'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * Every country a rule may name, ISO code => name.
     *
     * @return array<string, string>
     */
    public function countryOptions(): array
    {
        $options = [];

        foreach (getCountries() as $country) {
            $code = strtoupper((string) ($country['code'] ?? ''));

            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $options[$code] = (string) ($country['name'] ?? $code);
            }
        }

        return $options;
    }
}
