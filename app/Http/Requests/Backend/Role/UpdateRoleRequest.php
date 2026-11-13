<?php

namespace App\Http\Requests\Backend\Role;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
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
                Rule::unique('roles', 'name')
                    ->ignore($this->route('role'))
                    ->where('guard_name', 'admin'),
            ],
            'description'  => ['required', 'string', 'max:255'],
            'permission'   => ['nullable', 'array'],
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
     * An empty set is allowed on purpose: stripping a role back to nothing is
     * a decision an administrator is entitled to make, and refusing it would
     * leave no way to undo a grant-all.
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
            'permission.*.exists' => __('One of the selected permissions no longer exists.'),
        ];
    }
}
