<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend;

use App\Services\Dashboard\DashboardSla;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The SLA targets behind the dashboard's Needs your action panel.
 *
 * Bounded at one hour to thirty days: below an hour every queue reads as late
 * the moment an item arrives, and above a month the alert stops meaning
 * anything. The warning threshold is a share of the target, so it has to stay
 * strictly inside it.
 */
class UpdateDashboardSlaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can('dashboard-sla-manage') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (DashboardSla::TARGETS as $target) {
            $rules[$target['setting']] = ['required', 'integer', 'min:1', 'max:720'];
        }

        $rules[DashboardSla::WARNING_SETTING] = ['required', 'integer', 'min:10', 'max:99'];
        /*
         * Optional, not required: the modal always posts it (a hidden 0 sits
         * in front of the checkbox), but a caller sending the older payload
         * must keep working and simply leave the switch as it is.
         */
        $rules[DashboardSla::ALERTS_SETTING] = ['sometimes', 'boolean'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (DashboardSla::TARGETS as $target) {
            $key = $target['setting'];

            $messages[$key.'.required'] = __('Every queue needs a target.');
            $messages[$key.'.integer']  = __('A target must be a whole number of hours.');
            $messages[$key.'.min']      = __('A target must be at least :min hour.');
            $messages[$key.'.max']      = __('A target must not exceed :max hours (30 days).');
        }

        $warning = DashboardSla::WARNING_SETTING;

        $messages[$warning.'.required'] = __('The warning threshold is required.');
        $messages[$warning.'.integer']  = __('The warning threshold must be a whole percentage.');
        $messages[$warning.'.min']      = __('The warning threshold must be at least :min%.');
        $messages[$warning.'.max']      = __('The warning threshold must stay below the target, so at most :max%.');

        $messages[DashboardSla::ALERTS_SETTING.'.boolean'] = __('The notification switch must be on or off.');

        return $messages;
    }
}
