<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MassPayment;

use App\Support\PhoneIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The app checks one recipient while the customer types it into "Add a
 * payment": the wallet the batch pays from, the recipient as typed, and the
 * country its phone field had picked (`recipient_country`), read by the same
 * rule a draft row uses (PhoneIdentifier::inPickedCountry()).
 */
class PreviewMassPaymentRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => [
                'required',
                'string',
                Rule::exists('wallets', 'uuid')->where(fn ($query) => $query->where('user_id', $this->user()?->id)),
            ],
            'recipient'         => ['required', 'string', 'max:191'],
            'recipient_country' => ['nullable', 'string', 'max:2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'wallet_id.required' => __('Choose the wallet to pay from.'),
            'wallet_id.exists'   => __('The selected wallet is invalid.'),
            'recipient.required' => __('Enter a phone number, an email or a wallet ID.'),
            'recipient.max'      => __('A recipient may be at most :max characters.'),
        ];
    }

    /** The recipient as the draft would read it. */
    public function recipient(): string
    {
        return (string) $this->validated('recipient');
    }

    protected function prepareForValidation(): void
    {
        $recipient = $this->input('recipient');
        $country   = $this->input('recipient_country');

        if (is_string($recipient) && is_string($country)) {
            $this->merge(['recipient' => PhoneIdentifier::inPickedCountry($recipient, $country)]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
