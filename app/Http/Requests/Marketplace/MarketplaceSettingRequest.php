<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\Split\SplitProviderFactory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled'             => ['required', 'boolean'],
            'split_provider'      => ['nullable', 'string', Rule::in(array_keys(app(SplitProviderFactory::class)->options()))],
            'commission_pct'      => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_fixed'    => ['nullable', 'numeric', 'min:0'],
            'min_order_amount'    => ['nullable', 'numeric', 'min:0'],
            'max_order_amount'    => ['nullable', 'numeric', 'min:0', 'gt:min_order_amount'],
            'auto_release'        => ['required', 'boolean'],
            'payout_hold_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_order_amount.gt' => __('Maximum order amount must be greater than the minimum.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled'      => $this->boolean('enabled'),
            'auto_release' => $this->boolean('auto_release'),
        ]);
    }
}
