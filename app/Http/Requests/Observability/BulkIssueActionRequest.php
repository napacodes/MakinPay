<?php

namespace App\Http\Requests\Observability;

use App\Models\Observability\ErrorGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk triage action from the Issues list.
 *
 * Group ids are the public ULIDs, checked against the table rather than
 * trusted: the checkboxes come from a page the browser can edit, so an id
 * that was never on it must not be actionable.
 */
class BulkIssueActionRequest extends FormRequest
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
            'action'   => ['required', 'string', Rule::in(['resolve', 'ignore', 'assign'])],
            'groups'   => ['required', 'array', 'min:1', 'max:1000'],
            'groups.*' => ['required', 'string', Rule::exists(ErrorGroup::class, 'group_id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => __('Choose what to do with the selected errors.'),
            'action.in'       => __('That action is not available here.'),
            'groups.required' => __('Select at least one error first.'),
            'groups.max'      => __('Select no more than 1000 errors at once.'),
            'groups.*.exists' => __('One of the selected errors no longer exists.'),
        ];
    }
}
