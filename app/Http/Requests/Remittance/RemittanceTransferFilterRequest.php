<?php

namespace App\Http\Requests\Remittance;

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
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q'      => ['nullable', 'string', 'max:120'],
            'status' => [
                'nullable',
                Rule::in(array_merge(
                    ['all'],
                    array_map(fn (RemittanceStatus $status): string => $status->value, RemittanceStatus::cases()),
                )),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max'     => __('Search text may not exceed :max characters.'),
            'status.in' => __('Selected remittance status is invalid.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q'      => trim((string) $this->query('q')) ?: null,
            'status' => strtolower(trim((string) $this->query('status'))) ?: null,
        ]);
    }
}
