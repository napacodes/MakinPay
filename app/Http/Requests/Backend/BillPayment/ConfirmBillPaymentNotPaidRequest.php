<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Services\BillPayment\BillPaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin confirms that a provider payment in review was not charged at the
 * provider, and the customer is refunded the full total.
 *
 * The reason is shown to the customer on the receipt, so it is kept to the
 * column's 500 characters. The note records how the admin confirmed it with
 * the provider and never leaves the admin screens. The checkbox is the
 * explicit statement that the check was made. Whether the payment may be
 * refunded at all (in review, checked recently, no final answer) is
 * BillPaymentService's decision, not this request's.
 */
class ConfirmBillPaymentNotPaidRequest extends FormRequest
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
            'reason'              => ['required', 'string', 'max:500'],
            'note'                => ['required', 'string', 'min:'.BillPaymentService::CONFIRM_NOT_PAID_NOTE_MIN, 'max:1000'],
            'confirm_not_charged' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required'              => __('Enter the reason the customer will see.'),
            'reason.max'                   => __('The reason may not be longer than :max characters.'),
            'note.required'                => __('Write how you confirmed with the provider that this payment was not charged.'),
            'note.min'                     => __('Write a note of at least :min characters on how you confirmed the payment was not charged.'),
            'note.max'                     => __('The note may not be longer than :max characters.'),
            'confirm_not_charged.accepted' => __('Confirm you checked with the provider that this payment was not charged.'),
        ];
    }
}
