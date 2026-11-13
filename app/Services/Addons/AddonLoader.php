<?php

declare(strict_types=1);

namespace App\Services\Addons;

use App\Enums\AddonStatus;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Brings installed add-ons into the running application.
 *
 * Every installed add-on contributes its features to the catalog, whatever
 * its status, so FeatureManager always knows who owns a key. Only an add-on
 * that is active AND able to run here (compatible core, required PHP
 * extensions present) gets its classes autoloaded and its service provider
 * registered - and with it its routes, schedule and admin menus. Anything an
 * add-on serves is therefore absent while it is off, and its features read
 * as disabled through FeatureManager, so the existing feature middleware
 * answers 404.
 *
 * Runs from AddonServiceProvider::register(), before Eloquent's connection
 * resolver exists, which is why the installed rows are read through the
 * query builder rather than the Addon model.
 */
final class AddonLoader
{
    public const MENU_SECTION_CODE = 'addons';

    public const MENU_SECTION_ORDER = 85;

    /**
     * @var array<string, AddonStatus>|null
     */
    private ?array $installed = null;

    /**
     * @var array<string, AddonManifest>
     */
    private array $loaded = [];

    /**
     * @var array<string, true>
     */
    private array $autoloaded = [];

    /**
     * @var array<string, list<string>>
     */
    private array $failures = [];

    /**
     * @var array<string, CarbonInterface|null>
     */
    private array $installedAt = [];

    /**
     * @var array<string, string>
     */
    private array $licenseBlockers = [];

    public function __construct(private readonly AddonRegistry $registry) {}

    public function registry(): AddonRegistry
    {
        return $this->registry;
    }

    /**
     * Installed add-ons and their status, keyed by slug. Empty while the
     * `addons` table does not exist yet (fresh install, pre-migration upgrade).
     *
     * @return array<string, AddonStatus>
     */
    public function installed(): array
    {
        return $this->installed ??= $this->readInstalled();
    }

    public function load(Application $app): void
    {
        foreach ($this->installed() as $slug => $status) {
            $manifest = $this->registry->find($slug);

            if ($manifest === null) {
                continue;
            }

            $this->mergeFeatures($manifest);

            if ($status !== AddonStatus::ACTIVE || isset($this->loaded[$slug]) || $this->blockers($manifest) !== []) {
                continue;
            }

            $licenseBlocker = app(AddonLicensing::class)->blocker($manifest, $this->installedAt($slug));

            if ($licenseBlocker !== null) {
                $this->licenseBlockers[$slug] = $licenseBlocker;

                continue;
            }

            unset($this->licenseBlockers[$slug]);

            $this->registerAutoloader($manifest);

            try {
                $app->register($manifest->provider);
            } catch (Throwable $exception) {
                report($exception);
                $this->failures[$slug] = [$exception->getMessage()];

                continue;
            }

            $this->mergeAdminMenus($manifest);
            $this->loaded[$slug] = $manifest;
        }
    }

    public function isLoaded(string $slug): bool
    {
        return isset($this->loaded[$slug]);
    }

    /**
     * @return list<string>
     */
    public function loadedSlugs(): array
    {
        return array_keys($this->loaded);
    }

    public function installedAt(string $slug): ?CarbonInterface
    {
        $this->installed();

        return $this->installedAt[$slug] ?? null;
    }

    /**
     * Active add-ons held back by their license, with the reason.
     *
     * @return array<string, string>
     */
    public function licenseBlockers(): array
    {
        return $this->licenseBlockers;
    }

    /**
     * Add-ons whose provider threw while registering, with the message.
     *
     * @return array<string, list<string>>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Why an add-on cannot run on this server right now; empty when it can.
     *
     * @param  (callable(string): bool)|null $isExtensionLoaded defaults to extension_loaded
     * @return list<string>
     */
    public function blockers(AddonManifest $manifest, ?callable $isExtensionLoaded = null): array
    {
        $reasons = [];

        if (! $this->registry->isCompatible($manifest)) {
            $reasons[] = sprintf(
                'Requires DigiKash %s or later; this site runs %s.',
                $manifest->requiresCore,
                $this->registry->coreVersion(),
            );
        }

        foreach ($manifest->missingExtensions($isExtensionLoaded) as $extension) {
            $reasons[] = "The PHP {$extension} extension is not installed.";
        }

        return $reasons;
    }

