<?php

namespace App\Http\Requests\Language;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TranslationUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'language'  => ['required', 'string', 'max:12', 'exists:languages,code'],
            'group'     => ['required', 'string', 'max:120'],
            'key'       => ['required', 'string', 'max:1000'],
            'value'     => ['nullable', 'string', 'max:20000'],
            'namespace' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'language.exists' => __('The selected language is not available.'),
            'key.required'    => __('A translation key is required.'),
            'value.string'    => __('The translation must be text.'),
        ];
    }
}
