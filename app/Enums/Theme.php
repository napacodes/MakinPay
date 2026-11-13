<?php

namespace App\Enums;

/**
 * Site-wide visual theme. Drives:
 *  - which component schema/view set the page builder shows
 *  - which Blade layout & section partials the frontend renders
 *  - which CSS/JS bundle is loaded.
 *
 * Persisted in the `settings` table under the `active_theme` key; resolved
 * everywhere via {@see activeTheme()} in app/helpers.php.
 */
enum Theme: string
{
    case Classic = 'classic';
    case Golden  = 'golden';
    case Emerald = 'emerald';
    case Aurora  = 'aurora';

    public function label(): string
    {
        return match ($this) {
            self::Classic => __('Classic'),
            self::Golden  => __('Golden'),
            self::Emerald => __('Emerald'),
            self::Aurora  => __('Aurora'),
        };
    }

    public function tagline(): string
    {
        return match ($this) {
            self::Classic => __('The original clean, friendly look. Blue-forward, high clarity, built for a broad audience.'),
            self::Golden  => __('Luxury private-banking aesthetic — obsidian and gold, serif display, Swiss-watch poise.'),
            self::Emerald => __('Modern banking green — vivid emerald on warm paper, grotesk display, editorial whitespace.'),
            self::Aurora  => __('Premium fintech blue — midnight glass by default, an editorial white mode one click away.'),
        };
    }

    /**
     * Human label for the theme's surface mode.
     *
     * Deliberately a string rather than a read of {@see isDark()}: that boolean
     * only separates light from dark, and a later theme may be neither.
     */
    public function modeLabel(): string
    {
        return match ($this) {
            self::Classic => __('Light'),
            self::Golden  => __('Dark'),
            self::Emerald => __('Light'),
            self::Aurora  => __('Dark + Light'),
        };
    }

    /**
     * Design characteristics listed in a theme card's details panel.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function designSpec(): array
    {
        return match ($this) {
            self::Classic => [
                ['label' => __('Typeface'), 'value' => 'Instrument Sans'],
                ['label' => __('Radius scale'), 'value' => '6 · 10 · 14px'],
                ['label' => __('Surface'), 'value' => __('Solid, light')],
            ],
            self::Golden => [
                ['label' => __('Typeface'), 'value' => 'Instrument Serif + Sans'],
                ['label' => __('Radius scale'), 'value' => '2 · 4 · 8px'],
                ['label' => __('Surface'), 'value' => __('Obsidian, metallic edge')],
            ],
            self::Emerald => [
                ['label' => __('Typeface'), 'value' => 'Space Grotesk + Manrope'],
                ['label' => __('Radius scale'), 'value' => '8 · 12 · 20 · 28px'],
                ['label' => __('Surface'), 'value' => __('Warm paper, light')],
            ],
            self::Aurora => [
                ['label' => __('Typeface'), 'value' => 'Plus Jakarta Sans + JetBrains Mono'],
                ['label' => __('Radius scale'), 'value' => '12 · 16 · 20 · 28px'],
                ['label' => __('Surface'), 'value' => __('Midnight glass / white')],
            ],
        };
    }

    public function accentColor(): string
    {
        return match ($this) {
            self::Classic => '#4663EE',
            self::Golden  => '#D4AF37',
            self::Emerald => '#0A7A42',
            self::Aurora  => '#4663EE',
        };
    }

    public function isDark(): bool
    {
        return $this === self::Golden || $this === self::Aurora;
    }

    /**
     * Editable landing-page color slots for this theme. The defaults below are
     * the single source of truth; saved overrides live in the settings table
     * under {@see colorSettingKey()} and are merged in by {@see resolvedPalette()}.
     *
     * @return array<int, array{key: string, label: string, hint: string, default: string}>
     */
    public function colorSlots(): array
    {
        return match ($this) {
            self::Classic => [
                ['key' => 'primary',   'label' => __('Primary'),   'hint' => __('Buttons, links & key accents'), 'default' => '#4663EE'],
                ['key' => 'secondary', 'label' => __('Secondary'), 'hint' => __('Secondary highlights & info'),   'default' => '#22BDFF'],
                ['key' => 'accent',    'label' => __('Accent'),    'hint' => __('Success & positive accents'),    'default' => '#17A86A'],
            ],
            self::Golden => [
                ['key' => 'gold',       'label' => __('Gold'),       'hint' => __('Primary metallic accent'),  'default' => '#D4AF37'],
                ['key' => 'gold_deep',  'label' => __('Deep Gold'),  'hint' => __('Gradient base & borders'),  'default' => '#B8860B'],
                ['key' => 'gold_light', 'label' => __('Light Gold'), 'hint' => __('Gradient highlight'),        'default' => '#F5E6A8'],
            ],
            self::Emerald => [
                ['key' => 'primary', 'label' => __('Primary'), 'hint' => __('Buttons, links & key accents'),   'default' => '#0A7A42'],
                ['key' => 'accent',  'label' => __('Accent'),  'hint' => __('Hovers, charts & active states'), 'default' => '#0C9553'],
                ['key' => 'signal',  'label' => __('Signal'),  'hint' => __('APY & yield figures'),            'default' => '#A8701C'],
            ],
            self::Aurora => [
                ['key' => 'primary',   'label' => __('Primary'),   'hint' => __('Buttons, links & key accents'), 'default' => '#4663EE'],
                ['key' => 'secondary', 'label' => __('Secondary'), 'hint' => __('Gradients & info'),             'default' => '#22BDFF'],
                ['key' => 'positive',  'label' => __('Positive'),  'hint' => __('Savings & success'),            'default' => '#34D399'],
            ],
        };
    }

