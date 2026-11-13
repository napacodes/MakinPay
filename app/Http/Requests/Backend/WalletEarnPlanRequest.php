<?php

namespace App\Http\Requests\Backend;

use App\Enums\WalletEarnPayoutFrequency;
use App\Enums\WalletEarnProfitType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletEarnPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency_id'    => ['nullable', Rule::exists('currencies', 'id')],
            'name'           => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:1000'],
            'minimum_amount' => ['required', 'numeric', 'min:0.00000001'],
            'maximum_amount' => ['nullable', 'numeric', 'gte:minimum_amount'],
            // Either component may be zero, but not both — a plan that pays
            // nothing is not a plan. Checked in withValidator() rather than
            // with `required` so the operator is told what is actually wrong.
            'profit_fixed'   => ['nullable', 'numeric', 'min:0'],
            'profit_percent' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'profit_min'     => ['nullable', 'numeric', 'min:0'],
            'profit_max'     => ['nullable', 'numeric', 'min:0', 'gte:profit_min'],

            // Retained so an un-migrated form or customer integration still
            // validates; the models derive them from the pair above.
            'profit_rate'      => ['nullable', 'numeric', 'min:0'],
            'profit_type'      => ['nullable', Rule::in(array_keys(WalletEarnProfitType::options()))],
            'duration_value'   => ['required', 'integer', 'min:1', 'max:10000'],
            'duration_unit'    => ['required', Rule::in(['hours', 'days', 'months'])],
            'payout_frequency' => ['required', Rule::in(array_keys(WalletEarnPayoutFrequency::options()))],
            'return_principal' => ['nullable', 'boolean'],
            'auto_approve'     => ['nullable', 'boolean'],
            'is_featured'      => ['nullable', 'boolean'],
            'icon'             => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp,svg', 'max:2048'],
            'plan_badge'       => ['nullable', 'string', 'max:50'],
            'status'           => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'maximum_amount.gte' => __('Maximum amount must be greater than or equal to the minimum amount.'),
            'profit_max.gte'     => __('Maximum profit must be greater than or equal to the minimum profit.'),
            'duration_value.min' => __('Duration must be at least one unit.'),
        ];
    }

    /**
     * A plan has to pay something.
     *
     * The two components are individually optional — that is what makes
     * "fixed only", "percentage only" and "both" all expressible — so the
     * requirement is on the pair, not on either field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fixed   = (float) $this->input('profit_fixed', 0);
            $percent = (float) $this->input('profit_percent', 0);

            // A legacy payload sends the old pair instead, and it is still
            // valid — the model splits it. Only complain when nothing at all
            // was supplied.
            if ($fixed > 0 || $percent > 0 || (float) $this->input('profit_rate', 0) > 0) {
                return;
            }

            $validator->errors()->add(
                'profit_percent',
                __('Set a fixed profit, a percentage, or both.')
            );
        });
    }
}
