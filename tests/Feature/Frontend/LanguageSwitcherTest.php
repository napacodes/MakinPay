<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Frontend language switcher
|--------------------------------------------------------------------------
|
| The switcher is invisible unless three things line up:
|
|  1. the active layout actually includes the partial;
|  2. the composer hands it a non-empty language collection;
|  3. the partial can pick a "current" row to label itself with.
|
| When any of them fails the homepage still returns 200 and simply renders
| nothing, which is why the defect survived browser checks for so long. These
| tests pin all three, plus the empty-collection and unknown-locale paths.
|
| @group serial
|
*/

use App\Models\Language;
use App\Support\LanguageSwitcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    LanguageSwitcher::flush();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $this->artisan('makinpay:install', [
        '--force'          => true,
        '--no-interaction' => true,
        '--seed-baseline'  => true,
        '--allow-existing' => true,
    ])->assertSuccessful();

    Language::flushCache();
    Cache::forget('languages');
})->group('serial');

it('includes the switcher in the active homepage layout', function (): void {
    $markup = $this->get('/')->assertOk()->getContent();

    expect($markup)->toContain('class="language-selector"');

    /*
     * The classic layout renders it twice: once in the header strip for
     * desktop, once in the offcanvas drawer that replaces the strip below the
     * 991px breakpoint in main.css. Losing either one makes the switcher
     * disappear at that viewport only.
     */
    expect(substr_count($markup, 'class="language-selector"'))->toBe(2);
});

it('labels itself with the active language and its flag', function (): void {
    $markup = $this->get('/')->assertOk()->getContent();

    $toggle = extractSwitcherToggle($markup);

    expect($toggle)->toContain('English')
        ->and($toggle)->toContain('general/static/flags/us.svg')
        ->and($toggle)->toContain('data-bs-toggle="dropdown"')
        ->and($toggle)->toContain('aria-expanded="false"');
});

it('links every language to the locale setter', function (): void {
    $markup = $this->get('/')->assertOk()->getContent();

    foreach (Language::activeCached() as $language) {
        expect($markup)->toContain(route('locale-set', $language->code));
    }

    // The active row is marked so the design can highlight it.
    expect($markup)->toContain('dropdown-item d-flex align-items-center active');
});

it('switches the locale when the setter is followed', function (): void {
    $this->get('/')->assertOk();

    $this->get(route('locale-set', 'ar'))->assertRedirect();

    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl">', false);

    // And back again, so the switcher is not a one-way door.
    $this->get(route('locale-set', 'en'))->assertRedirect();

    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr">', false);
});

it('falls back to the default language when the active locale has no row', function (): void {
    // Simulate a session holding a locale that is no longer configured.
    app()->setLocale('fr');

    $markup = $this->get('/')->assertOk()->getContent();

    $toggle = extractSwitcherToggle($markup);

    // Presenting the default language is honest; presenting "the first row"
    // claimed English was active when nothing matched.
    expect($toggle)->toContain('English');
});

it('renders nothing, and does not error, with an empty language collection', function (): void {
    Language::query()->delete();
    Language::flushCache();
    Cache::forget('languages');
    LanguageSwitcher::flush();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertDontSee('class="language-selector"', false);
});

it('keeps the switcher when only one language is active', function (): void {
    Language::where('code', '!=', 'en')->update(['status' => false]);
    Language::flushCache();
    Cache::forget('languages');

    $markup = $this->get('/')->assertOk()->getContent();

    // A single language still names the locale the visitor is reading.
    expect($markup)->toContain('class="language-selector"')
        ->and($markup)->toContain('English');
});

it('falls back to a code badge when a language has no flag image', function (): void {
    /*
     * `flag` is NOT NULL in the schema, so "no flag" is an empty string rather
     * than a null. The partial treats both as absent, otherwise a row saved
     * with no flag would render a broken <img> pointing at the site root.
     */
    DB::table('languages')->where('code', 'ar')->update(['flag' => '']);
    Language::flushCache();
    Cache::forget('languages');

    $markup = $this->get('/')->assertOk()->getContent();

    expect($markup)->toContain('flag-icon-fallback')
        ->and($markup)->toContain('>AR<');
});

/**
 * Pull the switcher's toggle anchor out of the rendered page so assertions
 * target the button rather than the whole document.
 */
function extractSwitcherToggle(string $markup): string
{
    $start = strpos($markup, 'class="language-selector"');

    if ($start === false) {
        return '';
    }

    $open = strpos($markup, '<a href="#" class="dropdown-toggle', $start);

    if ($open === false) {
        return '';
    }

    $close = strpos($markup, '</a>', $open);

    return $close === false ? '' : substr($markup, $open, $close - $open);
}
