<?php

declare(strict_types=1);

namespace App\Services\Addons;

use App\Enums\AddonStatus;
use App\Models\Addon;
use App\Models\Feature;
use App\Services\FeatureManager;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Installs, enables, disables, updates and uninstalls add-ons.
 *
 * Every operation holds a per-add-on lock, so two admins (or a double click)
 * cannot run two migrations for one add-on at once. Requirements are checked
 * at install (core version, PHP extensions) and again at every enable (plus
 * cron and queue when the manifest asks), and the operator is told exactly
 * what to fix. An update keeps a copy of the previous version and puts it
 * back when the new version's migrations fail.
 */
final class AddonManager
{
    public const LOCK_SECONDS = 600;

    public function __construct(
        private readonly AddonRegistry $registry,
        private readonly AddonLoader $loader,
        private readonly AddonRequirements $requirements,
        private readonly FeatureManager $features,
        private readonly AddonLicensing $licensing,
    ) {}

    /**
     * Installs an add-on whose code is already in addons/<slug>.
     *
     * @throws AddonOperationFailed
     */
    public function install(string $slug): Addon
    {
        return $this->locked($slug, function () use ($slug): Addon {
            $manifest = $this->manifestOrFail($slug);
            $failures = $this->requirements->failures($manifest, includeRuntime: false);

            if ($failures !== []) {
                throw new AddonOperationFailed($failures);
            }

            $addon = Addon::query()->firstOrNew(['slug' => $slug]);

            if ($addon->exists && $addon->status !== AddonStatus::FAILED) {
                throw AddonOperationFailed::because("{$manifest->name} is already installed.");
            }

            $addon->fill([
                'name'         => $manifest->name,
                'version'      => $manifest->version,
                'status'       => AddonStatus::INSTALLED,
                'last_error'   => null,
                'installed_at' => now(),
                'enabled_at'   => null,
            ])->save();

            $ranBefore = $this->ranMigrations();

            try {
                $this->migrate($manifest);
            } catch (Throwable $exception) {
                $this->rollBackThisAttempt($manifest, $ranBefore);
                $addon->forceFill(['status' => AddonStatus::FAILED, 'last_error' => $exception->getMessage()])->save();

                throw AddonOperationFailed::because('A database migration failed and the ones before it were rolled back: '.$exception->getMessage());
            }

            $this->seedPermissions($manifest);
            $this->refreshRuntime();
            $this->features->sync();

            return $addon->refresh();
        });
    }

    /**
     * Installs, or updates, from an uploaded package.
     *
     * @throws AddonOperationFailed
     */
    public function installPackage(string $zipPath): Addon
    {
        $staged = (new AddonPackage)->stage($zipPath, storage_path('app/addon-packages'));
        $slug   = $staged['manifest']->slug;

        try {
            $installed = Addon::query()->where('slug', $slug)->where('status', '!=', AddonStatus::FAILED->value)->exists();

            if ($installed) {
                return $this->markSigned($this->update($slug, $staged['manifest'], $staged['path']));
            }

            $target = $this->registry->path().'/'.$slug;

            File::ensureDirectoryExists($this->registry->path());
            File::deleteDirectory($target);

            if (! File::copyDirectory($staged['path'], $target)) {
                throw AddonOperationFailed::because('The add-on files could not be moved into the addons folder. Check that it is writable.');
            }

            $this->registry->refresh();

            return $this->markSigned($this->install($slug));
        } finally {
            File::deleteDirectory($staged['staging']);
        }
    }

    /**
     * @throws AddonOperationFailed
     */
    public function enable(string $slug): Addon
    {
        return $this->locked($slug, function () use ($slug): Addon {
            $addon    = $this->installedOrFail($slug);
            $manifest = $this->manifestOrFail($slug);

            if ($addon->status === AddonStatus::FAILED) {
                throw AddonOperationFailed::because('The last install did not finish. Install it again before enabling it.');
            }

            $failures = $this->requirements->failures($manifest);
            $license  = $this->licensing->blocker($manifest, $addon->installed_at);

            if ($license !== null) {
                $failures[] = $license;
            }

            if ($failures !== []) {
                throw new AddonOperationFailed($failures);
            }

            $addon->forceFill(['status' => AddonStatus::ACTIVE, 'enabled_at' => now(), 'last_error' => null])->save();
            $this->refreshRuntime();

            return $addon->refresh();
        });
    }

