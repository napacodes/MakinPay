<?php

declare(strict_types=1);

use App\Support\Installation\Preflight;

/*
|--------------------------------------------------------------------------
| Preflight — environment gates
|--------------------------------------------------------------------------
|
| Preflight is read-only. These tests assert it reports the truth about the
| environment and, crucially, that a blocking failure is recognised as
| blocking so `makinpay:install` stops before touching the schema.
|
*/

function preflight(): Preflight
{
    return app(Preflight::class);
}

/** @return array{key: string, status: string, detail: string} */
function checkFor(string $key): array
{
    foreach (preflight()->run() as $check) {
        if ($check['key'] === $key) {
            return $check;
        }
    }

    throw new RuntimeException('Preflight check not found: '.$key);
}

it('passes preflight on a correctly configured server', function (): void {
    $checks = preflight()->run();

    expect($checks)->not->toBeEmpty()
        ->and(preflight()->hasBlockingFailure($checks))->toBeFalse();

    $statuses = array_unique(array_column($checks, 'status'));

    expect($statuses)->not->toContain(Preflight::FAIL);
});

it('reports a supported PHP version', function (): void {
    expect(checkFor('php_version')['status'])->toBe(Preflight::OK);
});

it('fails preflight when a required PHP extension is missing', function (): void {
    // Deliberately request an extension name that cannot exist.
    config(['installer.required_extensions' => ['Imaginary' => 'makinpay_nonexistent_extension']]);

    $check = checkFor('ext_makinpay_nonexistent_extension');

    expect($check['status'])->toBe(Preflight::FAIL)
        ->and($check['detail'])->toBe('missing');

    expect(preflight()->hasBlockingFailure(preflight()->run()))->toBeTrue();
});

it('fails preflight when the application key is missing', function (): void {
    config(['app.key' => '']);

    $check = checkFor('app_key');

    expect($check['status'])->toBe(Preflight::FAIL)
        ->and($check['detail'])->toBe('missing');
});

it('fails preflight when the application key is malformed', function (): void {
    // Correct prefix, wrong decoded length.
    config(['app.key' => 'base64:'.base64_encode('too-short')]);

    $check = checkFor('app_key');

    expect($check['status'])->toBe(Preflight::FAIL)
        ->and($check['detail'])->toBe('present but malformed');
});

it('accepts a well formed application key without printing it', function (): void {
    $key = (string) config('app.key');
    $check = checkFor('app_key');

    expect($check['status'])->toBe(Preflight::OK);

    // The raw key must never appear in a report an operator may paste around.
    expect(json_encode(preflight()->run()))->not->toContain($key);
});

it('fails preflight when the database driver is unknown', function (): void {
    config(['database.default' => 'not_a_real_connection']);

    $check = checkFor('db_driver');

    expect($check['status'])->toBe(Preflight::FAIL);
    expect(preflight()->hasBlockingFailure(preflight()->run()))->toBeTrue();
});

it('warns rather than fails when APP_URL is left at localhost', function (): void {
    config(['app.url' => 'http://localhost']);

    $check = checkFor('app_url');

    expect($check['status'])->toBe(Preflight::WARN)
        ->and($check['status'])->not->toBe(Preflight::FAIL);
});

it('warns when debug mode is enabled in production', function (): void {
    $original = app()->environment();

    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true]);

    try {
        $check = checkFor('app_debug');

        expect($check['status'])->toBe(Preflight::WARN)
            ->and($check['detail'])->toBe('APP_DEBUG=true');
    } finally {
        app()->detectEnvironment(fn () => $original);
    }
});

it('reports every configured writable path', function (): void {
    $keys = array_column(preflight()->run(), 'key');

    foreach (['writable_storage', 'writable_storage_app', 'writable_cache_directory'] as $expected) {
        expect($keys)->toContain($expected);
    }
});

it('fails preflight when a writable path is not writable', function (): void {
    config(['installer.writable_paths' => ['Nowhere' => '/this/path/does/not/exist/anywhere']]);

    $check = checkFor('writable_nowhere');

    expect($check['status'])->toBe(Preflight::FAIL);
    expect(preflight()->hasBlockingFailure(preflight()->run()))->toBeTrue();
});

it('never exposes database credentials in a connection error', function (): void {
    // Point at a path SQLite cannot open; the driver message may echo the DSN.
    config([
        'database.connections.sqlite.database' => '/nonexistent-dir/secret.sqlite',
        'database.connections.sqlite.password' => 'super-secret-password',
    ]);

    DB::purge('sqlite');

    $check = checkFor('db_connect');

    expect($check['status'])->toBe(Preflight::FAIL);
    expect($check['detail'])->not->toContain('super-secret-password');
});
