<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MarketplaceCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'amount'    => ['required', 'numeric', 'decimal:0,8', 'gt:0', 'max:99999999'],
            'remarks'   => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => __('The payment amount must be greater than zero.'),
        ];
    }
}
