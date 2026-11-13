<?php

namespace App\Http\Requests\Frontend;

use App\Support\AmountEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateCryptoAddressRequest extends FormRequest
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
        return [
            'coin'    => ['required', 'string', Rule::in(['USDT', 'USDC'])],
            'network' => ['required', 'string', Rule::in(['erc20', 'polygon', 'base', 'solana', 'trc20', 'bep20'])],
            // The amount is the quantity of the coin being sent on chain, so
            // the floor is one unit of that coin. USDT and USDC carry six
            // decimals, and 0.01 was two.
            'amount' => AmountEntry::rules($this->input('coin')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'coin.required'    => __('Please select a coin.'),
            'coin.in'          => __('The selected coin is not supported.'),
            'network.required' => __('Please select a network.'),
            'network.in'       => __('The selected network is not supported.'),
            'amount.required'  => __('Please enter an amount.'),
            'amount.min'       => __('The amount must be greater than zero.'),
        ];
    }
}
