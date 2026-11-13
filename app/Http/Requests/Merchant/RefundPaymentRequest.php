<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates a Merchant API refund request.
 *
 * `amount` is optional — omit it for a full refund, or pass a smaller value
 * for a partial refund. `ref_refund` is the merchant's own reference and, when
 * provided, makes the call idempotent (the same reference never refunds twice).
 */
class RefundPaymentRequest extends FormRequest
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
            'trx_id'     => 'required|string|max:120',
            'amount'     => 'nullable|numeric|gt:0',
            'reason'     => 'nullable|string|max:255',
            'ref_refund' => 'nullable|string|max:60',
        ];
    }

    public function messages(): array
    {
        return [
            'trx_id.required' => __('The original transaction id (trx_id) is required.'),
            'amount.gt'       => __('Refund amount must be greater than zero.'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
