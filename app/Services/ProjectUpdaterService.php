<?php

namespace App\Services;

use App\Exceptions\NotifyErrorException;
use App\Models\ProjectLicense;
use App\Models\ProjectUpdate;
use App\Support\AdminShell;
use App\Support\MemoryLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use ZipArchive;

class ProjectUpdaterService
{
    /**
     * After this long, "Up to date" is a claim the page cannot make.
     *
     * The status is only ever as fresh as the last check, and this
     * installation was showing a confident verdict from a check three months
     * old. Past the window the page says the check is out of date instead.
     */
    public const STALE_CHECK_DAYS = 7;

    /** Install and rollback rewrite the same directory; only one may run. */
    private const RUN_LOCK = 'project-updater:run';

    private bool $licenseRefreshed = false;

    public function __construct(private readonly ProjectUpdateServerClient $client) {}

    public function licenseWasRefreshed(): bool
    {
        return $this->licenseRefreshed;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        /*
         * The newest check, not the highest version: every check returns the
         * server's current release, so the latest check result is the latest
         * release the server knows about. Whether that release is ahead of
         * the running code is a separate question the row answers itself,
         * through isNewerThanInstalled().
         */
        $latest = ProjectUpdate::query()->latest('checked_at')->latest('id')->first();

        return [
            'license'      => ProjectLicense::current(),
            'latest'       => $latest,
            'history'      => ProjectUpdate::query()->latest('created_at')->limit(10)->get(),
            'checks'       => $this->environmentChecks(),
            'backup'       => $this->recoveryBackupSummary(),
            'checkIsStale' => $latest?->checked_at === null
                || $latest->checked_at->lt(now()->subDays(self::STALE_CHECK_DAYS)),
            'failureCategory' => $latest && $latest->status === 'failed'
                ? $this->classifyInstallError((string) $latest->error_message)
                : null,
        ];
    }

