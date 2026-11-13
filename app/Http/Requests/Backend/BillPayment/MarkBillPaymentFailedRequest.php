<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An operator records that a bill could not be paid, and the customer is
 * refunded the full total.
 *
 * The reason is shown to the customer on the receipt and in the failure
 * notice, so it is required and kept to the column's 500 characters. The
 * internal note never leaves the admin screens.
 */
class MarkBillPaymentFailedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason'     => ['required', 'string', 'max:500'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Enter the reason the customer will see.'),
            'reason.max'      => __('The reason may not be longer than :max characters.'),
            'admin_note.max'  => __('The internal note may not be longer than :max characters.'),
        ];
    }
}
