<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\P2P;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class P2PAssetFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can('p2p-manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency_id' => [
                'required',
                'integer',
                Rule::exists('currencies', 'id'),
                // One row per asset — the resolver looks a fee up by currency,
                // so a second row for the same one could only be ambiguous.
                Rule::unique('p2p_asset_fees', 'currency_id')->ignore($this->route('asset_fee')),
            ],

            // Both components may be zero: a "0% + 0" override is how an
            // operator makes one asset fee-free while the global pair charges.
            'maker_fee_pct'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'maker_fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'maker_fee_min'   => ['nullable', 'numeric', 'min:0'],
            'maker_fee_max'   => ['nullable', 'numeric', 'min:0', 'gte:maker_fee_min'],

            'taker_fee_pct'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'taker_fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'taker_fee_min'   => ['nullable', 'numeric', 'min:0'],
            'taker_fee_max'   => ['nullable', 'numeric', 'min:0', 'gte:taker_fee_min'],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency_id.unique' => __('This asset already has a fee override. Edit the existing one instead.'),
            'maker_fee_max.gte'  => __('Maximum maker fee must be greater than or equal to the minimum.'),
            'taker_fee_max.gte'  => __('Maximum taker fee must be greater than or equal to the minimum.'),
        ];
    }

    /**
     * A floor above the amount it clamps is not a floor.
     *
     * The fee is charged in the traded asset, so a min of 1 on a BTC override
     * would take a whole bitcoin off every trade. There is no honest ceiling
     * for that, but a floor larger than the ceiling is unambiguously a typo
     * and is worth catching before it prices an asset out of the market.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['maker', 'taker'] as $side) {
                $fixed = (float) $this->input("{$side}_fee_fixed", 0);
                $max   = $this->input("{$side}_fee_max");

                if ($max !== null && $max !== '' && $fixed > (float) $max) {
                    $validator->errors()->add(
                        "{$side}_fee_fixed",
                        __('The flat fee is above the maximum, so the maximum would always apply.')
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function feeAttributes(): array
    {
        $data = $this->validated();

        foreach (['maker_fee_pct', 'maker_fee_fixed', 'taker_fee_pct', 'taker_fee_fixed'] as $component) {
            // NOT NULL DEFAULT 0, but a blank field arrives as null because
            // ConvertEmptyStringsToNull rewrites '' before validation.
            $data[$component] = $data[$component] ?? 0;
        }

        $data['is_active'] = $this->boolean('is_active');

        return $data;
    }
}
