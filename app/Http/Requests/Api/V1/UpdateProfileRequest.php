<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use App\Http\Requests\Concerns\EnsuresPhoneIsUnique;
use App\Support\IdentityRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Editing the account from the app.
 *
 * Every field is `sometimes`, so a client can send one changed value rather
 * than echoing the whole profile back - and so an omitted field is never read
 * as "clear this".
 *
 * phone is checked for uniqueness in the form it is STORED in - formatted
 * against the account's registered country, as sign-up stores it - because
 * users.phone has a unique index and a check against the raw typing lets
 * 01712-345678 past a stored +8801712345678 and into an integrity error.
 */
class UpdateProfileRequest extends FormRequest
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
        $id = $this->user()->id;

        return [
            'first_name'       => ['sometimes', ...IdentityRules::name(false)],
            'last_name'        => ['sometimes', ...IdentityRules::name(false)],
            'username'         => ['sometimes', ...IdentityRules::username('users', $id)],
            'email'            => ['sometimes', ...IdentityRules::email('users', $id)],
            'business_name'    => ['sometimes', ...IdentityRules::businessName(false)],
            'business_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender'           => ['sometimes', 'nullable', new Enum(Gender::class)],
            'birthday'         => ['sometimes', 'nullable', 'date', 'before:today'],
            'phone'            => ['sometimes', 'nullable', 'string', 'max:32'],
            'country'          => ['sometimes', 'nullable', 'string', 'size:2'],
            'state'            => ['sometimes', 'nullable', 'string', 'max:120'],
            'city'             => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code'      => ['sometimes', 'nullable', 'string', 'max:32'],
            'address'          => ['sometimes', 'nullable', 'string', 'max:255'],

            // Presentation only, and restricted to an active currency so a
            // disabled one cannot be pinned.
            'monthly_statement_email' => ['sometimes', 'boolean'],
            'display_currency'        => [
                'sometimes', 'nullable', 'string',
                Rule::exists('currencies', 'code')->where('status', true),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () use ($validator): void {
            $this->ensurePhoneIsNotHeldByAnotherAccount($validator, $this->user());
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return IdentityRules::messages();
    }
}
