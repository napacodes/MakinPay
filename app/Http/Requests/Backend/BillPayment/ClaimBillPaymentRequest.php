<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An operator marks a payment as theirs while they pay it at the biller.
 *
 * `take_over` is the explicit choice to take a payment another operator
 * claimed recently. Without it, their claim stands.
 */
class ClaimBillPaymentRequest extends FormRequest
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
            'take_over' => ['nullable', 'boolean'],
        ];
    }

    public function takesOver(): bool
    {
        return $this->boolean('take_over');
    }
}
