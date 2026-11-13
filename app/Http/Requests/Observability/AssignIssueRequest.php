<?php

namespace App\Http\Requests\Observability;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an assignee change on one error.
 *
 * A null assignee is allowed on purpose — unassigning is a real triage action,
 * not an error.
 */
class AssignIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can('observability-manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_id' => ['nullable', 'integer', Rule::exists(Admin::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.exists' => __('That administrator account does not exist.'),
        ];
    }
}
