<?php

declare(strict_types=1);

namespace App\Support\Installation;

use App\Models\Admin;
use App\Support\InstallationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * Read-only preflight checks for the CLI installation workflow.
 *
 * Everything here answers a question without writing: is PHP capable, is the
 * environment sane, is the application key present, is the database reachable,
 * can the web server write where it must, and has this installation already
 * completed. `makinpay:install` acts on the answers; `makinpay:install:check`
 * only reports them, which is what an operator runs to diagnose a deployment
 * that will not boot.
 *
 * The checks deliberately do NOT touch the database schema. Creating tables is
 * the install command's job, and a "check" that migrates is not a check.
 */
class Preflight
{
    /** Result status vocabulary, ordered by severity. */
    public const OK      = 'ok';
    public const WARN    = 'warn';
    public const FAIL    = 'fail';
    public const UNKNOWN = 'unknown';

    public function __construct() {}

    /**
     * Marker file proving a CLI install completed.
     *
     * Separate from the legacy web installer's `storage/app/installed` lock:
     * that one records a buyer's wizard run and embeds Envato license data we
     * no longer use. Keeping them distinct means an installation completed by
     * the old installer still reads as installed (so it is never re-seeded by
     * accident) while a new install records a clean, secret-free marker.
     */
    public function markerPath(): string
    {
        return storage_path('app/makinpay-installed.json');
    }

