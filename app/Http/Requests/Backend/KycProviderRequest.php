<?php

namespace App\Http\Requests\Backend;

use App\Models\KycProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $routeProvider = $this->route('provider');
        $providerId    = $routeProvider instanceof KycProvider ? $routeProvider->id : $routeProvider;

        return [
            'code'          => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/', Rule::unique('kyc_providers', 'code')->ignore($providerId)],
            'name'          => ['required', 'string', 'max:120'],
            'driver'        => ['required', 'string', Rule::in(array_keys((array) config('kyc.drivers', []))), Rule::unique('kyc_providers', 'driver')->ignore($providerId)],
            'countries'     => ['required', 'array', 'min:1'],
            'countries.*'   => ['string', 'regex:/^(\*|[A-Za-z]{2})$/'],
            'priority'      => ['required', 'integer', 'min:0', 'max:1000'],
            'mode'          => ['required', Rule::in(['sandbox', 'live'])],
            'credentials'   => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'status'        => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex'         => __('Use lowercase letters, numbers and dashes only.'),
            'driver.unique'      => __('This provider is already configured — edit its existing card instead.'),
            'countries.required' => __('Assign at least one country (use * for any country).'),
            'countries.*.regex'  => __('Each country must be a 2-letter code or * for any country.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $countries = collect($this->input('countries', []))
            ->map(fn (mixed $code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $credentials = collect($this->input('credentials', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->all();

        $this->merge([
            'code'        => strtolower(trim((string) $this->input('code'))),
            'countries'   => $countries,
            'credentials' => $credentials ?: null,
            'status'      => $this->boolean('status'),
        ]);
    }
}
