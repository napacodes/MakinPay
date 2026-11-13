<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\MassPayment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin records one payout line as not paid, with a reason; the line is
 * refunded to the owner's wallet. The service decides whether the line may be
 * resolved this way at all.
 */
class MarkMassPayoutRowNotPaidRequest extends FormRequest
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
            'reason.required' => __('Give a reason for marking this payout as not paid.'),
            'reason.max'      => __('The reason may not be longer than :max characters.'),
        ];
    }
}
