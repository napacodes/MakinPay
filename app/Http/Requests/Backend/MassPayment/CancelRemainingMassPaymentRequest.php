<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\MassPayment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin cancels the lines of a mass payment that are not paid yet; each
 * one is refunded to the sender's wallet.
 *
 * The reason is recorded on the batch's audit trail, so it is required. The
 * service refuses a blank one again under the batch lock.
 */
class CancelRemainingMassPaymentRequest extends FormRequest
{
    public const REASON_MAX_LENGTH = 500;

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
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Give a reason for cancelling the remaining rows.'),
            'reason.max'      => __('The reason may not be longer than :max characters.'),
        ];
    }
}
