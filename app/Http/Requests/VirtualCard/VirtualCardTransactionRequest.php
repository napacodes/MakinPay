<?php

namespace App\Http\Requests\VirtualCard;

use App\Models\VirtualCard;
use App\Support\AmountEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class VirtualCardTransactionRequest extends FormRequest
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
        $userId = $this->user()?->id;

        return [
            'card_id' => [
                'required',
                'integer',
                Rule::exists('virtual_cards', 'id')->where(
                    fn ($query) => $query->where('user_id', $userId)
                ),
            ],
            // The floor is one unit of the card's own currency, and anything
            // finer than that currency can hold is rejected rather than
            // silently rounded into the ledger. `gt:0` accepted 0.000000001 on
            // a USD card and 0.001 on a JPY one — amounts the ledger cannot
            // represent, so the wallet was debited for something the card
            // never received.
            'amount' => AmountEntry::rules($this->cardCurrency()),
            // Rendered once per top-up form, so a browser resubmit replays the
            // same key and funds the card once. Nullable: the withdraw form
            // shares this request and never sends one, and a page cached from
            // before this shipped still has to work — `prevent.duplicate` on
            // the route is the backstop for those.
            'idempotency_key' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * The currency of the wallet backing the selected card, or null when it
     * cannot be resolved — in which case AmountEntry falls back to the base
     * currency and the manager's own guards still apply.
     */
    private function cardCurrency(): ?string
    {
        $userId = $this->user()?->id;
        $cardId = $this->input('card_id');

        if ($userId === null || $cardId === null || $cardId === '') {
            return null;
        }

        try {
            return VirtualCard::query()
                ->whereKey($cardId)
                ->where('user_id', $userId)
                ->with('wallet.currency:id,code')
                ->first()?->wallet?->currency?->code;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'card_id.exists' => __('The selected virtual card is not available.'),
            'amount.min'     => __('Amount must be greater than zero.'),
            'amount.decimal' => __('Amount has more decimal places than this currency supports.'),
        ];
    }
}
