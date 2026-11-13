<?php

namespace App\Http\Requests\Auth;

use App\Traits\ReCaptchaValidation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by the user, agent, and merchant forgot-password screens, mirroring
 * how LoginRequest serves all three portals.
 */
class PasswordResetLinkRequest extends FormRequest
{
    use ReCaptchaValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->addRecaptchaRuleIfConfigured([
            'email' => ['required', 'email'],
        ]);
    }

    public function messages(): array
    {
        return $this->recaptchaValidationMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (): void {
            $this->ensureRecaptchaPassed();
        });
    }
}
