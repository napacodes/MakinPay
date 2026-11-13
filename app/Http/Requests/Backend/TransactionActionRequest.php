<?php

namespace App\Http\Requests\Backend;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionActionRequest extends FormRequest
{
    /**
     * Which permission this action needs depends on the transaction's own
     * type, which is not known until the controller has loaded the row. Only
     * the panel is authorized here; the controller resolves the rest.
     */
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
            'action'  => ['required', 'string', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in'   => __('Selected transaction action is invalid.'),
            'remarks.max' => __('Remarks may not be longer than 500 characters.'),
        ];
    }
}
