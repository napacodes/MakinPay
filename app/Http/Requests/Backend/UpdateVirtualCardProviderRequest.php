<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVirtualCardProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:50'],
            'payment_gateway_id'   => ['nullable', 'integer', 'exists:payment_gateways,id'],
            'supported_currencies' => ['nullable', 'string', 'max:1000', 'regex:/^[A-Za-z0-9, ]*$/'],
            'supported_countries'  => ['nullable', 'string', 'max:1000', 'regex:/^[A-Za-z, ]*$/'],
            'issue_fee'            => ['required', 'numeric', 'min:0'],
            'issue_fee_pct'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_balance'          => ['nullable', 'numeric', 'min:0'],
            'capabilities'         => ['nullable', 'array'],
            'capabilities.*'       => ['string', Rule::in(array_keys((array) config('virtual_card.default_capabilities', [])))],
            'status'               => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supported_currencies.regex' => __('Use comma-separated currency codes, such as USD, EUR.'),
            'supported_countries.regex'  => __('Use comma-separated country codes, such as US, GB.'),
        ];
    }
}
