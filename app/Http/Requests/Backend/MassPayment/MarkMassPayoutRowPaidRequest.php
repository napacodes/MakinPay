<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\MassPayment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin records one payout line as paid, with the reference of the
 * payment that was made. The service refuses a reference already recorded on
 * another line of the same driver.
 */
class MarkMassPayoutRowPaidRequest extends FormRequest
{
    public const REFERENCE_MAX_LENGTH = 128;

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
            'reference' => ['required', 'string', 'max:'.self::REFERENCE_MAX_LENGTH, 'regex:/^[^\p{Cc}]+$/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference.required' => __('Enter the reference of the payment that was made.'),
            'reference.max'      => __('The reference may not be longer than :max characters.'),
            'reference.regex'    => __('The reference may not contain control characters.'),
        ];
    }
}
