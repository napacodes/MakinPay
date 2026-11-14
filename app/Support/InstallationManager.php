<?php

namespace App\Support;

use App\Exceptions\NotifyErrorException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class InstallationManager
{


    /**
     * Latches to true once the settings table is known to exist. Never latches
     * to false — see settingsTableAvailable(). Static because nothing binds
     * this class as a singleton, so the helper builds a fresh instance per
     * call and an instance memo would never be read twice.
     */
    private static ?bool $settingsTableExists = null;

    private const MIGRATED_VERSION_KEY = 'installation.migrated_version';

    private const SCHEMA_HEAL_LOCK = 'installation.schema_heal';

    /**
     * Interchangeable local MySQL hosts. When one refuses the connection the
     * installer transparently tries the other (TCP vs unix socket).
     */

    public function __construct() {}

    /**
     * The legacy web installer's completion lock.
     *
     * Retained because installations created before the CLI installer existed
     * still carry it, and they must never be mistaken for an uninstalled site.
     * New installs write storage/app/makinpay-installed.json instead.
     */
    private function lockPath(): string
    {
        return (string) config('installer.lock_file', storage_path('app/installed'));
    }

    public function isInstalled(): bool
    {
        if (File::exists($this->lockPath())) {
            return true;
        }

        return $this->hasInstalledDatabase();
    }

    /**
     * Whether the database carries the tables a completed installation has.
     */
    private function hasInstalledDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return Schema::hasTable('settings') && Schema::hasTable('admins');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Bring the database up to the code that is running against it.
     *
     * The in-app updater migrates as part of its own run, so this exists for
     * the installation whose files arrived some other way — the release zip
     * unpacked over the top by FTP, which is how a buyer without shell access
     * usually updates. Those files carry migrations the database has never
     * seen, and the mismatch surfaces as a column the new code selects and the
     * old schema does not have: a 500 on whichever screen reads it first,
     * which for the FX snapshot columns was both dashboards at once.
     *
     * This runs on every web request, so the common answer has to be cheap. A
     * marker holding the version last migrated settles it in one cache read.
     * The authoritative check only runs when that marker is missing or stale,
     * and the marker is rewritten even when nothing was pending — so a cleared
     * cache costs one comparison rather than a reconcile.
     *
     * Never fatal. An installation that cannot heal itself is the situation we
     * were already in; one that dies inside a service provider is worse.
     */
    public function ensureSchemaMatchesCode(): void
    {
        $signature = $this->schemaSignature();

        try {
            if (Cache::get(self::MIGRATED_VERSION_KEY) === $signature) {
                return;
            }

            if (! $this->hasPendingMigrations()) {
                Cache::forever(self::MIGRATED_VERSION_KEY, $signature);

                return;
            }

            $lock = Cache::lock(self::SCHEMA_HEAL_LOCK, 600);

            if (! $lock->get()) {
                return;
            }

            try {
                @set_time_limit(0);

                Artisan::call('migrate', ['--force' => true]);

                // A migration only reshapes tables. Reference data the new code
                // reads has to be reconciled too, or the schema lands complete
                // and the rows behind it short — the same additive step the
                // updater runs for exactly this reason.
                Artisan::call('install:reconcile');

                Cache::forever(self::MIGRATED_VERSION_KEY, $signature);

                Log::info('Applied pending migrations for an update that did not run them.', [
                    'version' => (string) config('app.version'),
                ]);
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            Log::warning('Could not bring the schema up to the running code: '.$e->getMessage());
        }
    }

    /**
     * What the marker is compared against.
     *
     * The migrations directory's mtime moves whenever a file is added to or
     * removed from it, which is the event that actually matters here. The
     * version string alone would not do: an installation running with cached
     * config keeps reporting the old one until the cache is cleared, and an
     * upload over FTP clears nothing.
     */
    private function schemaSignature(): string
    {
        return (string) config('app.version').'@'.(@filemtime(database_path('migrations')) ?: 0);
    }

    /**
     * Whether any migration on disk has never been recorded as run.
     */
    private function hasPendingMigrations(): bool
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return false;
        }

        $ran   = $migrator->getRepository()->getRan();
        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [database_path('migrations')]),
        );

        foreach (array_keys($files) as $migration) {
            if (! in_array($migration, $ran, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the settings table is queryable.
     *
     * Memoised once it answers yes, because the `setting()` helper calls this
     * before every single read and each miss costs an information_schema
     * round trip — 134 of them on one admin dashboard render. Only the true
     * answer is remembered: a table that exists cannot vanish mid-request,
     * while a false answer must stay live so the installer sees the table the
     * moment it creates one.
     */
    public function settingsTableAvailable(): bool
    {
        if (self::$settingsTableExists === true) {
            return true;
        }

        try {
            DB::connection()->getPdo();

            $exists = Schema::hasTable('settings');
        } catch (Throwable) {
            return false;
        }

        if ($exists) {
            self::$settingsTableExists = true;
        }

        return $exists;
    }

    public function sessionTableAvailable(): bool
    {
        try {
            DB::connection()->getPdo();

            return Schema::hasTable('sessions');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Queue drivers a deployment may use. Both need nothing beyond the
     * database connection already configured.
     */
    public const QUEUE_CONNECTIONS = ['database', 'sync'];

    /**
     * Privileges migrations and reference-data seeding need on MySQL/MariaDB.
     */
    public const REQUIRED_DATABASE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES'];

    /**
     * The crontab line the Laravel scheduler needs, for this project path.
     */
    public static function cronCommand(): string
    {
        return '* * * * * cd '.str_replace('\\', '/', base_path()).' && php artisan schedule:run >> /dev/null 2>&1';
    }

    /**
     * The privileges a list of MySQL/MariaDB GRANT rows gives on one database:
     * global (*.*) grants and database-level grants whose name, or LIKE
     * pattern, matches. Table- and column-level grants are ignored because
     * migrations create new tables.
     *
     * Retained for the CLI preflight, which reports missing privileges as a
     * warning before a migration fails on them.
     *
     * @param  list<string> $grants
     * @return list<string>
     */
    public static function grantedPrivileges(array $grants, string $database): array
    {
        $granted = [];

        foreach ($grants as $grant) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(?:(?:TABLE|FUNCTION|PROCEDURE)\s+)?(\S+)\s+TO\s/i', trim($grant), $match) !== 1) {
                continue;
            }

            $separator = strrpos($match[2], '.');

            if ($separator === false || trim(substr($match[2], $separator + 1), '`"') !== '*') {
                continue;
            }

            $scope = trim(substr($match[2], 0, $separator), '`"');

            if ($scope !== '*' && ! self::databaseNameMatches($scope, $database)) {
                continue;
            }

            if (stripos($match[1], 'ALL PRIVILEGES') !== false || strcasecmp(trim($match[1]), 'ALL') === 0) {
                return self::REQUIRED_DATABASE_PRIVILEGES;
            }

            foreach (explode(',', preg_replace('/\([^)]*\)/', '', $match[1]) ?? '') as $privilege) {
                $granted[] = strtoupper(trim($privilege));
            }
        }

        return array_values(array_unique(array_intersect($granted, self::REQUIRED_DATABASE_PRIVILEGES)));
    }

    /**
     * Match a database name against a grant scope, which may be a LIKE
     * pattern (`digikash\_%`): unescaped % and _ are wildcards.
     */
    private static function databaseNameMatches(string $pattern, string $database): bool
    {
        $regex = '';

        foreach (preg_split('/(\\\\.|%|_)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $regex .= match (true) {
                $token === '%'                => '.*',
                $token === '_'                => '.',
                str_starts_with($token, '\\') => preg_quote(substr($token, 1), '/'),
                default                       => preg_quote($token, '/'),
            };
        }

        return preg_match('/^'.$regex.'$/i', $database) === 1;
    }
}
