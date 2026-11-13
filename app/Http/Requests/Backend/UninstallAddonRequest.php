<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend;

/**
 * Uninstall: the identity confirmation, whether to keep the data, and - only
 * when the data goes - the add-on's slug typed out, so its tables are never
 * dropped by a stray click.
 */
class UninstallAddonRequest extends ConfirmAddonOperationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'keep_data'    => ['required', 'boolean'],
            'confirm_slug' => ['exclude_if:keep_data,1,true', 'required', 'string', 'in:'.(string) $this->route('slug')],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'confirm_slug.required' => __('Type the add-on\'s key to confirm deleting its data.'),
            'confirm_slug.in'       => __('That is not this add-on\'s key.'),
        ]);
    }
}
