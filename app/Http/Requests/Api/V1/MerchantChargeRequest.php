<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A counter charge: the merchant names one of their own shops and an
 * amount, and gets a checkout reference for the customer to scan.
 */
class MerchantChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'merchant_id'   => ['required', 'integer', Rule::exists('merchants', 'id')->where('user_id', $this->user()?->id)],
            'amount'        => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'description'   => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'merchant_id.exists' => __('That shop is not one of yours.'),
            'amount.gt'          => __('Enter an amount above zero.'),
        ];
    }
}
