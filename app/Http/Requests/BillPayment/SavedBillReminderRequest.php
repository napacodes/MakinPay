<?php

declare(strict_types=1);

namespace App\Http\Requests\BillPayment;

use App\Models\SavedBill;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BP3: the day of the month a customer wants to be reminded of one of "My
 * bills", or nothing to stop. Shared by the website form and the app.
 */
class SavedBillReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'remind_day' => ['nullable', 'integer', 'min:'.SavedBill::REMIND_DAY_MIN, 'max:'.SavedBill::REMIND_DAY_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $range = __('Choose a day of the month, 1 to 31, or none.');

        return [
            'remind_day.integer' => $range,
            'remind_day.min'     => $range,
            'remind_day.max'     => $range,
        ];
    }

    /** The day asked for, or null to stop reminding. */
    public function day(): ?int
    {
        $day = $this->validated('remind_day');

        return $day === null || $day === '' ? null : (int) $day;
    }
}
