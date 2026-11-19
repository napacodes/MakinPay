<?php

namespace App\Support;

use App\Models\Language;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolve which language a frontend switcher should present as current.
 *
 * Every theme renders its own switcher markup, but they must all answer the
 * same three questions the same way:
 *
 *  - which of the active languages is "current"?
 *  - what to show when that language has no flag image?
 *  - what to show when there are no languages at all?
 *
 * The first question is the one that used to be answered wrongly. The obvious
 * `->firstWhere('code', app()->getLocale()) ?? ->first()` claims the first row
 * is current whenever the active locale has no row — so a site running in a
 * locale that is not in the table displayed "English" as if English were
 * active. Falling back to the *default* language is at least honest about the
 * site's configured intent, and the dropdown still marks the truly active row
 * by comparing codes.
 */
final class LanguageSwitcher
{
    /**
     * Empty results already reported in this request, so a page that renders
     * two switchers does not log the same warning twice.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

    /**
     * Pick the language to present as current.
     *
     * Order of preference: the active locale, then the site's default language,
     * then whatever the table holds first.
     *
     * @param  Collection<int, Language>|null $languages
     */
    public static function current(?Collection $languages, ?string $locale = null): ?Language
    {
        $languages ??= collect();
        $locale    ??= app()->getLocale();

        if ($languages->isEmpty()) {
            self::reportOnce(
                'empty',
                'No active languages are configured, so the frontend language switcher renders nothing.'
            );

            return null;
        }

        if ($matched = $languages->firstWhere('code', $locale)) {
            return $matched;
        }

        /*
         * The active locale has no row. Report it once: it means either the
         * locale was set outside the table, or a language row was deactivated
         * while a session still points at it.
         */
        self::reportOnce(
            'missing:'.$locale,
            'The active locale has no active language row, so the switcher falls back to the default language.',
            ['locale' => $locale]
        );

        return $languages->firstWhere('is_default', true) ?? $languages->first();
    }

    /**
     * Should the switcher render at all?
     *
     * True whenever there is at least one active language. A single-language
     * site keeps its control rather than having it silently disappear: the
     * switcher is the one place showing a visitor which language they are
     * reading, so hiding it is a bigger surprise than a one-item dropdown.
     *
     * @param Collection<int, Language>|null $languages
     */
    public static function shouldRender(?Collection $languages): bool
    {
        return ($languages?->isNotEmpty() ?? false);
    }

    /**
     * Short label for a language that has no flag image: its code, upper-cased
     * and clipped to two characters ("EN", "AR").
     *
     * Rendered in place of the flag so the row keeps its alignment instead of
     * collapsing when the image is missing.
     */
    public static function flagFallback(?Language $language, ?string $fallbackCode = null): string
    {
        $code = trim((string) ($language?->code ?? $fallbackCode ?? ''));

        if ($code === '') {
            $code = mb_substr(trim((string) $language?->name), 0, 2);
        }

        return mb_strtoupper(mb_substr($code, 0, 2));
    }

    /**
     * Drop the per-request report memo. Used by tests.
     */
    public static function flush(): void
    {
        self::$reported = [];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function reportOnce(string $key, string $message, array $context = []): void
    {
        if (isset(self::$reported[$key])) {
            return;
        }

        self::$reported[$key] = true;

        Log::warning($message, $context + ['languages' => Language::class]);
    }
}
