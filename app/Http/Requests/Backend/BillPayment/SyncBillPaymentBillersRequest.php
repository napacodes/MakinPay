<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Models\BillPaymentProvider;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The admin "Sync billers" form: which countries, which bill categories, and
 * whether it is only a preview.
 *
 * Countries arrive as one text field ("NG, ke gh") and are split, trimmed and
 * upper-cased first. Left blank, the provider's own sync countries are used;
 * with neither, the request is refused. Categories must be ones the
 * provider's driver maps; none chosen means all of them.
 */
class SyncBillPaymentBillersRequest extends FormRequest
{
    /**
     * Its own error bag: the sync form sits beside the provider form, whose
     * error summary must not light up for a sync mistake.
     *
     * @var string
     */
    protected $errorBag = 'sync';

    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * The bill category codes a provider's driver maps, in config order.
     *
     * @return list<string>
     */
    public static function mappedCategoryCodes(BillPaymentProvider $provider): array
    {
        $map = (array) config('bill_payments.category_map.'.$provider->driver, []);

        return array_values(array_unique(array_map(static fn (mixed $code): string => strtolower(trim((string) $code)), array_values($map))));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $provider = $this->route('billPaymentProvider');
        $allowed  = $provider instanceof BillPaymentProvider ? self::mappedCategoryCodes($provider) : [];

        return [
            'countries'    => ['nullable', 'array', 'max:60'],
            'countries.*'  => ['string', 'regex:/^[A-Z]{2}$/', 'distinct'],
            'categories'   => ['nullable', 'array'],
            'categories.*' => ['string', 'distinct', Rule::in($allowed)],
            'dry_run'      => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'countries.array'       => __('List the countries as two-letter country codes, separated by commas.'),
            'countries.max'         => __('Sync at most 60 countries at once.'),
            'countries.*.regex'     => __('Each country must be a two-letter country code, such as NG or KE.'),
            'countries.*.string'    => __('Each country must be a two-letter country code, such as NG or KE.'),
            'countries.*.distinct'  => __('Each country can be listed once.'),
            'categories.array'      => __('Choose the categories from the list.'),
            'categories.*.in'       => __('Choose categories this provider can sync.'),
            'categories.*.distinct' => __('Each category can be chosen once.'),
            'dry_run.boolean'       => __('Preview only must be switched on or off.'),
        ];
    }

    /**
     * The countries this sync covers: the ones typed, or the provider's own.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        return $this->countriesFrom((array) ($this->validated('countries') ?? []));
    }

    /**
     * @param  array<array-key, mixed> $typed
     * @return list<string>
     */
    private function countriesFrom(array $typed): array
    {
        if ($typed !== []) {
            return array_values(array_map('strval', $typed));
        }

        $provider = $this->route('billPaymentProvider');
        $saved    = $provider instanceof BillPaymentProvider && is_array($provider->config) ? (array) ($provider->config['sync_countries'] ?? []) : [];

        return array_values(array_filter(
            array_map(static fn (mixed $country): string => strtoupper(trim((string) $country)), $saved),
            static fn (string $country): bool => preg_match('/^[A-Z]{2}$/', $country) === 1,
        ));
    }

    /**
     * Refused when neither the form nor the provider names a country.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->countriesFrom((array) $this->input('countries', [])) === []) {
                    $validator->errors()->add('countries', __('Name at least one country to sync, or set the provider\'s sync countries first.'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $countries = $this->input('countries');

        if (is_string($countries)) {
            $countries = preg_split('/[\s,;]+/', $countries) ?: [];
        }

        if (is_array($countries)) {
            $this->merge(['countries' => array_values(array_filter(
                array_map(static fn (mixed $country): mixed => is_string($country) ? strtoupper(trim($country)) : $country, $countries),
                static fn (mixed $country): bool => $country !== '',
            ))]);
        } elseif ($countries === null) {
            $this->merge(['countries' => []]);
        }
    }
}