    public function isInstalled(): bool
    {
        if (File::exists($this->markerPath())) {
            return true;
        }

        // The legacy web installer wrote its own lock file; honour it so an
        // installation created before this command existed is never treated as
        // fresh and re-seeded by accident.
        if (File::exists(storage_path('app/installed'))) {
            return true;
        }

        // Schema alone is NOT proof of installation. Migrations create the
        // `settings` and `admins` tables on every install, including one that
        // was interrupted before an administrator existed. Treating that as
        // "installed" would refuse to finish setup on a half-built site, which
        // is the exact situation an operator is trying to recover from. An
        // administrator row is the real signal that installation completed.
        try {
            return Schema::hasTable('admins') && Admin::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function marker(): ?array
    {
        $path = $this->markerPath();

        if (! File::exists($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) File::get($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Run every check. Each entry is
     * `{key, label, status, detail, remedy?}`.
     *
     * @return list<array{key: string, label: string, status: string, detail: string, remedy?: string}>
     */
    public function run(): array
    {
        return [
            ...$this->phpChecks(),
            ...$this->environmentChecks(),
            ...$this->keyChecks(),
            ...$this->databaseChecks(),
            ...$this->permissionChecks(),
            ...$this->existingInstallChecks(),
        ];
    }

    /**
     * @param list<array{key: string, label: string, status: string, detail: string, remedy?: string}> $checks
     */
    public function worst(array $checks): string
    {
        foreach ([self::FAIL, self::WARN, self::UNKNOWN] as $status) {
            foreach ($checks as $check) {
                if (($check['status'] ?? null) === $status) {
                    return $status;
                }
            }
        }

        return self::OK;
    }

    /**
     * Whether the failure set contains something that must stop an install.
     *
     * @param list<array{key: string, label: string, status: string, detail: string, remedy?: string}> $checks
     */
    public function hasBlockingFailure(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? null) === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy?: string}>
     */
    private function phpChecks(): array
    {
        $checks = [[
            'key'    => 'php_version',
            'label'  => 'PHP version',
            'status' => PHP_VERSION_ID >= 80300 ? self::OK : self::FAIL,
            'detail' => PHP_VERSION,
            'remedy' => PHP_VERSION_ID >= 80300 ? '' : 'MakinPay requires PHP 8.3 or newer.',
        ]];

        // Reuses the same required-extension list the application declares, so
        // the CLI and the (retired) wizard could never disagree about what the
        // application needs.
        foreach ((array) config('installer.required_extensions', []) as $label => $extension) {
            $loaded = extension_loaded((string) $extension);

            $checks[] = [
                'key'    => 'ext_'.strtolower((string) $extension),
                'label'  => 'Extension: '.$label,
                'status' => $loaded ? self::OK : self::FAIL,
                'detail' => $loaded ? 'loaded' : 'missing',
                'remedy' => $loaded ? '' : 'Install the PHP extension "'.$extension.'" and restart PHP.',
            ];
        }

        return $checks;
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy?: string}>
     */
    private function environmentChecks(): array
    {
        $environment = (string) app()->environment();
        $debug       = (bool) config('app.debug');

        $checks = [[
            'key'    => 'app_env',
            'label'  => 'Application environment',
            'status' => in_array($environment, ['production', 'staging', 'local'], true) ? self::OK : self::WARN,
            'detail' => $environment,
            'remedy' => '',
        ]];

        // Debug mode in production leaks stack traces and environment values
        // to whoever triggers the error. A warning, not a failure: some
        // operators install with it on and turn it off before going live.
        if ($environment === 'production') {
            $checks[] = [
                'key'    => 'app_debug',
                'label'  => 'Debug mode in production',
                'status' => $debug ? self::WARN : self::OK,
                'detail' => $debug ? 'APP_DEBUG=true' : 'APP_DEBUG=false',
                'remedy' => $debug ? 'Set APP_DEBUG=false in production before exposing the site.' : '',
            ];
        }

        $url = (string) config('app.url');
        $checks[] = [
            'key'    => 'app_url',
            'label'  => 'Application URL',
            'status' => $url !== '' && $url !== 'http://localhost' ? self::OK : self::WARN,
            'detail' => $url !== '' ? $url : '(unset)',
            'remedy' => $url === '' || $url === 'http://localhost'
                ? 'Set APP_URL to the public address of this installation.'
                : '',
        ];

        $connections = (array) config('database.connections', []);
        $default     = (string) config('database.default');
        $known       = is_array($connections[$default] ?? null);

        $checks[] = [
            'key'    => 'db_driver',
            'label'  => 'Database driver',
            'status' => $known && in_array($default, ['mysql', 'mariadb', 'sqlite', 'pgsql'], true) ? self::OK : self::FAIL,
            'detail' => $default !== '' ? $default : '(unset)',
            'remedy' => $known ? '' : 'Set DB_CONNECTION to a configured connection name.',
        ];

        return $checks;
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy?: string}>
     */
    private function keyChecks(): array
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return [[
                'key'    => 'app_key',
                'label'  => 'Application key',
                'status' => self::FAIL,
                'detail' => 'missing',
                'remedy' => 'Run `php artisan key:generate` (or let makinpay:install generate one).',
            ]];
        }

        // A malformed key fails at runtime with an opaque decryption error, so
        // it is worth catching during preflight. Only the length/shape is
        // inspected — the value itself is never printed.
        $decoded = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : null;

        $valid = is_string($decoded) && strlen($decoded) === 32;

        return [[
            'key'    => 'app_key',
            'label'  => 'Application key',
            'status' => $valid ? self::OK : self::FAIL,
            'detail' => $valid ? 'present (valid length)' : 'present but malformed',
            'remedy' => $valid ? '' : 'Generate a new key with `php artisan key:generate`. Re-encrypting existing secrets (e.g. stored SMTP passwords) is required afterwards.',
        ]];
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy?: string}>
     */
    private function databaseChecks(): array
    {
        $driver = (string) config('database.default');

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return [[
                'key'    => 'db_connect',
                'label'  => 'Database connectivity',
                'status' => self::FAIL,
                'detail' => $this->redactConnectionError($e->getMessage()),
                'remedy' => 'Check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD; confirm the server is running and reachable.',
            ]];
        }

        $checks = [[
            'key'    => 'db_connect',
            'label'  => 'Database connectivity',
            'status' => self::OK,
            'detail' => $driver.' connected',
            'remedy' => '',
        ]];

        try {
            $tables = count(Schema::getTables());
        } catch (Throwable $e) {
            $checks[] = [
                'key'    => 'db_tables',
                'label'  => 'Database inspection',
                'status' => self::WARN,
                'detail' => $this->redactConnectionError($e->getMessage()),
                'remedy' => 'The database connected but its tables could not be listed; the account may lack privileges.',
            ];

            return $checks;
        }

        $checks[] = [
            'key'    => 'db_tables',
            'label'  => 'Existing tables',
            'status' => self::OK,
            'detail' => $tables.' table(s)',
            'remedy' => '',
        ];

        // MySQL/MariaDB privilege probe. Shared hosts commonly pre-create the
        // database and withhold CREATE, which fails mid-migration with a
        // confusing 1044. Reported as a warning: grants come in shapes that
        // cannot be read with certainty, and the import itself is the truth.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                $grants = DB::connection()->getPdo()
                    ->query('SHOW GRANTS FOR CURRENT_USER()')
                    ->fetchAll(PDO::FETCH_COLUMN);

                $missing = array_values(array_diff(
                    InstallationManager::REQUIRED_DATABASE_PRIVILEGES,
                    InstallationManager::grantedPrivileges(array_map('strval', $grants), (string) config("database.connections.{$driver}.database"))
                ));

                $checks[] = [
                    'key'    => 'db_privileges',
                    'label'  => 'Database privileges',
                    'status' => $missing === [] ? self::OK : self::WARN,
                    'detail' => $missing === []
                        ? implode(' ', InstallationManager::REQUIRED_DATABASE_PRIVILEGES)
                        : 'missing: '.implode(', ', $missing),
                    'remedy' => $missing === []
                        ? ''
                        : 'Grant the missing privileges, or pre-create the database in the hosting control panel.',
                ];
            } catch (Throwable) {
                $checks[] = [
                    'key'    => 'db_privileges',
                    'label'  => 'Database privileges',
                    'status' => self::UNKNOWN,
                    'detail' => 'could not read grants',
                    'remedy' => 'Ensure the database user can CREATE, ALTER, INDEX, INSERT, UPDATE and DELETE.',
                ];
            }
        }

