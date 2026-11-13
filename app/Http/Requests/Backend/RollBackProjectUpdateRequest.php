<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rolling back is destructive to the application directory, so the operator
 * has to name the release they mean rather than merely posting to the route.
 * The controller compares it against the bound row.
 */
class RollBackProjectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'confirm_version' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_version.required' => __('Confirm which release you are rolling back.'),
        ];
    }
}
