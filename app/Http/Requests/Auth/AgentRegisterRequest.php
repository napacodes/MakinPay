<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\EnsuresPhoneIsUnique;
use App\Support\IdentityRules;
use App\Traits\ReCaptchaValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Validator;

class AgentRegisterRequest extends FormRequest
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
            'first_name' => IdentityRules::name(),
            'last_name'  => IdentityRules::name(),
            'username'   => IdentityRules::username('users'),
            'email'      => IdentityRules::email('users'),
            'country'    => IdentityRules::country(),
            'phone'      => 'required|string',
            'password'   => ['required', 'confirmed', Rules\Password::defaults()],
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
        $validator->after(function (Validator $validator): void {
            // Ahead of the country checks below, which return early.
            $this->ensureRecaptchaPassed();

            if ($validator->errors()->has('country')) {
                return;
            }

            if ($this->countryData() === null) {
                $validator->errors()->add('country', __('The selected country is invalid.'));

                return;
            }

            /*
             * The country policy is settled BEFORE the phone is looked at.
             * The uniqueness check skips itself when the country already has
             * an error, and it can only do that if the error is there yet -
             * running it first meant a customer in a country the programme is
             * closed in was also told their phone was taken, under a refusal
             * they have to fix first and which makes the phone moot.
             */
            $allowedCountries = $this->allowedCountries();
            if ($allowedCountries !== [] && ! in_array($this->countryCode(), $allowedCountries, true)) {
                $validator->errors()->add(
                    'country',
                    __('The Agent program is not available in :country yet.', ['country' => $this->countryCode()])
                );
            }

            $this->ensurePhoneIsNotAlreadyRegistered($validator);
        });
    }

    public function countryCode(): string
    {
        return strtoupper((string) $this->input('country', ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function countryData(): ?array
    {
        $countryData = getCountryByCode($this->countryCode());

        return is_array($countryData) ? $countryData : null;
    }

    /**
     * @return array<int, string>
     */
    private function allowedCountries(): array
    {
        $raw = (string) setting('agent_allowed_countries', '');
        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->values()
            ->all();
    }
}