    /**
     * @throws AddonOperationFailed
     */
    public function disable(string $slug): Addon
    {
        return $this->locked($slug, function () use ($slug): Addon {
            $addon = $this->installedOrFail($slug);
            $this->assertNothingRunningNeeds($slug);

            $addon->forceFill(['status' => AddonStatus::DISABLED])->save();
            $this->loader->unload($slug);
            $this->refreshRuntime();

            return $addon->refresh();
        });
    }

    /**
     * Removes the add-on's installation. Its folder stays on disk, where it
     * shows as "not installed". With $keepData its tables, permissions and
     * feature rows (and the admin's settings on them) survive a reinstall.
     *
     * @throws AddonOperationFailed
     */
    public function uninstall(string $slug, bool $keepData): void
    {
        $this->locked($slug, function () use ($slug, $keepData): void {
            $addon    = $this->installedOrFail($slug);
            $manifest = $this->registry->find($slug);
            $this->assertNothingRunningNeeds($slug);

            if (! $keepData) {
                if ($manifest !== null && is_dir($manifest->migrationsPath())) {
                    try {
                        $this->runArtisan('migrate:reset', ['--path' => $manifest->migrationsPath(), '--realpath' => true, '--force' => true]);
                    } catch (Throwable $exception) {
                        throw AddonOperationFailed::because('Its tables could not be removed: '.$exception->getMessage());
                    }
                }

                Permission::query()->where('category', $this->permissionCategory($slug))->delete();
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                Feature::query()->get()
                    ->filter(fn (Feature $feature): bool => ($feature->meta['addon'] ?? null) === $slug)
                    ->each(fn (Feature $feature) => $feature->delete());
            }

            $addon->delete();
            $this->loader->unload($slug);
            $this->refreshRuntime();
        });
    }

    /**
     * Replaces an installed add-on's code with a newer version and runs its
     * migrations; on failure the previous version's files are put back.
     *
     * @throws AddonOperationFailed
     */
    public function update(string $slug, AddonManifest $incoming, string $incomingPath): Addon
    {
        return $this->locked($slug, function () use ($slug, $incoming, $incomingPath): Addon {
            $addon = $this->installedOrFail($slug);

            if (version_compare($incoming->version, $addon->version, '<=')) {
                throw AddonOperationFailed::because("Version {$addon->version} is already installed; this package is {$incoming->version}.");
            }

            $failures = $this->requirements->failures($incoming, includeRuntime: false);

            if ($failures !== []) {
                throw new AddonOperationFailed($failures);
            }

            $target = $this->registry->path().'/'.$slug;
            $backup = storage_path('app/addon-backups/'.$slug.'-'.$addon->version.'-'.now()->getTimestamp());

            if (! File::copyDirectory($target, $backup)) {
                throw AddonOperationFailed::because('The current version could not be backed up, so the update was not started.');
            }

            File::deleteDirectory($target);
            File::copyDirectory($incomingPath, $target);
            $this->registry->refresh();

            $incomingManifest = $this->manifestOrFail($slug);
            $ranBefore        = $this->ranMigrations();

            try {
                $this->migrate($incomingManifest);
            } catch (Throwable $exception) {
                $this->rollBackThisAttempt($incomingManifest, $ranBefore);

                File::deleteDirectory($target);
                File::copyDirectory($backup, $target);
                $this->registry->refresh();

                $addon->forceFill(['last_error' => $exception->getMessage()])->save();

                throw AddonOperationFailed::because("The update failed and version {$addon->version} was restored: ".$exception->getMessage());
            }

            $addon->forceFill(['name' => $incoming->name, 'version' => $incoming->version, 'last_error' => null])->save();

            $this->seedPermissions($incoming);
            $this->refreshRuntime();
            $this->features->sync();

            return $addon->refresh();
        });
    }

