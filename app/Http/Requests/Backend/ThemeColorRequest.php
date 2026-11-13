<?php

namespace App\Http\Requests\Backend;

use App\Enums\Theme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'colors'       => ['required', 'array'],
            'colors.*'     => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_mode' => ['sometimes', 'nullable', 'string', Rule::in($this->modeOptions())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colors.*.regex'    => __('Each color must be a valid 6-digit hex value, e.g. #4663EE.'),
            'colors.*.required' => __('Please choose a color for every slot.'),
            'default_mode.in'   => __('Choose dark, light or the visitor\'s device setting.'),
        ];
    }

    /**
     * Starting modes the theme in the route offers; empty for a single-mode
     * theme, so a default mode posted for one is rejected.
     *
     * @return list<string>
     */
    protected function modeOptions(): array
    {
        return Theme::tryFrom((string) $this->route('theme'))?->defaultModeOptions() ?? [];
    }

    protected function prepareForValidation(): void
    {
        $colors = $this->input('colors', []);

        if (is_array($colors)) {
            $this->merge([
                'colors' => collect($colors)
                    ->map(fn ($value): string => strtoupper(trim((string) $value)))
                    ->all(),
            ]);
        }
    }
}
