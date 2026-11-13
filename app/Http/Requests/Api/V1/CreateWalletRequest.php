<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a wallet in another currency from the app.
 *
 * Takes the currency's code rather than its id: the app never sees a
 * currency id (v1/currencies does not send one), and a code is what the
 * customer chose from the list.
 */
class CreateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper(trim((string) $this->input('currency', '')))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency' => [
                'required',
                'string',
                'max:10',
                Rule::exists('currencies', 'code')->where('status', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency.required' => __('Please select a currency.'),
            'currency.exists'   => __('The selected currency is currently unavailable.'),
        ];
    }
}