    /**
     * Undo the migrations a failed install or update managed to run before
     * the one that failed - while that version's files are still in place -
     * so a failed install leaves nothing behind and a restored version never
     * runs against a schema it has never seen. They form the newest batch,
     * which is what migrate:rollback reverts.
     *
     * @param list<string> $ranBefore
     */
    private function rollBackThisAttempt(AddonManifest $incoming, array $ranBefore): void
    {
        if (count($this->ranMigrations()) <= count($ranBefore)) {
            return;
        }

        try {
            $this->runArtisan('migrate:rollback', ['--path' => $incoming->migrationsPath(), '--realpath' => true, '--force' => true]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return list<string>
     */
    private function ranMigrations(): array
    {
        try {
            return app('migrator')->getRepository()->getRan();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Only a package that passed AddonPackage's signature check reaches here,
     * so the add-on is recorded as Coevs-signed; a folder copied in by hand
     * never is.
     */
    private function markSigned(Addon $addon): Addon
    {
        $addon->forceFill(['meta' => array_replace((array) $addon->meta, ['signed_by' => 'coevs'])])->save();

        return $addon;
    }

    private function migrate(AddonManifest $manifest): void
    {
        if (! is_dir($manifest->migrationsPath())) {
            return;
        }

        $this->runArtisan('migrate', ['--path' => $manifest->migrationsPath(), '--realpath' => true, '--force' => true]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters): void
    {
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: "{$command} did not finish.");
        }
    }

    private function seedPermissions(AddonManifest $manifest): void
    {
        if ($manifest->permissions === []) {
            return;
        }

        $created = [];

        foreach ($manifest->permissions as $name) {
            $created[] = Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                ['category' => $this->permissionCategory($manifest->slug)],
            );
        }

        $superAdmin = Role::query()->where('guard_name', 'admin')->where('name', 'super-admin')->first();

        if ($superAdmin instanceof Role) {
            $superAdmin->givePermissionTo($created);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionCategory(string $slug): string
    {
        return 'addon:'.$slug;
    }

    /**
     * Re-reads installed add-ons, merges their features into the catalog and
     * loads any that just became active, then drops cached routes so a
     * disabled add-on's routes leave with it.
     */
    private function refreshRuntime(): void
    {
        $this->loader->forget();
        $this->loader->load(app());
        $this->features->flush();

        if (app()->routesAreCached()) {
            Artisan::call('route:clear');
        }
    }

    private function manifestOrFail(string $slug): AddonManifest
    {
        $this->registry->refresh();
        $manifest = $this->registry->find($slug);

        if ($manifest !== null) {
            return $manifest;
        }

        $problems = $this->registry->problems()[$slug] ?? ["No add-on named {$slug} was found in the addons folder."];

        throw new AddonOperationFailed($problems);
    }

    /**
     * An add-on another running add-on requires stays: switching it off
     * under the other one would leave that one on while its calls land on
     * nothing. The operator disables the dependent add-on first.
     *
     * @throws AddonOperationFailed
     */
    private function assertNothingRunningNeeds(string $slug): void
    {
        $dependents = [];

        foreach ($this->registry->all() as $manifest) {
            if ($manifest->slug === $slug || ! isset($manifest->requiresAddons[$slug])) {
                continue;
            }

            if (Addon::query()->where('slug', $manifest->slug)->where('status', AddonStatus::ACTIVE)->exists()) {
                $dependents[] = $manifest->name;
            }
        }

        if ($dependents !== []) {
            throw AddonOperationFailed::because(sprintf('%s needs it. Disable %s first.', implode(' and ', $dependents), count($dependents) === 1 ? 'that add-on' : 'those add-ons'));
        }
    }

    private function installedOrFail(string $slug): Addon
    {
        $addon = Addon::query()->where('slug', $slug)->first();

        if (! $addon instanceof Addon) {
            throw AddonOperationFailed::because("The add-on {$slug} is not installed.");
        }

        return $addon;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult $operation
     * @return TResult
     */
    private function locked(string $slug, Closure $operation): mixed
    {
        $lock = Cache::lock('addon-operation:'.$slug, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw AddonOperationFailed::because('Another operation on this add-on is already running. Try again in a moment.');
        }

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
