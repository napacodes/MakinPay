<?php

namespace App\Http\Requests\Frontend;

use App\Support\QuickMenu;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuickMenuRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Every key is checked against the catalog this user is entitled to, so a
     * hand-crafted request cannot pin a shortcut to a feature the site has
     * switched off or that belongs to a role the user does not hold.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items'   => ['required', 'array', 'min:1', 'max:'.QuickMenu::MAX_ITEMS],
            'items.*' => ['required', 'string', Rule::in(QuickMenu::allowedKeysFor($this->user()))],
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => __('Pick at least one shortcut to keep in your quick menu.'),
            'items.array'    => __('Pick at least one shortcut to keep in your quick menu.'),
            'items.min'      => __('Pick at least one shortcut to keep in your quick menu.'),
            'items.max'      => __('That is more shortcuts than the quick menu can hold.'),
            'items.*.in'     => __('One of those shortcuts is not available on your account.'),
        ];
    }

    /**
     * The chosen keys, de-duplicated but left in the order the user arranged.
     *
     * @return array<int, string>
     */
    public function selectedKeys(): array
    {
        /** @var array<int, string> $items */
        $items = $this->validated('items');

        return array_values(array_unique($items));
    }
}