    /**
     * Surface modes a first-time visitor can be started on, for a theme that
     * ships both a dark and a light mode. Empty for a single-mode theme, which
     * is what keeps Theme Manager from offering the choice there.
     *
     * @return list<string>
     */
    public function defaultModeOptions(): array
    {
        return match ($this) {
            self::Classic, self::Golden, self::Emerald => [],
            self::Aurora => ['dark', 'light', 'system'],
        };
    }

    /**
     * Settings key holding the admin's chosen starting mode.
     */
    public function defaultModeSettingKey(): string
    {
        return "theme_{$this->value}_default_mode";
    }

    /**
     * The starting mode an administrator picked, or the theme's own default
     * when nothing valid is saved. Null for a single-mode theme.
     */
    public function defaultMode(): ?string
    {
        $options = $this->defaultModeOptions();

        if ($options === []) {
            return null;
        }

        $stored = strtolower(trim((string) setting($this->defaultModeSettingKey(), $options[0])));

        return in_array($stored, $options, true) ? $stored : $options[0];
    }

    /**
     * Settings key under which a given color slot's override is persisted.
     */
    public function colorSettingKey(string $slot): string
    {
        return "theme_{$this->value}_color_{$slot}";
    }

    /**
     * Resolve a single slot's color: a valid saved override, else the default.
     */
    public function colorValue(string $slot): string
    {
        $default = collect($this->colorSlots())->firstWhere('key', $slot)['default'] ?? '#000000';
        $stored  = strtoupper(trim((string) setting($this->colorSettingKey($slot), $default)));

        return preg_match('/^#[0-9A-F]{6}$/', $stored) === 1 ? $stored : strtoupper($default);
    }

    /**
     * The full resolved landing palette for this theme as slot => hex.
     *
     * @return array<string, string>
     */
    public function resolvedPalette(): array
    {
        return collect($this->colorSlots())
            ->mapWithKeys(fn (array $slot): array => [$slot['key'] => $this->colorValue($slot['key'])])
            ->all();
    }

    public static function default(): self
    {
        return self::Classic;
    }

    /**
     * Coerce a raw value (e.g. from settings) into a Theme, falling back to default.
     */
    public static function fromValueOrDefault(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value) && ($theme = self::tryFrom($value))) {
            return $theme;
        }

        return self::default();
    }
}
