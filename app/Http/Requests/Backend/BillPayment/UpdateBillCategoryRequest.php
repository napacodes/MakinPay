<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Models\BillCategory;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The same rules as a new category, with the code unique against every
 * other category. A system category keeps the code it shipped with: the
 * module seeds and finds those six by code.
 */
class UpdateBillCategoryRequest extends StoreBillCategoryRequest
{
    /**
     * @return list<mixed>
     */
    protected function codeRules(): array
    {
        $category = $this->routeCategory();

        return [
            'required',
            'string',
            'max:64',
            'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            Rule::unique('bill_categories', 'code')->ignore($category?->id),
            static function (string $attribute, mixed $value, Closure $fail) use ($category): void {
                if ($category !== null && $category->is_system && $value !== $category->code) {
                    $fail(__('A system category keeps its code.'));
                }
            },
        ];
    }

    private function routeCategory(): ?BillCategory
    {
        $category = $this->route('category');

        if ($category instanceof BillCategory) {
            return $category;
        }

        return is_numeric($category) ? BillCategory::query()->find((int) $category) : null;
    }
}