    /**
     * The newest recovery snapshot on disk, or null when none was ever taken.
     *
     * The updater page has to answer "is there something to fall back on?"
     * before an operator presses install, and the only honest source for that
     * is the backups directory itself — a ProjectUpdate row records the path
     * it *wrote*, not whether the archive is still there. Missing directory,
     * unreadable disk or a driver without a local path all read as "no
     * backup" rather than throwing on a page render.
     *
     * @return array{name: string, path: string, bytes: float, taken_at: Carbon}|null
     */
    public function recoveryBackupSummary(): ?array
    {
        try {
            $root = trim((string) config('project_updater.backups_path'), '/');
            $disk = Storage::disk((string) config('project_updater.storage_disk'));

            if (! $disk->exists($root)) {
                return null;
            }

            $absoluteRoot = $disk->path($root);
            $candidates   = array_merge(
                File::directories($absoluteRoot),
                File::glob($absoluteRoot.'/downloads/*.zip') ?: []
            );

            $newest    = null;
            $newestAge = -1;

            foreach ($candidates as $candidate) {
                if (basename($candidate) === 'downloads') {
                    continue;
                }

                $modified = @filemtime($candidate);

                if ($modified !== false && $modified > $newestAge) {
                    $newest    = $candidate;
                    $newestAge = $modified;
                }
            }

            if ($newest === null) {
                return null;
            }

            $relative = ltrim(str_replace('\\', '/', mb_substr($newest, mb_strlen($absoluteRoot))), '/');

            return [
                'name'  => basename($newest),
                'path'  => 'storage/app/'.$root.'/'.$relative,
                'bytes' => $this->pathSizeBytes($newest),
                /* filemtime is a UTC epoch; the page prints it in the site's zone. */
                'taken_at' => Carbon::createFromTimestamp($newestAge, config('app.timezone')),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Bytes on disk for a file, or for every file under a directory.
     */
    private function pathSizeBytes(string $path): float
    {
        if (is_file($path)) {
            $size = @filesize($path);

            return $size === false ? 0.0 : (float) $size;
        }

        $total = 0.0;

        foreach (File::allFiles($path) as $file) {
            $total += (float) $file->getSize();
        }

        return $total;
    }

    /**
     * Buckets a raw install error message into one of a handful of known
     * categories so the UI can highlight the matching troubleshooting section.
     */
    public function classifyInstallError(?string $message): ?string
    {
        if (blank($message)) {
            return null;
        }

        $lower = mb_strtolower((string) $message);

        if (str_contains($lower, 'permission denied')
            || str_contains($lower, 'failed to open stream')
            || str_contains($lower, 'not writable')
            || str_contains($lower, 'is not readable')
            || str_contains($lower, 'operation not permitted')) {
            return 'permissions';
        }

        if (str_contains($lower, 'maximum execution time')
            || str_contains($lower, 'execution time of')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'allowed memory size')) {
            return 'timeout';
        }

        if (str_contains($lower, 'no space left')
            || str_contains($lower, 'enospc')
            || str_contains($lower, 'disk full')
            || str_contains($lower, 'disk space')) {
            return 'disk';
        }

        if (str_contains($lower, 'sqlstate')
            || str_contains($lower, 'pdo')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'database')
            || str_contains($lower, 'migration')) {
            return 'database';
        }

        if (str_contains($lower, 'zip')
            || str_contains($lower, 'could not be opened')
            || str_contains($lower, 'could not be unpacked')) {
            return 'zip';
        }

        return 'other';
    }

    public function activate(string $purchaseCode): ProjectLicense
    {
        $data    = $this->client->activate($purchaseCode);
        $license = $data['license'] ?? $data;

        if (! is_array($license)) {
            throw new NotifyErrorException(__('The license activation response was invalid.'));
        }

        return $this->storeActivation(
            (string) config('project_updater.product_slug'),
            (string) config('project_updater.item_id'),
            $purchaseCode,
            $license,
        );
    }

    /**
     * Persists a server-confirmed activation for this domain - core's, or an
     * add-on's under its own product slug - replacing any earlier one.
     *
     * @param array<string, mixed> $license
     */
    public function storeActivation(string $productSlug, string $defaultItemId, string $purchaseCode, array $license): ProjectLicense
    {
        $slug   = $productSlug;
        $domain = request()->getHost();

        $values = [
            'item_id'         => (string) ($license['item_id'] ?? $defaultItemId),
            'purchase_code'   => $purchaseCode,
            'license_token'   => $license['token']  ?? $license['license_token'] ?? null,
            'buyer_username'  => $license['buyer']  ?? $license['buyer_username'] ?? null,
            'status'          => $license['status'] ?? 'active',
            'support_until'   => ProjectLicense::normalizeSupportUntil($license['supported_until'] ?? $license['support_until'] ?? null),
            'activated_at'    => now(),
            'last_checked_at' => now(),
            'metadata'        => $license,
        ];

        $model = ProjectLicense::query()
            ->where('product_slug', $slug)
            ->where('domain', $domain)
            ->first();

        if ($model) {
            // Wipe the existing encrypted purchase_code from the model's raw
            // attributes (and sync the original) BEFORE filling new values, so
            // save()'s dirty-check doesn't try to decrypt a stored cipher that
            // was written under a rotated/lost APP_KEY. Without this, the cast
            // throws DecryptException("MAC is invalid") during isDirty().
            $rawAttributes                  = $model->getAttributes();
            $rawAttributes['purchase_code'] = null;
            $model->setRawAttributes($rawAttributes, sync: true);

            $model->fill($values);
            $model->save();

            return $model;
        }

        return ProjectLicense::query()->create(array_merge($values, [
            'product_slug' => $slug,
            'domain'       => $domain,
        ]));
    }

    public function checkForUpdates(): ProjectUpdate
    {
        $this->licenseRefreshed = false;

        $license = ProjectLicense::current();

        if (! $license?->isActive()) {
            throw new NotifyErrorException(__('Activate the project license before checking for updates.'));
        }

        // If the local token is missing entirely, refresh it up-front using the stored
        // purchase code so the user does not have to manually re-activate.
        $storedPurchaseCode = $license->safePurchaseCode();

        if (blank($license->license_token) && filled($storedPurchaseCode)) {
            $license                = $this->activate($storedPurchaseCode);
            $storedPurchaseCode     = $license->safePurchaseCode();
            $this->licenseRefreshed = true;
        }

        try {
            $data = $this->client->check($license->license_token);
        } catch (NotifyErrorException $e) {
            // The server rejected the stored token. If we have the original purchase
            // code we can transparently refresh and retry once — most token-mismatch
            // errors are caused by the licensing server rotating or losing the token.
            if (! $this->isLicenseTokenError($e->getMessage())) {
                throw $e;
            }

            if (blank($storedPurchaseCode)) {
                // Differentiate "no purchase code stored" from "purchase code can't be
                // decrypted" so the operator knows whether to dig out their Envato
                // code or to investigate APP_KEY rotation.
                $rawPurchaseCode = $license->getRawOriginal('purchase_code');

                throw new NotifyErrorException(filled($rawPurchaseCode)
                    ? __('The license token is invalid and the stored purchase code could not be decrypted (the application encryption key may have changed). Please re-activate the license with your Envato purchase code.')
                    : __('The license token is no longer valid and cannot be refreshed automatically. Please re-activate the license with your purchase code.'));
            }

            try {
                $license = $this->activate($storedPurchaseCode);
            } catch (NotifyErrorException $refreshError) {
                throw new NotifyErrorException(__(
                    'Update check failed (:original) and license refresh also failed (:refresh).',
                    ['original' => $e->getMessage(), 'refresh' => $refreshError->getMessage()]
                ));
            }

            $this->licenseRefreshed = true;
            $data                   = $this->client->check($license->license_token);
        }

        $update = $data['update'] ?? $data;

        if (! is_array($update)) {
            throw new NotifyErrorException(__('The update check response was invalid.'));
        }

        $license->forceFill(['last_checked_at' => now()])->save();

        $checked = ProjectUpdate::query()->updateOrCreate(
            [
                'version' => (string) ($update['version'] ?? config('app.version')),
                'channel' => (string) ($update['channel'] ?? config('project_updater.channel')),
            ],
            [
                'status' => version_compare((string) ($update['version'] ?? config('app.version')), (string) config('app.version'), '>')
                    ? 'available'
                    : 'current',
                'package_url'   => $update['package_url']  ?? null,
                'checksum'      => $update['checksum']     ?? null,
                'signature'     => $update['signature']    ?? null,
                'changelog'     => $update['changelog']    ?? [],
                'requirements'  => $update['requirements'] ?? [],
                'release_date'  => $update['release_date'] ?? null,
                'checked_at'    => now(),
                'metadata'      => $update,
                'error_message' => null,
            ]
        );

        /* The sidebar foot's update badge reads this row through a cache. */
        Cache::forget(AdminShell::UPDATES_CACHE_KEY.':'.config('app.version'));

        return $checked;
    }

    public function refreshLicense(): ProjectLicense
    {
        $license = ProjectLicense::current();

        if (! $license) {
            throw new NotifyErrorException(__('No license is currently activated.'));
        }

        $purchaseCode = $license->safePurchaseCode();

        if (blank($purchaseCode)) {
            $rawPurchaseCode = $license->getRawOriginal('purchase_code');

            throw new NotifyErrorException(filled($rawPurchaseCode)
                ? __('Cannot refresh the license because the stored purchase code could not be decrypted (the application encryption key may have changed). Please re-activate the license with your Envato purchase code.')
                : __('Cannot refresh the license because the original purchase code is not stored. Please activate the license again.'));
        }

        $refreshed              = $this->activate($purchaseCode);
        $this->licenseRefreshed = true;

        return $refreshed;
    }

    /**
     * Rewrite the 'version' line in config/project_updater.php so the next
     * request reads the freshly-installed version. Idempotent: a no-op if the
     * file is missing, unwritable, or already on the right version.
     */
    public function writeInstalledVersionToConfig(string $version): void
    {
        $version    = trim($version);
        $configPath = config_path('project_updater.php');

        if ($version === '' || ! File::exists($configPath) || ! File::isWritable($configPath)) {
            return;
        }

        $original = (string) File::get($configPath);

        // Replace only the first top-level `'version' => '...'` to avoid
        // touching unrelated version strings further down in the file.
        $pattern = "/('version'\s*=>\s*)'(?:\\\\.|[^'\\\\])*'/u";

        $replaced = preg_replace(
            $pattern,
            '$1'."'".addcslashes($version, "\\'")."'",
            $original,
            1,
            $count
        );

        if ($replaced === null || $count === 0 || $replaced === $original) {
            return;
        }

        File::put($configPath, $replaced);
    }

    /**
     * Refuse a package this installation cannot take whole.
     *
     * The update server sends `requirements` with every release — `app` is
     * the oldest DigiKash version the package can be laid over (a delta built
     * from 2.7.0 carries only the files changed since 2.7.0), `php` the oldest
     * PHP it runs on. They used to be stored and never read, so a 2.6.4 site
     * offered a 2.7.0-based delta installed half a release and broke. A
     * constraint that is not a plain version comparison is left to the server
     * rather than guessed at.
     */
    private function assertRequirementsMet(ProjectUpdate $update): void
    {
        $requirements = is_array($update->requirements) ? $update->requirements : [];

        $checks = [
            'app' => [(string) config('app.version'), 'DigiKash'],
            'php' => [PHP_VERSION, 'PHP'],
        ];

        foreach ($checks as $key => [$current, $label]) {
            $constraint = trim((string) ($requirements[$key] ?? ''));

            if (preg_match('/^(>=|<=|==|=|>|<)?\s*v?(\d+(?:\.\d+)*)$/i', $constraint, $match) !== 1) {
                continue;
            }

            $operator = match ($match[1]) {
                '' => '>=',
                '=', '==' => '==',
                default => $match[1],
            };

            if (! version_compare($current, $match[2], $operator)) {
                throw new NotifyErrorException(__(
                    'Version :target needs :label :constraint, and this site runs :current. Nothing was changed. Install the releases in between first, or run a fresh update check.',
                    ['target' => $update->version, 'label' => $label, 'constraint' => $operator.' '.$match[2], 'current' => $current]
                ));
            }
        }
    }

    private function isLicenseTokenError(string $message): bool
    {
        $lower = mb_strtolower($message);

        foreach ([
            'unknown license token',
            'invalid license token',
            'license token',
            'license_token',
            'unknown token',
            'invalid token',
            'token expired',
            'token invalid',
            'token not found',
            'token mismatch',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function install(ProjectUpdate $update, bool $includeSystemBackup = false): ProjectUpdate
    {
        if (! (bool) config('project_updater.install_enabled', true)) {
            throw new NotifyErrorException(__('Project update installation is disabled by configuration.'));
        }

        if (! $update->isInstallable()) {
            throw new NotifyErrorException(__('This update cannot be installed from its current status.'));
        }

        /*
         * Never install backwards.
         *
         * `status` is frozen at check time, so a row marked `available`
         * months ago stays installable long after newer releases have landed
         * over it — this installation had a v2.0.1 row still saying
         * "available" while running 2.6.4. Installing it would copy older
         * files over the application and run that release's migrations.
         *
         * Equal versions are allowed only as a retry of a failed install:
         * writeInstalledVersionToConfig() runs near the end of the pipeline,
         * so a run that died just after it leaves the config on the new
         * version with the work unfinished, and that retry must not be
         * blocked by its own partial success.
         */
        $comparison = version_compare((string) $update->version, (string) config('app.version'));

        if ($comparison < 0 || ($comparison === 0 && $update->status !== 'failed')) {
            throw new NotifyErrorException(__(
                'Version :target is not newer than the installed version :current. Run a fresh update check before installing.',
                ['target' => $update->version, 'current' => config('app.version')]
            ));
        }

        $this->assertRequirementsMet($update);

        if (blank($update->package_url) || blank($update->checksum)) {
            throw new NotifyErrorException(__('The update package URL and checksum are required.'));
        }

        // A real install (download + ZIP extract + db dump + storage zip + file
        // copy + migrations) easily exceeds PHP's default 30s max_execution_time.
        // Disable it for this request so the script isn't killed mid-update, and
        // ignore the user-abort signal so a closed browser doesn't leave the
        // application half-updated.
        @set_time_limit(0);
        @ignore_user_abort(true);

        MemoryLimit::atLeast('512M');

        /*
         * Who pressed the button, and when — read before the work starts so a
         * failure records them too. The history table is the only place an
         * operator can see that last Tuesday's four-minute outage was an
         * install, and which administrator ran it.
         */
        $startedAt   = microtime(true);
        $actor       = $this->currentActorName();
        $versionFrom = (string) config('app.version');

        /*
         * One writer at a time.
         *
         * Install and rollback both rewrite the application directory from
         * two different sources. Run together — two admins, or one admin and
         * an impatient second click — they interleave file copies and leave a
         * tree that is neither release. The lock is held for the whole run and
         * is never waited on: the second caller is told to wait, not queued
         * behind a five-minute install.
         */
        $lock = Cache::lock(self::RUN_LOCK, 1800);

        if (! $lock->get()) {
            throw new NotifyErrorException(__('Another update operation is already running. Wait for it to finish before starting a new one.'));
        }

        try {
            // Pre-flight: confirm the web user can actually write to the key
            // application directories BEFORE we download / extract / back up.
            // Otherwise the install fails halfway through the copy phase with
            // a cryptic "copy(...): Failed to open stream: Permission denied"
            // after we've already wasted disk space and time.
            $this->assertWritablePaths();

            $packagePath = $this->downloadPackage($update);
            $this->verifyPackage($packagePath, (string) $update->checksum, $update->signature);

            $extractPath = $this->extractPackage($packagePath, $update);
            $manifest    = $this->readManifest($extractPath);
            $backupPath  = $this->backupManifestFiles($manifest, $update);

            if ($includeSystemBackup) {
                $this->createDatabaseAndStorageBackup($update, $backupPath);
            }

            // Go down through the maintenance service rather than a bare
            // `artisan down`: that writes no recovery key, so an install that
            // dies before the `finally` below (a fatal error, a killed PHP
            // worker) leaves the site 503-ing with no way back in short of
            // deleting storage/framework/down over SSH.
            app(MaintenanceModeService::class)->activate();
            $this->copyManifestFiles($extractPath, $manifest);
            Artisan::call('migrate', ['--force' => true]);

            // A migration only reshapes tables. Anything the new code expects to
            // *read* — notification copy, gateway bindings on transactions written
            // before the binding existed — has to be reconciled too, or the update
            // lands schema-complete and data-short. Additive only, and it reports
            // rather than throws, so it can never leave an update half-applied.
            Artisan::call('install:reconcile');

            // Ensure the locally-stored version string reflects what we just
            // installed. config('app.version') reads from
            // config/project_updater.php's 'version' key — if the update
            // package didn't refresh that file (or shipped without it), the UI
            // would still show the old version. We rewrite the line explicitly
            // so the displayed version is always correct after install.
            $this->writeInstalledVersionToConfig((string) $update->version);

            Artisan::call('optimize:clear');

            $update->forceFill([
                'status'        => 'installed',
                'version_from'  => $versionFrom,
                'package_path'  => $packagePath,
                'backup_path'   => $backupPath,
                'installed_at'  => now(),
                'duration_ms'   => $this->elapsedMilliseconds($startedAt),
                'installed_by'  => $actor,
                'error_message' => null,
            ])->save();
        } catch (\Throwable $e) {
            $update->forceFill([
                'status'        => 'failed',
                'version_from'  => $versionFrom,
                'duration_ms'   => $this->elapsedMilliseconds($startedAt),
                'installed_by'  => $actor,
                'error_message' => $e->getMessage(),
            ])->save();

            throw $e instanceof NotifyErrorException
                ? $e
                : new NotifyErrorException(__('Project update failed: :message', ['message' => $e->getMessage()]));
        } finally {
            optional($lock)->release();

            if (app()->isDownForMaintenance()) {
                try {
                    // sync() lifts maintenance mode unless the administrator
                    // had deliberately switched it on, in which case the site
                    // stays down with its own recovery key restored.
                    app(MaintenanceModeService::class)->sync();
                } catch (\Throwable) {
                    // Don't mask the original failure if lifting maintenance mode fails.
                }
            }
        }

        return $update->refresh();
    }

    /**
     * Put back the files one install replaced.
     *
     * This is a DEPLOY rollback, the same thing Capistrano or Envoyer mean by
     * the word: the application's code returns to what it was, and the
     * database is left exactly as it is. It does not, and must not, replay
     * database.sql — that dump predates the install, so restoring it would
     * erase every transaction, wallet movement and KYC decision written
     * since. On this application that is not a recoverable mistake, so it is
     * not a button; restore-note.txt inside the snapshot documents the manual
     * path for an operator who has decided they really want it.
     *
     * In practice migrations are additive, so older code against a newer
     * schema ignores the columns it does not know about. Where a release
     * ships a destructive migration its notes say so, and the honest move is
     * forward rather than back.
     *
     * The rollback takes its own snapshot first, so it is itself reversible.
     */
    public function rollback(ProjectUpdate $update): ProjectUpdate
    {
        if (! (bool) config('project_updater.rollback_enabled', true)) {
            throw new NotifyErrorException(__('Rolling back updates is disabled by configuration.'));
        }

        if (($reason = $update->rollbackBlockedReason()) !== null) {
            throw new NotifyErrorException($reason);
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        MemoryLimit::atLeast('512M');

        $disk       = Storage::disk((string) config('project_updater.storage_disk'));
        $backupRoot = $disk->path((string) $update->backup_path);
        $manifest   = $this->readBackupManifest($backupRoot);

        $startedAt   = microtime(true);
        $actor       = $this->currentActorName();
        $versionFrom = (string) config('app.version');
        $versionTo   = $this->versionInsideBackup($backupRoot) ?? $update->version_from;

        $lock = Cache::lock(self::RUN_LOCK, 1800);

        if (! $lock->get()) {
            throw new NotifyErrorException(__('Another update operation is already running. Wait for it to finish before starting a new one.'));
        }

        try {
            $this->assertWritablePaths();

            /*
             * A snapshot of what is about to be overwritten. Without it a
             * rollback is a one-way door: the files it replaces are the only
             * copy of the release being undone.
             */
            $safetyPath = trim((string) config('project_updater.backups_path'), '/')
                .'/pre-rollback-'.$update->version.'-'.now()->format('YmdHis');

            $this->backupManifestFiles($manifest, $update, $safetyPath);

            app(MaintenanceModeService::class)->activate();

            $restored = $this->restoreManifestFiles($backupRoot, $manifest);

            /*
             * The snapshot usually carries the old config/project_updater.php,
             * and restoring it restores the version with it. Where the release
             * did not ship that file the version is written explicitly, or the
             * application runs old code while reporting the version it just
             * undid.
             */
            if (filled($versionTo)) {
                $this->writeInstalledVersionToConfig((string) $versionTo);
            }

            Artisan::call('optimize:clear');

            $update->forceFill([
                'status'       => 'rolled_back',
                'version_from' => $versionTo ?: $update->version_from,
                'backup_path'  => $safetyPath,
                'duration_ms'  => $this->elapsedMilliseconds($startedAt),
                'installed_by' => $actor,
                /*
                 * error_message means a failure. Files the release ADDED have
                 * nothing to restore and are left alone by design, which is
                 * worth recording but is not an error — it goes to the log,
                 * not into a column the failure banner reads.
                 */
                'error_message' => null,
            ])->save();

            Log::info('Project updater rolled back.', [
                'version'       => $update->version,
                'version_from'  => $versionTo,
                'restored'      => $restored['restored'],
                'left_in_place' => $restored['absent'],
                'snapshot'      => $safetyPath,
            ]);

            return $update->refresh();
        } catch (\Throwable $e) {
            /*
             * A rollback that dies part-way leaves the tree mixed, and the
             * operator needs to know which snapshot holds the other half.
             */
            $update->forceFill([
                'error_message' => __('Rollback from v:from failed: :message', [
                    'from'    => $versionFrom,
                    'message' => $e->getMessage(),
                ]),
            ])->save();

            throw $e instanceof NotifyErrorException
                ? $e
                : new NotifyErrorException(__('Rollback failed: :message', ['message' => $e->getMessage()]));
        } finally {
            optional($lock)->release();

            if (app()->isDownForMaintenance()) {
                try {
                    app(MaintenanceModeService::class)->sync();
                } catch (\Throwable) {
                    /* Never mask the original failure. */
                }
            }
        }
    }

    /**
     * The manifest stored alongside a snapshot, validated exactly as the
     * package's own manifest is: it lists paths that will be written over the
     * application, so it gets no more trust than the package got.
     *
     * @return array{files: list<array{path: string}>}
     */
    private function readBackupManifest(string $backupRoot): array
    {
        $manifestPath = $backupRoot.'/manifest.json';

        if (! File::exists($manifestPath)) {
            throw new NotifyErrorException(__('This snapshot has no manifest, so there is no record of which files to put back.'));
        }

        $manifest = json_decode((string) File::get($manifestPath), true);

        if (! is_array($manifest) || ! isset($manifest['files']) || ! is_array($manifest['files'])) {
            throw new NotifyErrorException(__('The snapshot manifest is invalid.'));
        }

        return $manifest;
    }

    /**
     * Copy a snapshot's files back over the application.
     *
     * A path in the manifest with no file in the snapshot was NEW in the
     * release being undone, so there is nothing to put back. It is left where
     * it is rather than deleted: an unreferenced file costs nothing, and
     * deleting from a list is how a rollback becomes an outage.
     *
     * @param  array{files: list<array{path: string}>} $manifest
     * @return array{restored: int, absent: int}
     */
    private function restoreManifestFiles(string $backupRoot, array $manifest): array
    {
        $restored = 0;
        $absent   = 0;

        foreach ($manifest['files'] as $file) {
            $relativePath = $this->safeRelativePath((string) ($file['path'] ?? ''));
            $source       = $backupRoot.'/'.$relativePath;

            if (! File::exists($source)) {
                $absent++;

                continue;
            }

            $target = base_path($relativePath);
            File::ensureDirectoryExists(dirname($target));

            try {
                File::copy($source, $target);
                $restored++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Permission denied')) {
                    throw new NotifyErrorException($this->buildPermissionErrorMessage($target, $relativePath));
                }

                throw $e;
            }
        }

        return ['restored' => $restored, 'absent' => $absent];
    }

    /**
     * The version string inside a snapshot's copy of config/project_updater.php.
     */
    private function versionInsideBackup(string $backupRoot): ?string
    {
        $configPath = $backupRoot.'/config/project_updater.php';

        if (! File::exists($configPath)) {
            return null;
        }

        $matched = preg_match("/'version'\s*=>\s*'([^']+)'/u", (string) File::get($configPath), $matches);

        return $matched === 1 ? $matches[1] : null;
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * The signed-in administrator's name, or null for a console-run install.
     */
    private function currentActorName(): ?string
    {
        foreach (['admin', 'web'] as $guard) {
            $name = Auth::guard($guard)->user()?->name;

            if (is_string($name) && trim($name) !== '') {
                return mb_substr(trim($name), 0, 190);
            }
        }

        return null;
    }

    /**
     * @return array<int, array{label: string, status: bool, help: string, value: string, icon: string}>
     */
    public function environmentChecks(): array
    {
        $diskFreeBytes = $this->diskFreeBytes();
        $minDiskBytes  = 500 * 1024 * 1024;

        /*
         * `help` is the line under the label, and it is a REQUIREMENT, not a
         * sentence: "≥ 8.2 required", not "PHP 8.2 or higher is required to
         * run Digikash". The row is a three-column grid with a mono value on
         * the end, so a sentence is ellipsed away to nothing — all six rows
         * on this page truncated mid-word until they were written this way.
         * Keep new checks to roughly thirty characters.
         */
        $zipReady      = class_exists(ZipArchive::class);
        $remoteFopen   = (bool) ini_get('allow_url_fopen');
        $writablePaths = $this->writableApplicationPaths();
        $databaseReady = $this->canConnectToDatabase();
        $serverReady   = filled(config('project_updater.server_url'));

        return [
            [
                'label'  => __('PHP version'),
                'status' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'help'   => __('≥ 8.2 required'),
                'value'  => PHP_VERSION,
                /* A plain tick, as the reference has it — the code glyph is a solid
                   shape and read as a smudge at 13px. */
                'icon' => 'check',
            ],
            [
                'label'  => __('Disk space'),
                'status' => $diskFreeBytes === null || $diskFreeBytes >= $minDiskBytes,
                'help'   => __('≥ 500 MB required'),
                'value'  => $diskFreeBytes === null ? __('unknown') : $this->formatBytes($diskFreeBytes),
                'icon'   => 'layer',
            ],
            /*
             * The same three roots assertWritablePaths() checks before an
             * install starts, asked here so the answer arrives before the
             * operator commits rather than half-way through the copy phase.
             */
            [
                'label'  => __('Write access'),
                'status' => $writablePaths,
                'help'   => __('app, storage, bootstrap/cache'),
                'value'  => $writablePaths ? __('writable') : __('blocked'),
                'icon'   => 'lock',
            ],
            [
                'label'  => __('Package tools'),
                'status' => $zipReady && $remoteFopen,
                'help'   => __('ext-zip, allow_url_fopen'),
                'value'  => match (true) {
                    $zipReady && $remoteFopen => __('ok'),
                    ! $zipReady               => __('no ext-zip'),
                    default                   => __('no fopen'),
                },
                'icon' => 'plugin',
            ],
            [
                'label'  => __('Updater server'),
                'status' => $serverReady,
                'help'   => __('release server set'),
                'value'  => $serverReady ? __('configured') : __('missing'),
                'icon'   => 'api',
            ],
            [
                'label'  => __('Database connection'),
                'status' => $databaseReady,
                'help'   => __('required for migrations'),
                'value'  => $databaseReady ? __('ok') : __('unreachable'),
                'icon'   => 'cil-memory',
            ],
        ];
    }

    /**
     * Can the web user write everywhere an install has to write?
     *
     * Mirrors assertWritablePaths(), which is what actually stops the install
     * — this is the read-only question asked ahead of time.
     */
    private function writableApplicationPaths(): bool
    {
        foreach ([base_path('app'), base_path('bootstrap/cache'), storage_path('app')] as $path) {
            if (is_dir($path) && ! is_writable($path)) {
                return false;
            }
        }

        return true;
    }

    private function diskFreeBytes(): ?float
    {
        try {
            $free = @disk_free_space(base_path());

            return $free === false ? null : (float) $free;
        } catch (\Throwable) {
            return null;
        }
    }

    private function canConnectToDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / 1024 ** 3, 1).' GB';
        }

        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / 1024 ** 2, 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }

    private function downloadPackage(ProjectUpdate $update): string
    {
        try {
            $body = $this->client->download((string) $update->package_url);
        } catch (NotifyErrorException $e) {
            if (! in_array($e->getCode(), [401, 403], true)) {
                throw $e;
            }

            $this->refreshPackageLink($update);

            $body = $this->client->download((string) $update->package_url);
        }
        $path = trim((string) config('project_updater.packages_path'), '/').'/'.$update->version.'-'.Str::uuid().'.zip';

        Storage::disk((string) config('project_updater.storage_disk'))->put($path, $body);

        return $path;
    }

    /**
     * Replace an expired package link with a freshly signed one.
     *
     * The update server signs each package URL for a few minutes (15 by
     * default), and the link is stored when the check runs. Checks are
     * manual, so pressing Install a quarter of an hour after Check found the
     * release used to fail with "Server returned 403" before anything else
     * happened. A new check hands out a new link for the same release.
     *
     * The server only signs links for the newest release above the installed
     * version, so a re-check that names a different release — or a retry of
     * a version the config already claims — cannot rescue this row.
     *
     * @throws NotifyErrorException
     */
    private function refreshPackageLink(ProjectUpdate $update): void
    {
        $expired = __('The download link for :version has expired. Run a fresh update check, then install straight away.', [
            'version' => $update->version,
        ]);

        if (version_compare((string) $update->version, (string) config('app.version')) <= 0) {
            throw new NotifyErrorException($expired, 403);
        }

        $checked = $this->checkForUpdates();

        if ($checked->version !== $update->version || $checked->channel !== $update->channel) {
            throw new NotifyErrorException(__('Release :latest has replaced :version on the update server. Install :latest instead.', [
                'latest'  => $checked->version,
                'version' => $update->version,
            ]), 409);
        }

        $update->refresh();

        if (blank($update->package_url)) {
            throw new NotifyErrorException($expired, 403);
        }
    }

    public function verifyPackage(string $packagePath, string $checksum, ?string $signature = null): void
    {
        $absolutePath = Storage::disk((string) config('project_updater.storage_disk'))->path($packagePath);
        $actual       = hash_file('sha256', $absolutePath);

        if (! hash_equals(strtolower($checksum), strtolower((string) $actual))) {
            throw new NotifyErrorException(__('Update package checksum verification failed.'));
        }

        $publicKey = config('project_updater.public_key');

        if (filled($publicKey) && filled($signature)) {
            $verified = openssl_verify($checksum, base64_decode((string) $signature, true) ?: '', (string) $publicKey, OPENSSL_ALGO_SHA256);

            if ($verified !== 1) {
                throw new NotifyErrorException(__('Update package signature verification failed.'));
            }
        }
    }

    private function extractPackage(string $packagePath, ProjectUpdate $update): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new NotifyErrorException(__('The PHP ZIP extension is required to install updates.'));
        }

        $disk                = Storage::disk((string) config('project_updater.storage_disk'));
        $archivePath         = $disk->path($packagePath);
        $extractPath         = trim((string) config('project_updater.extract_path'), '/').'/'.$update->version.'-'.Str::uuid();
        $absoluteExtractPath = $disk->path($extractPath);

        File::ensureDirectoryExists($absoluteExtractPath);

        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new NotifyErrorException(__('The update package could not be opened.'));
        }

