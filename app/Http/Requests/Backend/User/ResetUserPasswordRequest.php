<?php

namespace App\Http\Requests\Backend\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method'   => ['required', 'string', Rule::in(['link', 'manual'])],
            'password' => [
                Rule::requiredIf(fn (): bool => $this->input('method') === 'manual'),
                'nullable',
                'string',
                Password::min(8)->letters()->numbers(),
                'max:72',
            ],
            'force_change' => ['nullable', 'boolean'],
            'sign_out_all' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => __('Enter the temporary password, or send a reset link instead.'),
            'password.min'      => __('The temporary password needs at least 8 characters.'),
        ];
    }
}
