<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A bill category as an admin writes it.
 *
 * The code is the category's address on the customer side
 * (bill-payments/category/{code}), so it is a lowercase slug and unique. The
 * icon is one of the module's own SVGs, never a free-form path.
 */
class StoreBillCategoryRequest extends FormRequest
{
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
            'name'        => ['required', 'string', 'max:120'],
            'code'        => $this->codeRules(),
            'icon'        => ['nullable', 'string', Rule::in((array) config('bill_payments.category_icons', []))],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'status'      => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex'  => __('Use lowercase letters and digits, joined by single dashes or underscores.'),
            'code.unique' => __('Another category already uses this code.'),
            'icon.in'     => __('Choose one of the listed icons.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => strtolower(trim($code))]);
        }
    }

    /**
     * @return list<mixed>
     */
    protected function codeRules(): array
    {
        return [
            'required',
            'string',
            'max:64',
            'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            Rule::unique('bill_categories', 'code'),
        ];
    }
}
