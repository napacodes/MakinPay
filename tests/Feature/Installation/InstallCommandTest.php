<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Support\Installation\Preflight;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Install command — end to end
|--------------------------------------------------------------------------
|
| Exercises `makinpay:install` the way an operator runs it, asserting the
| properties that matter for safety: an existing APP_KEY is never replaced, an
| existing database is never reset, a second run is refused, demo data is never
| written in production, and the marker is only written when the installation
| is genuinely usable.
|
| These tests do NOT seed the full baseline (39 seeders) — that is covered
| separately by InstallerSeedingTest against a disposable file database.
|
*/

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const ADMIN_EMAIL    = 'owner@makinpay.test';
const ADMIN_PASSWORD = 'Str0ngPass!23';

function markerPath(): string
{
    return app(Preflight::class)->markerPath();
}

function installOptions(array $overrides = []): array
{
    return array_merge([
        '--no-interaction' => true,
        '--force'          => true,
    ], $overrides);
}

/**
 * Set environment variables for the duration of a callback.
 *
 * The installer reads the administrator email and password with getenv(),
 * deliberately — a password must never travel as a command-line argument
 * where `ps` could read it. A test therefore cannot pass it in the options
 * array; it has to be exported the way an operator would export it.
 */
function withEnvironment(array $variables, Closure $callback): mixed
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($name.'='.$value);
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name]    = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

/** Assert an install result succeeded, surfacing its output when it did not. */
function expectInstalled(array $result): void
{
    expect($result['exit'])->toBe(0, "makinpay:install failed:\n".$result['output']);
}

/**
 * Run `makinpay:install` and return its exit code and output.
 *
 * Administrator credentials come from the environment set in tests/Pest.php,
 * because the command reads them with getenv() by design.
 */
function install(array $options = []): array
{
    $exit = Artisan::call('makinpay:install', installOptions($options));

    return ['exit' => $exit, 'output' => Artisan::output()];
}

it('installs successfully and writes the completion marker', function (): void {
    expect(file_exists(markerPath()))->toBeFalse();

    expectInstalled(install());

    expect(file_exists(markerPath()))->toBeTrue();
    expect(app(Preflight::class)->isInstalled())->toBeTrue();
});

it('applies migrations so the schema is complete', function (): void {
    expectInstalled(install());

    foreach (['admins', 'users', 'migrations', 'settings', 'payment_gateways'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table {$table} to exist.");
    }
});

it('creates the administrator from the environment without echoing the password', function (): void {
    $result = install();

    expectInstalled($result);

    // The secret must never appear in command output, which operators paste
    // into tickets and chat threads.
    expect($result['output'])->not->toContain(ADMIN_PASSWORD);

    $admin = Admin::query()->where('email', ADMIN_EMAIL)->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('super-admin'))->toBeTrue();
});

it('refuses to run a second time without --allow-existing', function (): void {
    expectInstalled(install());

    $second = withEnvironment([
        'MAKINPAY_ADMIN_EMAIL' => 'second@makinpay.test',
    ], fn () => install());

    expect($second['exit'])->not->toBe(0);
    expect($second['output'])->toContain('already');

    expect(Admin::query()->count())->toBe(1);
    expect(Admin::query()->where('email', 'second@makinpay.test')->exists())->toBeFalse();
});

it('does not reset an existing database when re-run with --allow-existing', function (): void {
    expectInstalled(install());

    // A row that a `migrate:fresh` would destroy.
    DB::table('settings')->insert([
        'key'        => 'sentinel_setting',
        'val'        => 'must_survive',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expectInstalled(install(['--allow-existing' => true]));

    expect(DB::table('settings')->where('key', 'sentinel_setting')->exists())->toBeTrue();
    expect(Admin::query()->count())->toBe(1);
});

it('never overwrites an existing application key', function (): void {
    $original = (string) config('app.key');

    expect($original)->not->toBe('');

    $result = install();
    expectInstalled($result);

    expect($result['output'])->toContain('present (kept)');

    // In-process, the container must still hold the original key.
    expect((string) config('app.key'))->toBe($original);
});

it('does not write the marker when no administrator is created', function (): void {
    $this->artisan('makinpay:install', installOptions(['--skip-admin' => true]))
        ->assertSuccessful();

    /*
     * A marker without an administrator would strand the site behind the
     * maintenance page with no way in. Preflight also treats "no admin row" as
     * not-installed, so the two signals must agree.
     */
    expect(file_exists(markerPath()))->toBeFalse();
    expect(app(Preflight::class)->isInstalled())->toBeFalse();
});

it('treats a migrated but admin-less database as not installed', function (): void {
    // Simulates an install interrupted after migrating, before the admin step.
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('admins'))->toBeTrue();
    expect(Admin::query()->exists())->toBeFalse();
    expect(app(Preflight::class)->isInstalled())->toBeFalse();
});

it('honours the legacy installer lock file', function (): void {
    // A site installed by the retired web installer must not read as fresh.
    $legacy = storage_path('app/installed');

    file_put_contents($legacy, 'legacy');

    try {
        expect(app(Preflight::class)->isInstalled())->toBeTrue();
    } finally {
        @unlink($legacy);
    }
});

it('writes a marker with no secrets in it', function (): void {
    expectInstalled(install());

    $contents = (string) file_get_contents(markerPath());

    expect($contents)->not->toContain(ADMIN_PASSWORD)
        ->and($contents)->not->toContain((string) config('app.key'))
        ->and(mb_strtolower($contents))->not->toContain('password');
});

it('refuses demo seeding in production', function (): void {
    $original = app()->environment();

    app()->detectEnvironment(fn (): string => 'production');

    try {
        $result = install(["--demo" => true]);

        expectInstalled($result);
        expect($result['output'])->toContain('refused in production');
    } finally {
        app()->detectEnvironment(fn () => $original);
    }

    // No demo accounts, whatever the seeder would otherwise have produced.
    expect(DB::table('users')->where('email', 'like', '%@example.com')->count())->toBe(0);
});

it('reports health via the check command', function (): void {
    $this->artisan('makinpay:install:check')
        ->expectsOutputToContain('PHP version')
        ->assertSuccessful();
});

it('emits a well formed report from the check command', function (): void {
    $this->artisan('makinpay:install:check', ['--json' => true])->assertSuccessful();

    $checks = app(Preflight::class)->run();

    expect($checks)->toBeArray()->not->toBeEmpty();

    foreach ($checks as $check) {
        expect($check)->toHaveKeys(['key', 'label', 'status', 'detail'])
            ->and($check['status'])->toBeIn(['ok', 'warn', 'fail', 'unknown']);
    }
});

it('creates an administrator through makinpay:admin:create', function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    Artisan::call('makinpay:admin:create', [
        '--name'           => 'Second Admin',
        '--email'          => 'second@makinpay.test',
        '--no-interaction' => true,
    ]);

    expect(Admin::query()->where('email', 'second@makinpay.test')->exists())->toBeTrue();
});

it('refuses a weak password supplied through the environment', function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $exit = withEnvironment(['MAKINPAY_ADMIN_PASSWORD' => 'weak'], fn (): int => Artisan::call(
        'makinpay:admin:create',
        ['--name' => 'Weak Admin', '--email' => 'weak@makinpay.test', '--no-interaction' => true]
    ));

    expect($exit)->not->toBe(0);

    expect(Admin::query()->where('email', 'weak@makinpay.test')->exists())->toBeFalse();
});
