<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\Concerns\ResolvesOwnWalletUuid;
use App\Support\AmountEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgentCashOutRequest extends FormRequest
{
    use ResolvesOwnWalletUuid;

    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('agent');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_id' => [
                'required',
                Rule::exists('agents', 'id')->where('user_id', (int) $this->user()?->id),
            ],
            'wallet_id' => [
                'required',
                Rule::exists('wallets', 'id')->where('user_id', (int) $this->user()?->id),
            ],
            'customer'     => ['required', 'string', 'max:255'],
            'amount'       => AmountEntry::rules($this->operationCurrency()),
            'customer_otp' => ['required', 'digits:6'],
            'note'         => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The currency the amount is denominated in - the operation currency,
     * which is the wallet this request names.
     *
     * A flat 'min:0.01' floored every amount at one cent, which is not the
     * smallest unit of anything in particular: in BTC it refused every
     * operation below roughly six hundred dollars, and in a currency with no
     * minor unit it invited a fraction the ledger would round away.
     * AmountEntry derives both the floor and the precision from the currency,
     * the way the send, withdraw, deposit and exchange forms already do.
     *
     * Falls back to the base currency when the wallet cannot be resolved; the
     * service re-reads the wallet anyway, so a wrong guess here can only
     * affect the error message.
     */
    public function operationCurrency(): ?string
    {
        return AmountEntry::currencyForWalletId(
            $this->input('wallet_id'),
            $this->user()?->getAuthIdentifier(),
        ) ?? siteCurrency();
    }
}
