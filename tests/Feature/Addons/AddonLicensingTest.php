<?php

declare(strict_types=1);

use App\Services\Addons\AddonLicensing;
use App\Services\Addons\AddonManifest;

/*
|--------------------------------------------------------------------------
| Add-on licensing enforcement
|--------------------------------------------------------------------------
|
| Add-on licensing was DECOUPLED from the removed core LicenseGuard, not
| removed. These tests protect that decoupling: enforcement must still be ON in
| production, still OFF in local/testing and demo, and still gated per add-on by
| whether the manifest declares an Envato item id.
|
| The `enforced` constructor argument is the class's own seam for pinning this
| decision, so no environment globals are mutated.
|
| No network request is made: the server client is resolved from the container
| but never called by the methods under test.
|
*/

/**
 * Build a real AddonManifest through its factory so the tests exercise the
 * same validation a package installed on disk goes through.
 *
 * @param  array<string, mixed>  $overrides
 */
function manifest(array $overrides = []): AddonManifest
{
    $data = array_merge([
        'slug'          => 'demo-addon',
        'name'          => 'Demo Addon',
        'publisher'     => 'MakinPay',
        'version'       => '1.0.0',
        'requires_core' => '1.0.0',
        'provider'      => 'Addons\\DemoAddon\\DemoAddonServiceProvider',
        'item_id'       => 12345678,
        'description'   => 'Test add-on.',
    ], $overrides);

    $manifest = AddonManifest::fromArray($data, sys_get_temp_dir());

    expect($manifest)->toBeInstanceOf(AddonManifest::class);

    return $manifest;
}

it('enforces add-on licensing in production', function (): void {
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: true);

    expect($licensing->enforced())->toBeTrue();
});

it('does not enforce add-on licensing when explicitly disabled', function (): void {
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: false);

    expect($licensing->enforced())->toBeFalse();
});

it('does not enforce when the add-on license flag is off in config', function (): void {
    config(['project_updater.addon_license_enforce' => false]);
    config(['app.demo' => false]);

    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class));

    expect($licensing->enforced())->toBeFalse();
});

it('does not enforce in a demo deployment', function (): void {
    config(['project_updater.addon_license_enforce' => true]);
    config(['app.demo' => true]);

    $licensing = new App\Services\Addons\AddonLicensing(
        app(App\Services\ProjectUpdateServerClient::class)
    );

    expect($licensing->enforced())->toBeFalse();
});

it('requires a license for an add-on that declares an item id', function (): void {
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: true);

    expect($licensing->requiresLicense(manifest()))->toBeTrue();
});

it('does not require a license for an add-on with no item id', function (): void {
    /*
     * A free or first-party add-on ships without an Envato item id. It must
     * keep working regardless of the enforcement flag.
     */
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: true);

    expect($licensing->requiresLicense(manifest(['item_id' => null])))->toBeFalse();
});

it('does not require a license when enforcement is off', function (): void {
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: false);

    expect($licensing->requiresLicense(manifest()))->toBeFalse();
});

it('reports NOT_REQUIRED for an add-on with no item id', function (): void {
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: true);

    expect($licensing->state(manifest(['item_id' => null])))->toBe(AddonLicensing::NOT_REQUIRED);
});

it('reports NOT_REQUIRED when enforcement is off', function (): void {
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: false);

    expect($licensing->state(manifest()))->toBe(AddonLicensing::NOT_REQUIRED);
});

it('reports an unlicensed add-on as needing activation, not as valid', function (): void {
    /*
     * No `project_licenses` row exists for this slug, so a licensed add-on must
     * not read as active. This is the assertion that would catch the old
     * LicenseGuard defect (B3), where every refresh persisted VALID.
     */
    $licensing = new AddonLicensing(app(App\Services\ProjectUpdateServerClient::class), enforced: true);

    $state = $licensing->state(manifest());

    expect($state)->not->toBe(AddonLicensing::NOT_REQUIRED);
    expect($state)->not->toBe(AddonLicensing::VALID);
});