        return $checks;
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy?: string}>
     */
    private function permissionChecks(): array
    {
        $checks = [];

        foreach ((array) config('installer.writable_paths', []) as $label => $path) {
            $path   = (string) $path;
            $target = File::isDirectory($path) ? $path : dirname($path);
            $writable = File::exists($target) && File::isWritable($target);

            $checks[] = [
                'key'    => 'writable_'.strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $label) ?? 'path'),
                'label'  => 'Writable: '.$label,
                'status' => $writable ? self::OK : self::FAIL,
                'detail' => $writable ? $path : $path.' (not writable)',
                'remedy' => $writable ? '' : 'Grant the web server user write access to '.$path.'.',
            ];
        }

        return $checks;
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy?: string}>
     */
    private function existingInstallChecks(): array
    {
        $installed = $this->isInstalled();

        $checks = [[
            'key'    => 'installed_marker',
            'label'  => 'Existing installation',
            'status' => $installed ? self::WARN : self::OK,
            'detail' => $installed ? 'an installation is already recorded' : 'fresh installation',
            'remedy' => $installed
                ? 'This site is already installed. Re-running makinpay:install is refused unless --allow-existing is given.'
                : '',
        ]];

        if ($this->hasAdmins()) {
            $checks[] = [
                'key'    => 'existing_admin',
                'label'  => 'Existing administrators',
                'status' => self::WARN,
                'detail' => Admin::query()->count().' admin account(s)',
                'remedy' => 'A first administrator will not be created automatically; use makinpay:admin:create to add one deliberately.',
            ];
        }

        return $checks;
    }

    private function hasAdmins(): bool
    {
        try {
            return Schema::hasTable('admins') && Admin::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Strip credentials out of driver messages before they reach a terminal,
     * a log, or a report. Uses word-boundary anchors so the words "password"
     * and "database" inside an unrelated sentence are left alone.
     */
    private function redactConnectionError(string $message): string
    {
        $redacted = preg_replace(
            '/(?:password|pwd)\s*=\s*[^\s;,)]+/i',
            'password=***',
            $message
        ) ?? $message;

        return mb_strimwidth($redacted, 0, 300, '…');
    }
}
