<?php

declare(strict_types=1);

namespace App\Support;

use Locale;
use Throwable;

/**
 * A language's name in its own language, for filling the field in rather than
 * for reading at render time.
 *
 * The stored `native_name` is the source of truth: an administrator can set it
 * to whatever their audience actually calls the language, which matters for
 * regional variants and for languages CLDR names in a way their speakers do
 * not. This only supplies a sensible default when the field is left blank.
 *
 * ext-intl is not in composer.json, so it is used when present and never
 * relied on. The table below covers the languages this product ships and the
 * ones customers most often add; anything past it returns null, and the
 * administrator fills the field themselves.
 */
final class LanguageNames
{
    /**
     * Endonyms, each written the way its own speakers write it — including
     * the capitalisation, since Spanish, French and Italian do not capitalise
     * language names and English and German do.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'ar' => 'العربية',
        'bn' => 'বাংলা',
        'de' => 'Deutsch',
        'en' => 'English',
        'es' => 'Español',
        'fa' => 'فارسی',
        'fr' => 'Français',
        'hi' => 'हिन्दी',
        'id' => 'Bahasa Indonesia',
        'it' => 'Italiano',
        'ja' => '日本語',
        'ko' => '한국어',
        'ms' => 'Bahasa Melayu',
        'nl' => 'Nederlands',
        'pt' => 'Português',
        'ru' => 'Русский',
        'sw' => 'Kiswahili',
        'th' => 'ไทย',
        'tr' => 'Türkçe',
        'ur' => 'اردو',
        'vi' => 'Tiếng Việt',
        'zh' => '中文',
    ];

    /**
     * The best available endonym for a BCP 47 code, or null when there is
     * none worth offering.
     *
     * Null rather than the code itself, to keep a guess out of the database:
     * an empty field falls back to the English name, which is at least a
     * language name, whereas "es" in a picker is nothing at all.
     */
    public static function for(string $code): ?string
    {
        $code = strtolower(trim($code));

        if ($code === '') {
            return null;
        }

        /* "pt-BR" and "pt" share an endonym unless CLDR says otherwise. */
        $base = explode('-', str_replace('_', '-', $code))[0];

        if (class_exists(Locale::class)) {
            try {
                $native = Locale::getDisplayLanguage($code, $code);

                /*
                 * Given a tag it has no data for, intl echoes the tag back.
                 * That is not a name, and storing it would look deliberate.
                 */
                if (is_string($native) && $native !== ''
                                       && strcasecmp($native, $code) !== 0
                                       && strcasecmp($native, $base) !== 0) {
                    return self::asLabel($native);
                }
            } catch (Throwable) {
                /* Fall through to the table below. */
            }
        }

        return self::NAMES[$code] ?? self::NAMES[$base] ?? null;
    }

    /**
     * CLDR gives the form a language uses in running text, and Spanish,
     * French, Portuguese and Italian do not capitalise a language name there
     * — "hablo español". A menu row is a label rather than a sentence, and in
     * that position those same languages do capitalise, which is why every
     * picker shows "Español".
     *
     * A no-op for Arabic, Bengali and the CJK scripts, which have no case to
     * change.
     */
    private static function asLabel(string $native): string
    {
        return mb_strtoupper(mb_substr($native, 0, 1, 'UTF-8'), 'UTF-8')
            .mb_substr($native, 1, null, 'UTF-8');
    }
}
