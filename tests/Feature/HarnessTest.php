<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Harness self-checks
|--------------------------------------------------------------------------
|
| These assert that the test environment itself is safe. If any of them fail,
| every other assertion in the suite is suspect: the tests would be running
| against the developer's real database or real storage directory.
|
*/

it('runs against an in-memory or temporary database', function (): void {
    $driver = DB::connection()->getDriverName();

    expect($driver)->toBe('sqlite');

    $database = (string) config('database.connections.sqlite.database');

    expect($database === ':memory:' || str_contains($database, sys_get_temp_dir()))
        ->toBeTrue('The test database must be in-memory or inside the temp directory.');
});

it('redirects storage_path into an isolated temporary directory', function (): void {
    $storage = storage_path();

    expect($storage)->toContain('makinpay-tests-');
    expect($storage)->not->toBe(base_path('storage'));
    expect(is_dir($storage))->toBeTrue();
});

it('points the installation marker inside the isolated storage directory', function (): void {
    $marker = app(App\Support\Installation\Preflight::class)->markerPath();

    expect($marker)->toStartWith(storage_path());
    expect($marker)->toContain('makinpay-installed.json');
});

it('never leaves the real storage directory marked as installed', function (): void {
    expect(file_exists(base_path('storage/app/makinpay-installed.json')))
        ->toBeFalse('A test wrote the installation marker into the real storage directory.');
});
