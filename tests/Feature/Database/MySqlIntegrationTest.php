<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MySQL integration
|--------------------------------------------------------------------------
|
| SQLite is the default for the suite: fast, isolated, no server to manage. But
| production runs MySQL, and a handful of behaviours genuinely differ. This file
| checks the ones MakinPay depends on.
|
| It is SKIPPED unless the operator opts in:
|
|   MAKINPAY_ALLOW_MYSQL_TESTS=makinpay_test \
|   DB_CONNECTION=mysql DB_DATABASE=makinpay_test \
|   DB_USERNAME=root DB_PASSWORD= \
|   vendor/bin/pest --group=mysql
|
| The guard in tests/TestCase.php refuses to run against any MySQL database
| whose name does not exactly match MAKINPAY_ALLOW_MYSQL_TESTS. That makes an
| accidental run against a real Laragon schema impossible: the test process
| refuses before a single migration is applied.
|
| NOTE: Pest 2 does not read docblock groups. Each test below therefore carries
| an explicit `->group('mysql')` chain; that chain, not this comment, is what
| makes `composer test:mysql` select these tests.
|
*/

beforeEach(function (): void {
    $optIn = (string) (getenv('MAKINPAY_ALLOW_MYSQL_TESTS') ?: '');

    if ($optIn === '') {
        $this->markTestSkipped(
            'MySQL integration tests are opt-in. Set MAKINPAY_ALLOW_MYSQL_TESTS to the disposable test schema name.'
        );
    }

    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('DB_CONNECTION is not mysql; nothing to verify.');
    }

    if ((string) DB::connection()->getDatabaseName() !== $optIn) {
        $this->markTestSkipped(sprintf(
            'Connected database "%s" is not the declared test schema "%s".',
            DB::connection()->getDatabaseName(),
            $optIn
        ));
    }
});

it('connects to the declared disposable MySQL schema', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect((string) DB::connection()->getDatabaseName())->toBe((string) getenv('MAKINPAY_ALLOW_MYSQL_TESTS'));
})->group('mysql');

it('reports the UTF-8 charset and collation production relies on', function (): void {
    $row = DB::selectOne('SELECT @@character_set_database AS charset, @@collation_database AS collation');

    expect(strtolower((string) $row->charset))->toStartWith('utf8');
    expect(strtolower((string) $row->collation))->toStartWith('utf8');
})->group('mysql');

it('applies every migration on MySQL without error', function (): void {
    /*
     * This is the assertion that catches SQLite-compatible but MySQL-invalid
     * schema: index name lengths, default values on TEXT columns, and foreign
     * keys SQLite silently tolerated.
     */
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('admins'))->toBeTrue();
    expect(Schema::hasTable('payment_gateways'))->toBeTrue();
})->group('mysql');

it('creates an administrator on MySQL with a verifiable password', function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $provisioner = app(App\Support\Installation\AdministratorProvisioner::class);

    $result = $provisioner->provision('MySQL Owner', 'mysql-owner@makinpay.test', 'Str0ngPass!23');

    expect($result['created'])->toBeTrue();

    $admin = App\Models\Admin::query()->where('email', 'mysql-owner@makinpay.test')->firstOrFail();

    expect(Illuminate\Support\Facades\Hash::check('Str0ngPass!23', $admin->password))->toBeTrue();
    expect($admin->hasRole('super-admin'))->toBeTrue();
})->group('mysql');

it('enforces the unique email constraint on MySQL', function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $provisioner = app(App\Support\Installation\AdministratorProvisioner::class);

    $provisioner->provision('First', 'dup@makinpay.test', 'Str0ngPass!23');
    $second = $provisioner->provision('Second', 'dup@makinpay.test', 'Str0ngPass!23');

    expect($second['created'])->toBeFalse();
    expect(App\Models\Admin::query()->where('email', 'dup@makinpay.test')->count())->toBe(1);
})->group('mysql');
