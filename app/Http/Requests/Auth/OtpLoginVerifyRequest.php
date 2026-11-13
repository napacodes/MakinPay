<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ReadsLoginCountry;
use App\Support\HomeCountry;
use App\Support\SignInThrottle;
use App\Traits\ReCaptchaValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Signing in with the code that arrived by SMS (GF9).
 *
 * The throttle keys are the password form's own (LoginRequest), so a wrong
 * code and a wrong password draw on one lockout budget for the number:
 * alternating between the two is no way round it. Behind the site's
 * reCAPTCHA on the web, as every password attempt is; the API's subclass
 * turns it off.
 */
class OtpLoginVerifyRequest extends FormRequest
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
            'code'  => ['required', 'string', 'max:10'],
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
            'code.required'  => __('Enter the code we sent you.'),
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

    /**
     * @return list<string>
     */
    public function throttleKeys(): array
    {
        $loginKey = Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());

        return SignInThrottle::keys($loginKey, $this->string('login')->toString(), HomeCountry::code(), (string) $this->ip());
    }

    protected function wantsRecaptcha(): bool
    {
        return true;
    }
}
