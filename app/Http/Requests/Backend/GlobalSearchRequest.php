<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend;

use App\Support\AdminSearch\GlobalSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GlobalSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q'     => ['nullable', 'string', 'max:120'],
            'scope' => ['nullable', 'string', Rule::in(GlobalSearch::scopes())],
            'limit' => ['nullable', 'integer', 'between:1,'.GlobalSearch::MAX_LIMIT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max'         => __('Search terms are limited to :max characters.', ['max' => 120]),
            'scope.in'      => __('That search scope does not exist.'),
            'limit.between' => __('Ask for between :min and :max results per group.', ['min' => 1, 'max' => GlobalSearch::MAX_LIMIT]),
        ];
    }
}
