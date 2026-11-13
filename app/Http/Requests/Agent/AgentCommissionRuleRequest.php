<?php

namespace App\Http\Requests\Agent;

use App\Enums\AgentCommissionRuleType;
use App\Enums\AgentOperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class AgentCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:120',
            'status'           => 'nullable|boolean',
            'applies_globally' => 'nullable|boolean',
            'priority'         => 'required|integer|min:1|max:999',
            'operation_type'   => ['required', Rule::in(array_merge(['all'], AgentOperationType::values()))],
            'currency_id'      => 'nullable|exists:currencies,id',
            'min_amount'       => 'required|numeric|min:0',
            'max_amount'       => 'nullable|numeric|gt:min_amount',
            'calculation_type' => ['required', new Enum(AgentCommissionRuleType::class)],
            'percentage_rate'  => 'nullable|numeric|min:0|max:100',
            'fixed_amount'     => 'nullable|numeric|min:0',
            'min_commission'   => 'nullable|numeric|min:0',
            'max_commission'   => 'nullable|numeric|min:0|gte:min_commission',
            'effective_from'   => 'nullable|date',
            'effective_until'  => 'nullable|date|after_or_equal:effective_from',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type    = $this->input('calculation_type');
            $percent = (float) $this->input('percentage_rate', 0);
            $fixed   = (float) $this->input('fixed_amount', 0);

            if ($type === AgentCommissionRuleType::PERCENTAGE->value && $percent <= 0) {
                $validator->errors()->add('percentage_rate', __('Percentage rate must be greater than zero.'));
            }

            if ($type === AgentCommissionRuleType::FIXED->value && $fixed <= 0) {
                $validator->errors()->add('fixed_amount', __('Fixed amount must be greater than zero.'));
            }

            // A combined rule needs at least one component; requiring both
            // would make "2% + 0" unexpressible for no reason.
            if ($type === AgentCommissionRuleType::COMBINED->value && $percent <= 0 && $fixed <= 0) {
                $validator->errors()->add('fixed_amount', __('Set a fixed amount, a percentage rate, or both.'));
            }
        });
    }
}
