<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Support\Installation\AdministratorProvisioner;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Administrator provisioning
|--------------------------------------------------------------------------
|
| The provisioner is a security boundary, so these tests assert behaviour
| rather than implementation: the password is hashed exactly once and verifies,
| a weak password can never be written, an existing account is never silently
| overwritten, and the account ends up with the super-admin role.
|
| Migrating for every test costs ~7s each. The database is migrated once per
| process and every test runs inside a transaction that is rolled back, so
| they stay isolated without paying that cost repeatedly.
|
*/

const STRONG_PASSWORD = 'Str0ngPass!23';

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    /*
     * Run the project's real migrations rather than hand-building a reduced
     * schema. Spatie's permission package needs its own pivot tables
     * (role_has_permissions, model_has_permissions), and re-implementing them
     * here would test a schema the application never actually has.
     */
    $this->provisioner = app(AdministratorProvisioner::class);
});

it('creates an administrator with the super-admin role', function (): void {
    $result = $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    expect($result['created'])->toBeTrue()
        ->and($result['admin'])->toBeInstanceOf(Admin::class)
        ->and($result['reference'] ?? '')->toBe('');

    $admin = Admin::query()->where('email', 'owner@makinpay.test')->firstOrFail();

    expect($admin->name)->toBe('Site Owner')
        ->and($admin->status)->toBeTrue()
        ->and($admin->hasRole('super-admin'))->toBeTrue();
});

it('hashes the password exactly once and verifies it', function (): void {
    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    $admin = Admin::query()->where('email', 'owner@makinpay.test')->firstOrFail();

    // A bcrypt hash, not the plain value.
    expect($admin->password)->not->toBe(STRONG_PASSWORD)
        ->and($admin->password)->toStartWith('$2y$');

    // Verifies against the plain value exactly once. A double hash would fail
    // this assertion, which is the failure mode of assigning Hash::make() to a
    // model that already casts `password` to `hashed`.
    expect(Hash::check(STRONG_PASSWORD, $admin->password))->toBeTrue();
    expect(Hash::check($admin->password, $admin->password))->toBeFalse();
});

it('rejects a wrong password for a created administrator', function (): void {
    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    $admin = Admin::query()->where('email', 'owner@makinpay.test')->firstOrFail();

    expect(Hash::check('WrongPassworD2', $admin->password))->toBeFalse();
});

it('normalises the email to lower case', function (): void {
    $this->provisioner->provision('Site Owner', 'Owner@MakinPay.Test', STRONG_PASSWORD);

    expect(Admin::query()->where('email', 'owner@makinpay.test')->exists())->toBeTrue();
});

it('refuses a password that fails the policy', function (string $password): void {
    $result = $this->provisioner->provision('Site Owner', 'owner@makinpay.test', $password);

    expect($result['created'])->toBeFalse()
        ->and($result['admin'])->toBeNull()
        ->and($result['reason'])->not->toBe('');

    expect(Admin::query()->count())->toBe(0);
})->with([
    'too short'          => ['Sh0rt'],
    'no upper case'      => ['lowercase123'],
    'no lower case'      => ['UPPERCASE123'],
    'no digit'           => ['NoDigitsHere'],
    'empty'              => [''],
]);

it('enforces the password policy even when the caller skips validation', function (): void {
    // provision() re-validates internally, so a future caller cannot bypass
    // the policy by forgetting to call validate() first.
    $result = $this->provisioner->provision('Site Owner', 'owner@makinpay.test', 'weak');

    expect($result['created'])->toBeFalse();
    expect(Admin::query()->count())->toBe(0);
});

it('reports a mismatched confirmation from validate()', function (): void {
    $errors = $this->provisioner->validate(
        'Site Owner',
        'owner@makinpay.test',
        STRONG_PASSWORD,
        STRONG_PASSWORD.'x'
    );

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('do not match');
});

it('accepts a matching confirmation', function (): void {
    $errors = $this->provisioner->validate(
        'Site Owner',
        'owner@makinpay.test',
        STRONG_PASSWORD,
        STRONG_PASSWORD
    );

    expect($errors)->toBe([]);
});

it('rejects an invalid email address', function (): void {
    $errors = $this->provisioner->validate('Site Owner', 'not-an-email', STRONG_PASSWORD, STRONG_PASSWORD);

    expect($errors)->not->toBeEmpty();
});

it('refuses to create a duplicate administrator', function (): void {
    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    $second = $this->provisioner->provision('Other Owner', 'owner@makinpay.test', 'Different1Pass');

    expect($second['created'])->toBeFalse()
        ->and($second['reason'])->toContain('already exists');

    expect(Admin::query()->count())->toBe(1);
});

it('leaves the existing password untouched when a duplicate is refused', function (): void {
    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', 'Different1Pass');

    $admin = Admin::query()->where('email', 'owner@makinpay.test')->firstOrFail();

    expect(Hash::check(STRONG_PASSWORD, $admin->password))->toBeTrue()
        ->and(Hash::check('Different1Pass', $admin->password))->toBeFalse();
});

it('resets the password only when overwrite is explicit', function (): void {
    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    $result = $this->provisioner->provision('Site Owner', 'owner@makinpay.test', 'Different1Pass', overwrite: true);

    expect($result['created'])->toBeFalse(); // updated, not created

    $admin = Admin::query()->where('email', 'owner@makinpay.test')->firstOrFail();

    expect(Hash::check('Different1Pass', $admin->password))->toBeTrue();
    expect(Admin::query()->count())->toBe(1);
});

it('reports whether any administrator exists', function (): void {
    expect($this->provisioner->hasAnyAdministrator())->toBeFalse();

    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    expect($this->provisioner->hasAnyAdministrator())->toBeTrue();
});

it('generates a password that satisfies its own policy', function (): void {
    $password = $this->provisioner->generatePassword();

    expect(strlen($password))->toBeGreaterThanOrEqual(8);

    $errors = $this->provisioner->validate('Site Owner', 'owner@makinpay.test', $password, $password);

    expect($errors)->toBe([]);
});

it('generates a different password each time', function (): void {
    $first  = $this->provisioner->generatePassword();
    $second = $this->provisioner->generatePassword();

    expect($first)->not->toBe($second);
});

it('never writes the plain password anywhere in the admin row', function (): void {
    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    $row = (array) DB::table('admins')->where('email', 'owner@makinpay.test')->first();

    foreach ($row as $column => $value) {
        if (is_string($value)) {
            expect($value)->not->toContain(STRONG_PASSWORD, "Column {$column} leaked the plain password.");
        }
    }
});

it('creates the super-admin role when seeding was skipped', function (): void {
    expect(Role::query()->where('name', 'super-admin')->exists())->toBeFalse();

    $this->provisioner->provision('Site Owner', 'owner@makinpay.test', STRONG_PASSWORD);

    $role = Role::query()->where('name', 'super-admin')->first();

    expect($role)->not->toBeNull()
        ->and($role->guard_name)->toBe('admin');
});
