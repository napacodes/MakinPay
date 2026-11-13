<?php

namespace App\Http\Requests\Backend\User;

use Illuminate\Foundation\Http\FormRequest;

class ConvertUserToMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:255'],
            'notify'        => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => __('Enter the business name the merchant account will trade under.'),
        ];
    }
}