    /**
     * The add-on that owns a feature key, or null for a core feature. The
     * catalog marker is authoritative; the stored meta covers a row whose
     * add-on has since been removed from disk.
     *
     * @param array<string, mixed>|null $storedMeta
     */
    public function ownerOf(string $key, ?array $storedMeta = null): ?string
    {
        $features = (array) config('feature_catalog.features', []);
        $owner    = is_array($features[$key] ?? null) ? ($features[$key]['addon'] ?? null) : null;

        if (is_string($owner) && $owner !== '') {
            return $owner;
        }

        $stored = $storedMeta['addon'] ?? null;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * Stop treating an add-on as running for the rest of this request, so its
     * features read as off right after it is disabled or uninstalled. A
     * provider cannot be unregistered; the next request simply never loads it.
     */
    public function unload(string $slug): void
    {
        unset($this->loaded[$slug]);
    }

    /**
     * Forget the installed rows so the next load() reads them again.
     */
    public function forget(): void
    {
        $this->installed = null;
    }

    /**
     * @return array<string, AddonStatus>
     */
    private function readInstalled(): array
    {
        try {
            $rows = app('db')->table('addons')->select(['slug', 'status', 'installed_at'])->get();
        } catch (Throwable) {
            return [];
        }

        $installed = [];

        foreach ($rows as $row) {
            $status = AddonStatus::tryFrom((string) $row->status);

            if ($status !== null) {
                $installed[(string) $row->slug]         = $status;
                $this->installedAt[(string) $row->slug] = AddonLicensing::parseTimestamp($row->installed_at ?? null);
            }
        }

        ksort($installed);

        return $installed;
    }

    /**
     * An add-on's features are created switched off, whatever its manifest
     * says: the admin configures the add-on first and turns them on when it
     * is ready for customers. sync() writes is_enabled only when it creates a
     * row, so a toggle the admin has already set is never changed.
     */
    private function mergeFeatures(AddonManifest $manifest): void
    {
        foreach ($manifest->features as $key => $definition) {
            $definition['is_enabled'] = false;
            $definition['addon']      = $manifest->slug;
            $definition['meta']       = array_replace((array) ($definition['meta'] ?? []), ['addon' => $manifest->slug]);

            config(["feature_catalog.features.{$key}" => $definition]);
        }
    }

    private function registerAutoloader(AddonManifest $manifest): void
    {
        if (isset($this->autoloaded[$manifest->slug])) {
            return;
        }

        $prefix = $manifest->namespace();
        $base   = $manifest->sourcePath().'/';

        spl_autoload_register(static function (string $class) use ($prefix, $base): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });

        $this->autoloaded[$manifest->slug] = true;
    }

    /**
     * Appends the add-on's menu entries to one shared "Add-ons" sidebar
     * section, created the first time an add-on needs it.
     */
    private function mergeAdminMenus(AddonManifest $manifest): void
    {
        if ($manifest->adminMenus === []) {
            return;
        }

        $menus   = (array) config('admin_menus', []);
        $section = null;

        foreach ($menus as $index => $entry) {
            if (is_int($index) && is_array($entry) && ($entry['code'] ?? null) === self::MENU_SECTION_CODE) {
                $section = $index;
            }
        }

        if ($section === null) {
            $menus[] = [
                'label' => 'Add-ons',
                'code'  => self::MENU_SECTION_CODE,
                'order' => self::MENU_SECTION_ORDER,
                'menus' => [],
            ];
            $section = array_key_last($menus);
        }

        foreach ($manifest->adminMenus as $menu) {
            $menus[$section]['menus'][] = $menu;
        }

        config(['admin_menus' => $menus]);
    }
}
