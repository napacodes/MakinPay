<?php

declare(strict_types=1);

namespace App\Http\Requests\MassPayment;

use App\Models\ScheduledPayment;
use App\Services\MassPayment\MassPaymentSchedules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Pay this batch again": the batch whose lines to keep, how often, from
 * when, what to do when the wallet is short, and the wallet PIN that is
 * the mandate every run pays under.
 */
class StoreMassPaymentScheduleRequest extends FormRequest
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
            'batch'        => ['required', 'uuid'],
            'name'         => ['nullable', 'string', 'max:'.MassPaymentSchedules::MAX_NAME],
            'cadence'      => ['required', Rule::in(['once', 'weekly', 'monthly'])],
            'weekday'      => ['required_if:cadence,weekly', 'nullable', 'integer', 'min:1', 'max:7'],
            'day_of_month' => ['required_if:cadence,monthly', 'nullable', 'integer', 'min:1', 'max:31'],
            'starts_on'    => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on'      => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'weekend_rule' => ['nullable', Rule::in(ScheduledPayment::WEEKEND_RULES)],
            'low_balance'  => ['nullable', Rule::in(['skip', 'retry'])],
            'remind'       => ['nullable', 'boolean'],
            'pin'          => ['required', 'string', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'batch.required'           => __('Choose the batch to pay again.'),
            'starts_on.required'       => __('Pick the first day it should be paid.'),
            'starts_on.after_or_equal' => __('The first day cannot be in the past.'),
            'weekday.required_if'      => __('Pick the weekday.'),
            'day_of_month.required_if' => __('Pick the day of the month.'),
            'pin.required'             => __('Enter your wallet PIN: it is the mandate every run pays under.'),
        ];
    }

    /**
     * The schedule as MassPaymentSchedules::create() takes it.
     *
     * @return array<string, mixed>
     */
    public function schedule(): array
    {
        $validated = $this->validated();

        return [
            'cadence'      => $validated['cadence'],
            'weekdays'     => $validated['cadence'] === 'weekly' ? [(int) $validated['weekday']] : null,
            'day_of_month' => $validated['cadence'] === 'monthly' ? (int) $validated['day_of_month'] : null,
            'starts_on'    => $validated['starts_on'],
            'ends_on'      => $validated['ends_on']      ?? null,
            'weekend_rule' => $validated['weekend_rule'] ?? 'none',
            'low_balance'  => $validated['low_balance']  ?? 'retry',
            'remind'       => (bool) ($validated['remind'] ?? false),
        ];
    }
}
