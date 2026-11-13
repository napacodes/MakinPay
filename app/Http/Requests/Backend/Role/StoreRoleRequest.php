<?php

namespace App\Http\Requests\Backend\Role;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where('guard_name', 'admin'),
            ],
            'description'  => ['required', 'string', 'max:255'],
            'permission'   => ['required', 'array', 'min:1'],
            'permission.*' => [
                'required',
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'admin'),
            ],
        ];
    }

    /**
     * Names, not ids.
     *
     * The editor posts what the seeder calls each capability, so a form held
     * open across a reseed grants what it displayed rather than whatever now
     * occupies those id numbers.
     *
     * Unlike the update path, a new role must be born with something: one that
     * grants nothing can do nothing, and the rule predates this screen.
     *
     * @return list<string>
     */
    public function selectedPermissionNames(): array
    {
        return collect($this->validated('permission', []))
            ->map(static fn (mixed $name): string => (string) $name)
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permission.required' => __('Select at least one permission before saving this role.'),
            'permission.min'      => __('Select at least one permission before saving this role.'),
        ];
    }
}
