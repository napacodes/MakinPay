<?php

namespace App\Http\Requests\Backend\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The typed confirmation and the forfeit acknowledgement behind the Delete
 * account modal. Whether a forfeit is actually needed depends on the
 * wallets, so that check stays in the controller next to the balances.
 */
class DeleteUserRequest extends FormRequest
{
    public const CONFIRMATION = 'DELETE';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', 'in:'.self::CONFIRMATION],
            'forfeit' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm.required' => __('Type DELETE to confirm.'),
            'confirm.in'       => __('Type DELETE exactly to confirm.'),
        ];
    }
}
