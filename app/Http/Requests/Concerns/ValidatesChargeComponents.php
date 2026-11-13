<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Validation rules for the fixed + percent charge components shared by the
 * deposit and withdraw method forms.
 *
 * Every component is nullable: a method priced purely as a percentage leaves
 * the fixed field blank, and "no floor"/"no ceiling" are meaningfully
 * different from zero.
 */
trait ValidatesChargeComponents
{
    /**
     * @return array<string, string>
     */
    protected function chargeComponentRules(): array
    {
        $rules = [];

        foreach (['charge', 'user_charge', 'merchant_charge', 'agent_charge'] as $family) {
            $rules[$family.'_fixed']   = 'nullable|numeric|min:0';
            $rules[$family.'_percent'] = 'nullable|numeric|min:0|max:100';
            $rules[$family.'_min']     = 'nullable|numeric|min:0';
            $rules[$family.'_max']     = 'nullable|numeric|min:0|gte:'.$family.'_min';
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function chargeComponentMessages(): array
    {
        $messages = [];

        foreach (['charge', 'user_charge', 'merchant_charge', 'agent_charge'] as $family) {
            $messages[$family.'_percent.max'] = __('A percentage charge cannot exceed 100%.');
            $messages[$family.'_max.gte']     = __('The maximum charge must be greater than or equal to the minimum charge.');
        }

        return $messages;
    }
}
