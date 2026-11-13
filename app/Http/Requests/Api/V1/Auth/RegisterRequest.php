<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\EnsuresPhoneIsUnique;
use App\Support\IdentityRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

/**
 * Registration from the app.
 *
 * The same identity rules the three web forms use, so a handle the browser
 * would have rejected is not quietly accepted from a phone.
 *
 * Two differences, both forced by the client. The referral code arrives as a
 * field because a phone has no cookie for HandleReferralLinks to have set. And
 * reCAPTCHA is not asked for, for the reason given on the login request.
 */
class RegisterRequest extends FormRequest
{
    use EnsuresPhoneIsUnique;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role'          => ['required', Rule::in(UserRole::values())],
            'first_name'    => IdentityRules::name(),
            'last_name'     => IdentityRules::name(),
            'username'      => IdentityRules::username('users'),
            'email'         => IdentityRules::email('users'),
            'country'       => IdentityRules::country(),
            'phone'         => 'required|string',
            'password'      => ['required', 'confirmed', Rules\Password::defaults()],
            'referral_code' => ['nullable', 'string', 'max:64'],

            // Required only for a merchant, matching MerchantRegisterRequest.
            'business_name'    => IdentityRules::businessName($this->isMerchant()),
            'business_address' => ['nullable', 'string', 'max:255'],

            'device'             => ['sometimes', 'array'],
            'device.id'          => ['nullable', 'string', 'max:64'],
            'device.name'        => ['nullable', 'string', 'max:120'],
            'device.platform'    => ['nullable', 'string', 'max:20'],
            'device.app_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return IdentityRules::messages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () use ($validator): void {
            $this->ensurePhoneIsNotAlreadyRegistered($validator);
        });
    }

    public function role(): UserRole
    {
        return UserRole::from((string) $this->input('role'));
    }

    /**
     * @return array{id: ?string, name: ?string, platform: ?string, app_version: ?string}
     */
    public function device(): array
    {
        return [
            'id'          => $this->input('device.id'),
            'name'        => $this->input('device.name'),
            'platform'    => $this->input('device.platform'),
            'app_version' => $this->input('device.app_version'),
        ];
    }

    private function isMerchant(): bool
    {
        return $this->input('role') === UserRole::MERCHANT->value;
    }
}
