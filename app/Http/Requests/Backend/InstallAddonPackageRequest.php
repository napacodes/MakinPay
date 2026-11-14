<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend;

/**
 * Installing an uploaded package: the identity confirmation, and the
 * purchase code when the admin gave one. The code can only be activated once
 * the add-on is on disk, so it travels with the install rather than before it.
 */
class InstallAddonPackageRequest extends ConfirmAddonOperationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'purchase_code' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'purchase_code.regex' => __('Enter a valid purchase code from your license certificate.'),
        ]);
    }
}
