<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class UploadAddonPackageRequest extends FormRequest
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
            'package' => ['required', 'file', 'mimes:zip', 'max:51200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'package.required' => __('Choose the add-on package (.zip) to upload.'),
            'package.mimes'    => __('An add-on package is a .zip file.'),
            'package.max'      => __('The package is larger than 50 MB.'),
        ];
    }
}
