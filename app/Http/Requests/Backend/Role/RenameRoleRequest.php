<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A role's name and description, and nothing else.
 *
 * Renaming deliberately does not go through UpdateRoleRequest. That request
 * also validates `permission.*`, and the action behind it calls
 * `syncPermissions()` with whatever arrived — so a rename dialog, which posts
 * no permissions at all, would silently strip every one the role holds.
 */
class RenameRoleRequest extends FormRequest
{
    /**
     * As its siblings do. Authorisation for this controller lives in one
     * place — `RoleController::permissions()`, which maps `rename` to
     * `role-edit` for the permission middleware. A second check here would
     * be a second thing to keep in step.
     */
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
            'role_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->ignore($this->route('role'))
                    ->where('guard_name', 'admin'),
            ],
            'description' => ['required', 'string', 'max:255'],
        ];
    }
}
