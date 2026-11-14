<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Baseline seeding
|--------------------------------------------------------------------------
|
| Runs the real `--seed-baseline` path against a disposable file database.
| This is the only test that pays the cost of all 39 core seeders, so it lives
| in the `serial` group (it owns a file outside the transaction-wrapped
| in-memory database) and is excluded from parallel runs.
|
| It asserts the properties an operator depends on after a fresh install:
| permissions exist and reach super-admin, payment gateways are registered, and
| no demonstration user accounts are created.
|
| @group serial
|
*/

beforeEach(function (): void {
    /*
     * A fresh in-memory database per test. RefreshDatabase would be faster but
     * the seeders are the subject here, so they are invoked explicitly.
     */
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

it('seeds the permission set and grants it to super-admin', function (): void {
    $this->artisan('makinpay:install', [
        '--force'           => true,
        '--no-interaction'  => true,
        '--seed-baseline'   => true,
        '--allow-existing'  => true,
    ])->assertSuccessful();

    $permissionCount = DB::table('permissions')->count();

    expect($permissionCount)->toBeGreaterThan(0);

    $superAdmin = Spatie\Permission\Models\Role::query()
        ->where('name', 'super-admin')
        ->where('guard_name', 'admin')
        ->first();

    expect($superAdmin)->not->toBeNull();

    // The role must actually carry permissions, not merely exist.
    $granted = DB::table('role_has_permissions')
        ->where('role_id', $superAdmin->id)
        ->count();

    expect($granted)->toBeGreaterThan(0);
})->group('serial');

it('registers payment gateways during baseline seeding', function (): void {
    $this->artisan('makinpay:install', [
        '--force'          => true,
        '--no-interaction' => true,
        '--seed-baseline'  => true,
        '--allow-existing' => true,
    ])->assertSuccessful();

    $gateways = DB::table('payment_gateways')->count();

    expect($gateways)->toBeGreaterThan(0);
})->group('serial');

it('does not create demonstration user accounts during a baseline install', function (): void {
    $this->artisan('makinpay:install', [
        '--force'          => true,
        '--no-interaction' => true,
        '--seed-baseline'  => true,
        '--allow-existing' => true,
    ])->assertSuccessful();

    /*
     * `--seed-baseline` seeds reference data only. Demo accounts belong to the
     * `--demo` option, which is refused in production. If this regresses, every
     * customer install would ship with working demonstration logins.
     */
    expect(DB::table('users')->where('email', 'like', '%@example.com')->count())->toBe(0);
})->group('serial');

it('never runs demo seeders in production even when asked', function (): void {
    $original = app()->environment();

    app()->detectEnvironment(fn (): string => 'production');

    try {
        $exit = Illuminate\Support\Facades\Artisan::call('makinpay:install', [
            '--force'          => true,
            '--no-interaction' => true,
            '--seed-baseline'  => true,
            '--demo'           => true,
            '--allow-existing' => true,
        ]);

        expect($exit)->toBe(0);
    } finally {
        app()->detectEnvironment(fn () => $original);
    }

    /*
     * The refusal is announced through the console warning channel, which
     * Artisan::output() does not capture (it is asserted in
     * InstallCommandTest). What matters here is the outcome: no demo account
     * may exist after a production run that asked for them.
     */
    expect(DB::table('users')->where('email', 'like', '%@example.com')->count())->toBe(0);
})->group('serial');
