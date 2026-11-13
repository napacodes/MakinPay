<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycProviderConnectionTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'driver'        => ['required', 'string', Rule::in(array_keys((array) config('kyc.drivers', [])))],
            'mode'          => ['required', Rule::in(['sandbox', 'live'])],
            'provider_id'   => ['nullable', 'integer', 'exists:kyc_providers,id'],
            'credentials'   => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'driver.required' => __('Choose a KYC provider before testing the connection.'),
            'driver.in'       => __('This KYC provider cannot be tested.'),
            'mode.required'   => __('Choose the provider environment.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $credentials = collect($this->input('credentials', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->all();

        $this->merge([
            'driver'      => trim((string) $this->input('driver')),
            'credentials' => $credentials,
        ]);
    }
}
