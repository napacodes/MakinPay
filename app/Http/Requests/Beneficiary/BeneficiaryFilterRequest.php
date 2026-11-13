<?php

namespace App\Http\Requests\Beneficiary;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BeneficiaryFilterRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.max' => __('Search text may not exceed :max characters.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => trim((string) $this->query('search')) ?: null,
        ]);
    }
}
