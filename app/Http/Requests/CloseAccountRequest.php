<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's own confirmation for closing their account. Whether the
 * password and the code are right is AccountClosureService's call, so the
 * website and the app check them the same way.
 */
class CloseAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'close_password'        => ['required', 'string', 'max:255'],
            'close_two_factor_code' => [$this->user()?->two_factor_enabled ? 'required' : 'nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'close_password.required'        => __('Please enter your current password.'),
            'close_two_factor_code.required' => __('Please enter the code from your authenticator app.'),
        ];
    }
}
