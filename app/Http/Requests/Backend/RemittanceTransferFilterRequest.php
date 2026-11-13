<?php

namespace App\Http\Requests\Backend;

use App\Enums\RemittanceStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RemittanceTransferFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search'         => ['nullable', 'string', 'max:120'],
            'status'         => ['nullable', Rule::enum(RemittanceStatus::class)],
            'gateway'        => ['nullable', 'string', Rule::in(array_keys((array) config('remittance.gateways', [])))],
            'dispatch_state' => [
                'nullable',
                'string',
                Rule::in(array_keys((array) config('remittance.dispatch_states', []))),
            ],
            'corridor_id' => ['nullable', 'integer', Rule::exists('remittance_corridors', 'id')],
            'date_from'   => ['nullable', 'date_format:Y-m-d'],
            'date_to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page'    => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.enum'            => __('Selected remittance status is invalid.'),
            'gateway.in'             => __('Selected payout gateway is invalid.'),
            'dispatch_state.in'      => __('Selected dispatch state is invalid.'),
            'corridor_id.exists'     => __('Selected remittance corridor no longer exists.'),
            'date_from.date_format'  => __('The start date must use YYYY-MM-DD format.'),
            'date_to.date_format'    => __('The end date must use YYYY-MM-DD format.'),
            'date_to.after_or_equal' => __('The end date must be on or after the start date.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search'  => trim((string) $this->input('search')) ?: null,
            'gateway' => strtolower(trim((string) $this->input('gateway'))) ?: null,
        ]);
    }
}
