<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Enums\BillPayment\BillerAmountType;
use App\Models\Biller;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillerRules;
use App\Support\AmountEntry;
use App\Support\DecimalMoney;
use App\Support\HomeCountry;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A biller as an admin writes it.
 *
 * Every amount on a biller (min, max, each package price and the fixed-fee
 * override) is in the biller's own currency, so each is validated at that
 * currency's precision: a third decimal on a USD price is refused rather
 * than rounded into the ledger later. A customer-number pattern must compile
 * and its example must pass it, so a biller can never be saved in a state no
 * customer number could satisfy.
 */
class StoreBillerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currency = $this->currencyCode();
        $fixed    = 'exclude_unless:amount_type,'.BillerAmountType::Fixed->value;
        $open     = 'exclude_if:amount_type,'.BillerAmountType::Fixed->value;

        $items = [
            'items'                 => [$fixed, 'required', 'array', 'min:1'],
            'items.*.id'            => [$fixed, ...$this->itemIdRules()],
            'items.*.name'          => [$fixed, 'required', 'string', 'max:120'],
            'items.*.amount'        => [$fixed, ...AmountEntry::rules($currency)],
            'items.*.external_code' => [$fixed, 'nullable', 'string', 'max:128', 'distinct'],
            'items.*.status'        => [$fixed, 'nullable', 'boolean'],
        ];

        if ($this->syncedBiller() !== null) {
            /*
             * The catalogue sync owns a synced biller's packages: only an
             * existing package's on/off switch and order may change, so the
             * name, price and code rules are dropped and every row must name
             * a package this biller already has.
             */
            $items = [
                'items'          => [$fixed, 'nullable', 'array'],
                'items.*.id'     => [$fixed, 'required', ...$this->itemIdRules()],
                'items.*.status' => [$fixed, 'nullable', 'boolean'],
            ];
        }

        return [
            'name'                     => ['required', 'string', 'max:120'],
            'bill_category_id'         => ['required', 'integer', 'exists:bill_categories,id'],
            'bill_payment_provider_id' => ['required', 'integer', 'exists:bill_payment_providers,id'],
            'country'                  => ['required', 'string', Rule::in(HomeCountry::selectableCodes())],
            'currency'                 => ['required', 'string', 'max:10', 'exists:currencies,code', $this->knownCurrency()],
            'logo'                     => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp,ico', 'max:2048'],
            'description'              => ['nullable', 'string', 'max:500'],
            'customer_number_label'    => ['required', 'string', 'max:80'],
            'customer_number_pattern'  => ['nullable', 'string', 'max:255', $this->compilingPattern()],
            'customer_number_example'  => ['nullable', 'required_with:customer_number_pattern', 'string', 'max:64', $this->exampleMatchingPattern()],
            'customer_number_help'     => ['nullable', 'string', 'max:255'],
            'amount_type'              => ['required', Rule::in(array_column(BillerAmountType::cases(), 'value'))],
            'min_amount'               => [$open, ...AmountEntry::rules($currency, required: false)],
            'max_amount'               => [$open, ...AmountEntry::rules($currency, required: false)],
            ...$items,
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            // AmountEntry's floor is one unit; a fee override of zero is a real choice.
            'fee_fixed'       => ['nullable', 'numeric', 'min:0', 'decimal:0,'.Money::decimals($currency)],
            'token_label'     => ['nullable', 'string', 'max:80'],
            'processing_note' => ['nullable', 'string', 'max:255'],
            'instructions'    => ['nullable', 'string', 'max:2000'],
            'status'          => ['nullable', 'boolean'],
            'sort_order'      => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.in'                            => __('Choose a country from the list.'),
            'currency.exists'                       => __('Choose a currency the platform knows.'),
            'customer_number_example.required_with' => __('Give an example number that matches the pattern.'),
            'items.required'                        => __('Add at least one package to a fixed-amount biller.'),
            'items.min'                             => __('Add at least one package to a fixed-amount biller.'),
            'items.*.name.required'                 => __('Name every package.'),
            'items.*.amount.required'               => __('Price every package.'),
            'items.*.external_code.distinct'        => __('Two packages cannot share a code.'),
            'items.*.id.exists'                     => __('That package does not belong to this biller.'),
            'items.*.id.prohibited'                 => __('A new biller has no packages to update yet.'),
            'items.*.id.required'                   => __('Packages are managed by catalogue sync. Only an existing package can be switched on or off.'),
            'fee_percent.max'                       => __('The percentage fee cannot be more than 100.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bill_category_id'         => __('category'),
            'bill_payment_provider_id' => __('provider'),
            'customer_number_label'    => __('customer number label'),
            'customer_number_pattern'  => __('pattern'),
            'customer_number_example'  => __('example'),
            'customer_number_help'     => __('help text'),
            'min_amount'               => __('minimum amount'),
            'max_amount'               => __('maximum amount'),
            'items.*.name'             => __('package name'),
            'items.*.amount'           => __('package price'),
            'items.*.external_code'    => __('package code'),
            'fee_percent'              => __('percentage fee'),
            'fee_fixed'                => __('fixed fee'),
        ];
    }

    /**
     * The open range must not be upside down. Compared as decimals, so
     * "10.125" and "10.13" in a 3-dp currency are told apart.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('amount_type') === BillerAmountType::Fixed->value
                    || $validator->errors()->hasAny(['min_amount', 'max_amount'])) {
                    return;
                }

                $min = $this->input('min_amount');
                $max = $this->input('max_amount');

                if (! is_numeric($min) || ! is_numeric($max)) {
                    return;
                }

                if (DecimalMoney::compare((string) $max, (string) $min) < 0) {
                    $validator->errors()->add('max_amount', __('The maximum amount must be at least the minimum amount.'));
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->has('bill_payment_provider_id')) {
                    return;
                }

                $provider = BillPaymentProvider::query()->find((int) $this->input('bill_payment_provider_id'));
                $code     = trim((string) $this->editedBiller()?->external_code);

                if ($provider !== null && $code === '' && $provider->billersComeFromSync()) {
                    $validator->errors()->add('bill_payment_provider_id', __('Billers for :provider come from the catalogue sync. Run the sync on the provider screen, then switch on the biller it imports.', [
                        'provider' => $provider->name,
                    ]));
                }
            },
        ];
    }

    /**
     * The biller being edited, or null for a new one. A new biller never has
     * a provider code: only the catalogue sync sets one.
     */
    protected function editedBiller(): ?Biller
    {
        return null;
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['country', 'currency'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalised[$field] = strtoupper(trim($value));
            }
        }

        // The provider owns a synced biller's amount type; the sync refreshes it.
        $synced = $this->syncedBiller();

        if ($synced !== null && $synced->amount_type !== null) {
            $normalised['amount_type'] = $synced->amount_type->value;
        }

        $this->merge($normalised);
    }

    /**
     * The biller being edited when the catalogue sync manages it, or null.
     * A new biller is never synced.
     */
    protected function syncedBiller(): ?Biller
    {
        return null;
    }

    /**
     * A new biller has no packages yet, so no submitted package may claim to
     * be an existing one.
     *
     * @return list<mixed>
     */
    protected function itemIdRules(): array
    {
        return ['prohibited'];
    }

    private function currencyCode(): ?string
    {
        $currency = $this->input('currency');

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;
    }

    private function knownCurrency(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (Money::decimalsIfKnown(is_string($value) ? $value : null) === null) {
                $fail(__('Choose a currency the platform knows.'));
            }
        };
    }

    private function compilingPattern(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! BillerRules::compiles($value)) {
                $fail(__('The pattern is not a valid regular expression.'));
            }
        };
    }

    /**
     * The example is checked the way a customer's number is: whitespace
     * stripped, then matched against the whole anchored pattern. A pattern
     * that does not compile reports its own error, so the example is not
     * blamed for it.
     */
    private function exampleMatchingPattern(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $pattern = $this->input('customer_number_pattern');

            if (! is_string($pattern) || trim($pattern) === '' || ! BillerRules::compiles($pattern)) {
                return;
            }

            $example = app(BillerRules::class)->normalizeCustomerNumber((string) $value);

            if (@preg_match(BillerRules::wrap($pattern), $example) !== 1) {
                $fail(__('The example does not match the pattern.'));
            }
        };
    }
}
