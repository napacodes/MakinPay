<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\EnsuresPhoneIsUnique;
use App\Support\IdentityRules;
use App\Traits\ReCaptchaValidation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

class MerchantRegisterRequest extends FormRequest
{
    use EnsuresPhoneIsUnique;
    use ReCaptchaValidation;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->addRecaptchaRuleIfConfigured([
            'first_name'       => IdentityRules::name(),
            'last_name'        => IdentityRules::name(),
            'username'         => IdentityRules::username('users'),
            'email'            => IdentityRules::email('users'),
            'business_name'    => IdentityRules::businessName(),
            'business_address' => 'nullable|string|max:255',
            'country'          => IdentityRules::country(),
            'phone'            => 'required|string',
            'password'         => ['required', 'confirmed', Rules\Password::defaults()],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        /* A rejected handle has to say what a handle may contain. */
        return array_merge($this->recaptchaValidationMessages(), IdentityRules::messages());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () use ($validator): void {
            $this->ensureRecaptchaPassed();

            $this->ensurePhoneIsNotAlreadyRegistered($validator);
        });
    }
}
