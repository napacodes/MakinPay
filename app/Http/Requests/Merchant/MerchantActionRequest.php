<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class MerchantActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            // Total fee = fixed + (amount x percentage), clamped to any floor
            // and ceiling — the Stripe-style shape every other fee now uses.
            'fee'       => ['required_if:action,approve', 'nullable', 'numeric', 'min:0', 'max:100'],
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'fee_min'   => ['nullable', 'numeric', 'min:0'],
            'fee_max'   => ['nullable', 'numeric', 'min:0', 'gte:fee_min'],
            'action'    => ['required', 'in:approve,reject,disable,enable'],
            // Required to reject, never to approve. The rejection template
            // interpolates {rejection_reason}; leaving this optional is what
            // sent merchants "Reason: No reason provided."
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fee.required_if'              => __('Set the transaction fee before approving this merchant.'),
            'fee.max'                      => __('A percentage fee cannot be above 100.'),
            'fee_max.gte'                  => __('The maximum fee cannot be below the minimum fee.'),
            'rejection_reason.required_if' => __('Give a reason — it is sent to the merchant so they know what to fix.'),
            'rejection_reason.max'         => __('Keep the reason under 500 characters.'),
        ];
    }
}
