<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Baseline data the public site depends on
|--------------------------------------------------------------------------
|
| A CLI install replaced the `DB/digikash.sql` import, and for a while it
| reproduced neither of the two things the public homepage could not live
| without:
|
|  - `languages` was left empty, so the default theme's language switcher
|    dereferenced null and the homepage returned 500.
|  - `settings` was left empty, so every key fell back to its config default
|    and the footer rendered the literal text "admin" as the copyright.
|
| These tests pin the regression. They are deliberately asserted against the
| seeded rows and against a rendered homepage, not against the seeder's
| internals, so a future refactor is free to change how the data is written.
|
| @group serial
|
*/

beforeEach(function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $this->artisan('makinpay:install', [
        '--force'          => true,
        '--no-interaction' => true,
        '--seed-baseline'  => true,
        '--allow-existing' => true,
    ])->assertSuccessful();
})->group('serial');

it('seeds the languages the public shell needs', function (): void {
    $languages = DB::table('languages')->get();

    expect($languages)->not->toBeEmpty();

    $codes = $languages->pluck('code')->all();

    expect($codes)->toContain('en');

    /*
     * Exactly one row may claim the default. Two would make locale resolution
     * depend on row order, which is how "the site randomly loads in Arabic"
     * bugs start.
     */
    expect(DB::table('languages')->where('is_default', true)->count())->toBe(1);
})->group('serial');

it('seeds the public settings a storefront reads', function (): void {
    foreach (['site_title', 'copyright_text', 'admin_prefix', 'support_email', 'site_timezone'] as $key) {
        $value = DB::table('settings')->where('key', $key)->value('val');

        expect($value)->not->toBeNull("The \"$key\" setting was not seeded.");
    }

    // The two values that shipped wrong from the dump-derived config defaults.
    expect(DB::table('settings')->where('key', 'copyright_text')->value('val'))
        ->not->toBe('admin');

    expect(DB::table('settings')->where('key', 'site_title')->value('val'))
        ->not->toBe('Coevs');
})->group('serial');

it('renders the homepage with a language switcher and a real footer', function (): void {
    $response = $this->get('/');

    $response->assertOk();

    // A language option for every seeded language, pointing at the setter.
    $response->assertSee('locale-set/en', false)
        ->assertSee('locale-set/ar', false);

    // The copyright line, not the bare setting name it used to fall back to.
    $response->assertDontSee('<p>admin</p>', false);
})->group('serial');

it('renders the homepage without erroring when no language is active', function (): void {
    /*
     * An administrator can disable every language, or the table can be empty
     * on a half-finished install. Neither may take the homepage down: the
     * switcher hides itself instead.
     */
    DB::table('languages')->update(['status' => false]);

    App\Models\Language::flushCache();
    Cache::flush();

    $this->get('/')->assertOk();
})->group('serial');

it('does not print the post-install summary from a raw settings query', function (): void {
    /*
     * The command used to read `settings.value` — a column that does not exist
     * (`val` is the real one) — so a successful install ended with
     * "Unknown column 'value'" and a non-zero exit code, even though the
     * database and the marker were already written correctly.
     *
     * The administrator already exists at this point (beforeEach created the
     * first one), so this run deliberately reports "already exists" and does
     * not print the Next-steps block. That is the shape that used to throw:
     * the guard clause reached the settings read with no new admin to report.
     */
    $exit = Artisan::call('makinpay:install', [
        '--force'          => true,
        '--no-interaction' => true,
        '--allow-existing' => true,
    ]);

    expect($exit)->toBe(0);

    $output = Artisan::output();

    expect($output)->not->toContain('Unknown column');
    expect($output)->not->toContain('QueryException');

    // The run still has to report the outcome rather than dying mid-way.
    expect($output)->toContain('already exists');
})->group('serial');

it('repairs an installation that has no languages or public settings', function (): void {
    /*
     * `install:reconcile` is the supported repair path for a site that went
     * live in a broken state, so it has to restore exactly the data whose
     * absence produced the homepage 500. This is the regression that a
     * reinstall would otherwise be the only cure for.
     */
    DB::table('languages')->delete();
    DB::table('settings')->whereIn('key', ['site_title', 'copyright_text', 'admin_prefix'])->delete();

    App\Models\Language::flushCache();
    Cache::flush();

    expect(DB::table('languages')->count())->toBe(0);

    Artisan::call('install:reconcile');

    expect(DB::table('languages')->count())->toBeGreaterThan(0);
    expect(DB::table('settings')->where('key', 'site_title')->value('val'))->not->toBeNull();
    expect(DB::table('settings')->where('key', 'copyright_text')->value('val'))
        ->not->toBe('admin');

    // The repaired site must render, not merely hold rows.
    Cache::flush();
    $this->get('/')->assertOk();
})->group('serial');

it('reports the seeded admin prefix in the post-install summary', function (): void {
    /*
     * The summary reads `admin_prefix` to build the sign-in URL. It is fetched
     * through the settings layer, so a non-default prefix is honoured and a
     * missing row falls back to "admin" instead of failing.
     */
    App\Models\Setting::set('admin_prefix', 'backoffice');

    /*
     * The Next-steps block only prints when this run created the first
     * administrator, so drop the one beforeEach made; the command then
     * provisions a fresh admin from the environment.
     */
    DB::table('admins')->delete();

    Artisan::call('makinpay:install', [
        '--force'          => true,
        '--no-interaction' => true,
        '--allow-existing' => true,
    ]);

    expect(Artisan::output())->toContain('/backoffice');
})->group('serial');
