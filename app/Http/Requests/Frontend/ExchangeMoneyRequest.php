<?php

namespace App\Http\Requests\Frontend;

use App\Support\AmountEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExchangeMoneyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getAuthIdentifier();

        return [
            'source_wallet_id' => [
                'required',
                'string',
                Rule::exists('wallets', 'uuid')->where('user_id', $userId),
            ],
            'destination_wallet_id' => [
                'required',
                'string',
                'different:source_wallet_id',
                Rule::exists('wallets', 'uuid')->where('user_id', $userId),
            ],
            // The amount is typed in the source wallet's own currency, so the
            // floor is one unit of that currency. A flat 0.01 rejected every
            // BTC exchange below roughly six hundred dollars.
            'amount' => AmountEntry::rules($this->sourceCurrency()),
        ];
    }

    /**
     * The currency the amount field is denominated in — the source wallet's,
     * falling back to base when the wallet cannot be resolved. The controller
     * re-resolves it under a lock and refuses an unusable rate, so a wrong
     * guess here can only affect the error message, never the conversion.
     */
    public function sourceCurrency(): ?string
    {
        return AmountEntry::currencyForWalletUuid(
            $this->input('source_wallet_id'),
            $this->user()?->getAuthIdentifier(),
        ) ?? siteCurrency();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_wallet_id.exists'         => __('The selected source wallet is unavailable.'),
            'destination_wallet_id.exists'    => __('The selected destination wallet is unavailable.'),
            'destination_wallet_id.different' => __('The source and destination wallets must be different.'),
        ];
    }
}
