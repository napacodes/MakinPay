<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Switcher current-language resolution
|--------------------------------------------------------------------------
|
| Shared by the classic, Golden and user-panel switchers. The rule matters
| because the obvious `firstWhere(locale) ?? first()` silently claims the
| first row is active when nothing matches.
|
*/

use App\Models\Language;
use App\Support\LanguageSwitcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

function languageRow(string $code, array $attributes = []): Language
{
    $language = new Language();

    $language->forceFill(array_merge([
        'code'       => $code,
        'name'       => strtoupper($code).' name',
        'native_name'=> null,
        'flag'       => 'general/static/flags/'.$code.'.svg',
        'is_default' => false,
        'is_rtl'     => false,
        'status'     => true,
    ], $attributes));

    return $language;
}

beforeEach(function (): void {
    LanguageSwitcher::flush();
});

it('picks the row matching the active locale', function (): void {
    $languages = collect([languageRow('en', ['is_default' => true]), languageRow('ar')]);

    expect(LanguageSwitcher::current($languages, 'ar')?->code)->toBe('ar');
});

it('falls back to the default language when the locale has no row', function (): void {
    Log::spy();

    $languages = collect([languageRow('ar'), languageRow('en', ['is_default' => true])]);

    expect(LanguageSwitcher::current($languages, 'fr')?->code)->toBe('en');

    Log::shouldHaveReceived('warning')->once();
});

it('falls back to the first row when no row is marked default', function (): void {
    $languages = collect([languageRow('ar'), languageRow('en')]);

    expect(LanguageSwitcher::current($languages, 'fr')?->code)->toBe('ar');
});

it('returns null and warns for an empty collection', function (): void {
    Log::spy();

    expect(LanguageSwitcher::current(collect(), 'en'))->toBeNull();
    expect(LanguageSwitcher::current(null, 'en'))->toBeNull();

    // Both calls report as one problem, not two.
    Log::shouldHaveReceived('warning')->once();
});

it('reports a missing-locale warning only once per locale', function (): void {
    Log::spy();

    $languages = collect([languageRow('en', ['is_default' => true])]);

    LanguageSwitcher::current($languages, 'de');
    LanguageSwitcher::current($languages, 'de');

    Log::shouldHaveReceived('warning')->once();
});

it('decides whether to render from the collection', function (): void {
    expect(LanguageSwitcher::shouldRender(collect([languageRow('en')])))->toBeTrue()
        ->and(LanguageSwitcher::shouldRender(collect()))->toBeFalse()
        ->and(LanguageSwitcher::shouldRender(null))->toBeFalse();
});

it('builds a two-character fallback badge', function (): void {
    expect(LanguageSwitcher::flagFallback(languageRow('en')))->toBe('EN')
        ->and(LanguageSwitcher::flagFallback(languageRow('ar')))->toBe('AR')
        // No row at all: fall back to the locale string the caller has.
        ->and(LanguageSwitcher::flagFallback(null, 'pt_BR'))->toBe('PT')
        ->and(LanguageSwitcher::flagFallback(null, ''))->toBe('');
});

it('uses the display name, preferring the native one', function (): void {
    expect(languageRow('ar', ['native_name' => 'العربية'])->displayName())->toBe('العربية')
        ->and(languageRow('ar', ['name' => 'Arabic'])->displayName())->toBe('Arabic');
});