        $zip->extractTo($absoluteExtractPath);
        $zip->close();

        return $extractPath;
    }

    /**
     * @return array{files: list<array{path: string}>}
     */
    private function readManifest(string $extractPath): array
    {
        $manifestPath = Storage::disk((string) config('project_updater.storage_disk'))->path($extractPath.'/manifest.json');

        if (! File::exists($manifestPath)) {
            throw new NotifyErrorException(__('The update package manifest is missing.'));
        }

        $manifest = json_decode((string) File::get($manifestPath), true);

        if (! is_array($manifest) || ! isset($manifest['files']) || ! is_array($manifest['files'])) {
            throw new NotifyErrorException(__('The update package manifest is invalid.'));
        }

        return $manifest;
    }

    /**
     * @param array{files: list<array{path: string}>} $manifest
     */
    private function backupManifestFiles(array $manifest, ProjectUpdate $update, ?string $backupPath = null): string
    {
        $backupPath ??= trim((string) config('project_updater.backups_path'), '/').'/'.$update->version.'-'.now()->format('YmdHis');
        $backupRoot = Storage::disk((string) config('project_updater.storage_disk'))->path($backupPath);

        File::ensureDirectoryExists($backupRoot);

        foreach ($manifest['files'] as $file) {
            $relativePath = $this->safeRelativePath((string) ($file['path'] ?? ''));
            $source       = base_path($relativePath);

            if (File::exists($source)) {
                File::ensureDirectoryExists(dirname($backupRoot.'/'.$relativePath));
                File::copy($source, $backupRoot.'/'.$relativePath);
            }
        }

        File::put($backupRoot.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        return $backupPath;
    }

    public function createDatabaseAndStorageBackup(ProjectUpdate $update, ?string $backupPath = null): string
    {
        $backupPath ??= trim((string) config('project_updater.backups_path'), '/').'/'.$update->version.'-'.now()->format('YmdHis');
        $backupRoot = Storage::disk((string) config('project_updater.storage_disk'))->path($backupPath);

        File::ensureDirectoryExists($backupRoot);

        $this->writeDatabaseAndStorageBackup($backupRoot, $update->version);

        return $backupPath;
    }

    /**
     * @return array{path: string, name: string, backup_path: string}
     */
    public function createDownloadableRecoveryBackup(): array
    {
        /*
         * Never on a demo install. The archive is a full database dump plus
         * the storage folder, and the demo panel is open to anyone who read
         * the published admin credentials — so this is the one updater action
         * that hands the whole installation to an anonymous visitor. The
         * DemoMode guard refuses the route; this refuses the work, so no other
         * entry point (console, a future route, a queued job) can build the
         * archive either. Nothing is written before this check.
         */
        if ((bool) config('app.demo', false)) {
            throw new NotifyErrorException(__('Downloading a recovery backup is disabled in demo mode.'));
        }

        // Backups (mysqldump + zip of storage/) routinely exceed PHP's default
        // 30s max_execution_time. Lift the limit so the script can finish.
        @set_time_limit(0);
        @ignore_user_abort(true);

        MemoryLimit::atLeast('512M');

        $license = ProjectLicense::current();

        if (! $license?->isActive()) {
            throw new NotifyErrorException(__('Activate the project license before downloading a recovery backup.'));
        }

        $version    = (string) config('app.version');
        $stamp      = now()->format('YmdHis');
        $backupPath = trim((string) config('project_updater.backups_path'), '/').'/manual-'.$version.'-'.$stamp;
        $backupRoot = Storage::disk((string) config('project_updater.storage_disk'))->path($backupPath);

        File::ensureDirectoryExists($backupRoot);
        $this->writeDatabaseAndStorageBackup($backupRoot, $version);

        $fileName    = 'digikash-recovery-'.$version.'-'.$stamp.'.zip';
        $archivePath = trim((string) config('project_updater.backups_path'), '/').'/downloads/'.$fileName;
        $absoluteZip = Storage::disk((string) config('project_updater.storage_disk'))->path($archivePath);

        File::ensureDirectoryExists(dirname($absoluteZip));
        $this->archiveBackupDirectory($backupRoot, $absoluteZip);

        return [
            'path'        => $absoluteZip,
            'name'        => $fileName,
            'backup_path' => $backupPath,
        ];
    }

    private function writeDatabaseAndStorageBackup(string $backupRoot, string $version): void
    {
        $this->backupDatabase($backupRoot.'/database.sql');
        $this->backupStorageDirectory($backupRoot.'/storage.zip', $backupRoot);

        File::put($backupRoot.'/restore-note.txt', implode(PHP_EOL, [
            'Digikash update backup',
            'Version: '.$version,
            'Created: '.now()->toDateTimeString(),
            'database.sql contains a database dump.',
            'storage.zip contains the Laravel storage folder, excluding this backup directory.',
        ]));
    }

    private function backupDatabase(string $targetPath): void
    {
        File::ensureDirectoryExists(dirname($targetPath));

        $connection = DB::connection();
        $driver     = $connection->getDriverName();
        $pdo        = $connection->getPdo();
        $tables     = $this->databaseTables($driver);
        $handle     = fopen($targetPath, 'wb');

        if ($handle === false) {
            throw new NotifyErrorException(__('The database backup file could not be created.'));
        }

        fwrite($handle, '-- Digikash database backup'.PHP_EOL);
        fwrite($handle, '-- Generated at: '.now()->toDateTimeString().PHP_EOL.PHP_EOL);

        if ($driver === 'mysql') {
            fwrite($handle, 'SET FOREIGN_KEY_CHECKS=0;'.PHP_EOL.PHP_EOL);
        }

        foreach ($tables as $table) {
            $quotedTable = $this->quoteIdentifier($table, $driver);

            fwrite($handle, 'DROP TABLE IF EXISTS '.$quotedTable.';'.PHP_EOL);
            fwrite($handle, $this->createTableStatement($table, $driver).';'.PHP_EOL.PHP_EOL);
            $this->writeTableRows($handle, $pdo, $table, $driver);
            fwrite($handle, PHP_EOL);
        }

        if ($driver === 'mysql') {
            fwrite($handle, 'SET FOREIGN_KEY_CHECKS=1;'.PHP_EOL);
        }

        fclose($handle);
    }

    /**
     * @return list<string>
     */
    private function databaseTables(string $driver): array
    {
        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->map(fn (object $row): string => (string) $row->name)
                ->values()
                ->all();
        }

        if ($driver === 'mysql') {
            return collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
                ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                ->values()
                ->all();
        }

        throw new NotifyErrorException(__('Database backup is not supported for the active database driver: :driver', ['driver' => $driver]));
    }

    private function createTableStatement(string $table, string $driver): string
    {
        if ($driver === 'sqlite') {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);

            return (string) ($row->sql ?? '');
        }

        $row = DB::selectOne('SHOW CREATE TABLE '.$this->quoteIdentifier($table, $driver));

        return (string) (array_values((array) $row)[1] ?? '');
    }

    /**
     * @param resource $handle
     */
    private function writeTableRows(mixed $handle, PDO $pdo, string $table, string $driver): void
    {
        $statement = $pdo->query('SELECT * FROM '.$this->quoteIdentifier($table, $driver));

        if ($statement === false) {
            throw new NotifyErrorException(__('The database table could not be read for backup: :table', ['table' => $table]));
        }

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns = array_map(fn (string $column): string => $this->quoteIdentifier($column, $driver), array_keys($row));
            $values  = array_map(fn (mixed $value): string => $this->sqlValue($pdo, $value), array_values($row));

            fwrite($handle, 'INSERT INTO '.$this->quoteIdentifier($table, $driver).' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).');'.PHP_EOL);
        }
    }

    private function sqlValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $pdo->quote((string) $value);
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        $quote = $driver === 'mysql' ? '`' : '"';

        return $quote.str_replace($quote, $quote.$quote, $identifier).$quote;
    }

    private function backupStorageDirectory(string $targetPath, string $backupRoot): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new NotifyErrorException(__('The PHP ZIP extension is required to create the storage backup.'));
        }

        $zip = new ZipArchive;

        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new NotifyErrorException(__('The storage backup archive could not be created.'));
        }

        $sourceRoot    = $this->normalizedPath(storage_path());
        $excludedRoots = collect([
            $backupRoot,
            $this->storageDiskPath((string) config('project_updater.backups_path')),
            $this->storageDiskPath((string) config('project_updater.packages_path')),
            $this->storageDiskPath((string) config('project_updater.extract_path')),
        ])
            ->filter()
            ->map(fn (string $path): string => $this->normalizedPath($path))
            ->values()
            ->all();

        foreach (File::allFiles(storage_path(), true) as $file) {
            $path = $this->normalizedPath((string) $file->getRealPath());

            if ($this->pathStartsWithAny($path, $excludedRoots)) {
                continue;
            }

            $relativePath = ltrim(Str::after($path, $sourceRoot), '/');

            if ($relativePath !== '') {
                $zip->addFile($path, $relativePath);
            }
        }

        $zip->close();
    }

    private function archiveBackupDirectory(string $backupRoot, string $targetPath): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new NotifyErrorException(__('The PHP ZIP extension is required to create the downloadable backup.'));
        }

        $zip = new ZipArchive;

        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new NotifyErrorException(__('The downloadable backup archive could not be created.'));
        }

        $sourceRoot = $this->normalizedPath($backupRoot);

        foreach (File::allFiles($backupRoot, true) as $file) {
            $path         = $this->normalizedPath((string) $file->getRealPath());
            $relativePath = ltrim(Str::after($path, $sourceRoot), '/');

            if ($relativePath !== '') {
                $zip->addFile($path, $relativePath);
            }
        }

        $zip->close();
    }

    private function storageDiskPath(string $path): string
    {
        return Storage::disk((string) config('project_updater.storage_disk'))->path(trim($path, '/'));
    }

    /**
     * @param list<string> $roots
     */
    private function pathStartsWithAny(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizedPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param array{files: list<array{path: string}>} $manifest
     */
    private function copyManifestFiles(string $extractPath, array $manifest): void
    {
        $disk = Storage::disk((string) config('project_updater.storage_disk'));

        foreach ($manifest['files'] as $file) {
            $relativePath = $this->safeRelativePath((string) ($file['path'] ?? ''));
            $source       = $disk->path($extractPath.'/'.$relativePath);
            $target       = base_path($relativePath);

            if (! File::exists($source)) {
                throw new NotifyErrorException(__('Update file missing from package: :path', ['path' => $relativePath]));
            }

            File::ensureDirectoryExists(dirname($target));

            try {
                File::copy($source, $target);
            } catch (\Throwable $e) {
                // PHP's copy() throws a plain ErrorException with a generic
                // "Failed to open stream: Permission denied" — re-throw with
                // the buyer-facing fix command so they don't have to grep
                // their server logs to figure out what went wrong.
                if (str_contains($e->getMessage(), 'Permission denied')) {
                    throw new NotifyErrorException($this->buildPermissionErrorMessage($target, $relativePath));
                }

                throw $e;
            }
        }
    }

    /**
     * Pre-flight check before download — verify the web user can write to
     * the application's key directories. Bails out with an actionable error
     * message naming the failing path, current PHP user, file owner, and
     * the exact chown command to run.
     */
    private function assertWritablePaths(): void
    {
        $samplePaths = array_filter([
            base_path('app'),
            base_path('bootstrap/cache'),
            base_path('config'),
            base_path('database'),
            base_path('public'),
            base_path('resources'),
            base_path('routes'),
        ], 'is_dir');

        foreach ($samplePaths as $path) {
            if (! is_writable($path)) {
                throw new NotifyErrorException($this->buildPermissionErrorMessage($path, basename($path)));
            }
        }
    }

    /**
     * Build an actionable error message for a permission failure. Surfaces
     * the failing absolute path, who owns it, who PHP runs as, and a copy-
     * pasteable chown command — so the buyer can fix it in one SSH command
     * instead of guessing.
     */
    private function buildPermissionErrorMessage(string $absolutePath, string $relativePath): string
    {
        $owner   = $this->describePosixUser((int) @fileowner($absolutePath));
        $current = function_exists('posix_geteuid')
            ? $this->describePosixUser((int) @posix_geteuid())
            : 'the PHP / web server user';

        $appRoot      = base_path();
        $userForChown = $current !== 'the PHP / web server user' ? $current : 'www-data';

        return __(
            "The update cannot write to :path.\n\n".
            "PHP is running as ':current' but that file is owned by ':owner'. ".
            "Your web server user needs ownership of the application files to install updates.\n\n".
            "Fix on your server (one SSH command):\n".
            "sudo chown -R :user::user :root\n\n".
            'Then click "Install update" again. The update was NOT applied — your site is unchanged.',
            [
                'path'    => $absolutePath,
                'current' => $current,
                'owner'   => $owner,
                'user'    => $userForChown,
                'root'    => $appRoot,
            ]
        );
    }

    /**
     * Resolve a POSIX uid to a human-readable name. Falls back gracefully
     * when posix_* extensions aren't loaded (e.g. on Windows hosts).
     */
    private function describePosixUser(int $uid): string
    {
        if (! function_exists('posix_getpwuid')) {
            return 'uid '.$uid;
        }

        $info = @posix_getpwuid($uid);

        return is_array($info) && isset($info['name'])
            ? (string) $info['name']
            : 'uid '.$uid;
    }

    private function safeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '../')) {
            throw new NotifyErrorException(__('Update package contains an unsafe file path.'));
        }

        foreach (config('project_updater.protected_paths', []) as $protectedPath) {
            $protectedPath = trim((string) $protectedPath, '/');

            if ($path === $protectedPath || str_starts_with($path, $protectedPath.'/')) {
                throw new NotifyErrorException(__('Update package tried to modify a protected path: :path', ['path' => $path]));
            }
        }

        return $path;
    }
}
