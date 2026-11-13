<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\MassPayment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin closes a mass payment held for a ledger anomaly after reconciling
 * it by hand. No money moves, so the note saying how it was reconciled is
 * the only record of what was done; it is required.
 */
class CloseMassPaymentAfterReviewRequest extends FormRequest
{
    public const NOTE_MAX_LENGTH = 2000;

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
            'note' => ['required', 'string', 'max:'.self::NOTE_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('Add a note saying how the batch was reconciled.'),
            'note.max'      => __('The note may not be longer than :max characters.'),
        ];
    }
}
