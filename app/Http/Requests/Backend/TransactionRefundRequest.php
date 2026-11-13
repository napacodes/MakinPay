<?php

namespace App\Http\Requests\Backend;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TransactionRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can('transaction-refund') === true;
    }

    /**
     * The ceiling is deliberately not validated here.
     *
     * `MerchantRefundService::refund()` compares the request against
     * `refundableAmount()` while holding a `lockForUpdate()` on the row, so
     * that check cannot go stale between two concurrent refunds. A `max:` rule
     * evaluated out here would read the balance before the lock is taken and
     * would let two simultaneous requests each pass, so the limit is left to
     * the service — which surfaces it to the client as a 422 either way.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt'  => __('Refund amount must be greater than zero.'),
            'reason.max' => __('Reason may not be longer than 255 characters.'),
        ];
    }
}
