<?php

namespace App\Http\Requests\SendMoney;

use App\Support\AmountEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendMoneyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required'],
            'wallet_id' => [
                'required',
                Rule::exists('wallets', 'id')->where(fn ($query) => $query->where('user_id', $this->user()?->id)),
            ],
            // Typed in the sending wallet's own currency, so the floor is one
            // unit of that currency. A flat 0.01 rejected every BTC transfer
            // below roughly six hundred dollars.
            'amount' => AmountEntry::rules($this->walletCurrency()),
            'note'   => ['nullable', 'max:255'],

            /*
             * Demanded only from a customer who has actually set a PIN, which
             * is what keeps this from breaking the form for everyone else.
             * The service checks it again - and is the thing that enforces
             * it; this rule exists so the refusal reads as a field error on
             * the form rather than as a flashed exception.
             */
            'wallet_pin' => [
                Rule::requiredIf(fn (): bool => (bool) $this->user()?->hasWalletPin()),
                'nullable',
                'digits:6',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient.required'  => __('Please provide a recipient.'),
            'wallet_id.exists'    => __('The selected wallet is invalid.'),
            'amount.min'          => __('The amount must be at least :min.'),
            'note.max'            => __('The note must not exceed :max characters.'),
            'wallet_pin.required' => __('Your wallet PIN is required for this action.'),
            'wallet_pin.digits'   => __('The wallet PIN must be 6 digits.'),

        ];
    }

    /**
     * The currency the amount field is denominated in — the selected wallet's,
     * falling back to base when it cannot be resolved. The controller resolves
     * the wallet again and refuses an unusable rate, so a wrong guess here can
     * only affect the error message.
     */
    public function walletCurrency(): ?string
    {
        return AmountEntry::currencyForWalletId(
            $this->input('wallet_id'),
            $this->user()?->getAuthIdentifier(),
        ) ?? siteCurrency();
    }
}
