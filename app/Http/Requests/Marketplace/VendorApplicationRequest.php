<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:3', 'max:100'],
            'about'        => ['nullable', 'string', 'max:1000'],
            'location'     => ['nullable', 'string', 'max:120'],
            'category'     => ['required', 'string', Rule::in(array_keys(Vendor::CATEGORIES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => __('Please enter your shop or business name.'),
            'display_name.min'      => __('The shop name must be at least 3 characters.'),
        ];
    }
}
