<?php

namespace App\Http\Requests\Frontend;

use App\Constants\CurrencyRole;
use App\Exceptions\NotifyErrorException;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVoucherRequest extends FormRequest
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
            'wallet_id' => [
                'required',
                'integer',
                Rule::exists('wallets', 'id')->where(
                    fn ($query) => $query
                        ->where('user_id', $userId)
                        ->where('status', true)
                ),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('wallet_id')) {
                return;
            }

            $wallet = Wallet::query()
                ->with(['currency.activeRoles'])
                ->where('user_id', $this->user()?->id)
                ->find($this->integer('wallet_id'));

            try {
                app(WalletService::class)->assertOperational($wallet, CurrencyRole::VOUCHER);
            } catch (NotifyErrorException $exception) {
                $validator->errors()->add('wallet_id', $exception->getMessage());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'wallet_id.required' => __('Please select a wallet.'),
            'wallet_id.exists'   => __('Select one of your active voucher wallets.'),
            'amount.required'    => __('Please enter a voucher amount.'),
            'amount.gt'          => __('Voucher amount must be greater than zero.'),
        ];
    }
}
