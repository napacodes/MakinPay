<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ReadsLoginCountry;
use App\Traits\ReCaptchaValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Asking for a sign-in code by SMS (GF9): the phone number on the account.
 *
 * Behind the site's reCAPTCHA on the web, like the password form, since this
 * is the step that makes the site send a text. The API's subclass turns it
 * off: a phone cannot produce a token, and the app's budget is carried by the
 * same limits instead.
 */
class OtpLoginSendRequest extends FormRequest
{
    use ReadsLoginCountry;
    use ReCaptchaValidation;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [
            'login' => ['required', 'string', 'max:32'],
        ];

        return $this->wantsRecaptcha() ? $this->addRecaptchaRuleIfConfigured($rules) : $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge([
            'login.required' => __('Enter the phone number on your account.'),
            'login.max'      => __('Enter the phone number on your account.'),
        ], $this->recaptchaValidationMessages());
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->wantsRecaptcha()) {
            return;
        }

        $validator->after(function (): void {
            $this->ensureRecaptchaPassed();
        });
    }

    protected function wantsRecaptcha(): bool
    {
        return true;
    }
}
