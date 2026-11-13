<?php

namespace App\Support;

use App\Constants\CurrencyType;
use App\Exceptions\NotifyErrorException;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\Language;
use App\Models\ProjectLicense;
use App\Models\Setting;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDO;
use Spatie\Permission\Models\Role;
use Throwable;

class InstallationManager
{
    private const LEGACY_DEFAULT_ADMIN_EMAIL = 'admin@coevs.com';

    public const DEFAULT_ADMIN_PREFIX = 'admin';

    public const DEFAULT_CURRENCY_CODE = 'USD';

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
    private const LOCAL_HOST_ALIASES = ['127.0.0.1', 'localhost'];

    public function __construct(
        private readonly EnvatoLicenseVerifier $envatoLicenseVerifier,
        private readonly SmtpConnectionTester $smtpConnectionTester,
    ) {}

    /**
     * Curated list of fiat currencies offered during installation. Keys are
     * the ISO 4217 codes and the values describe how the currency is
     * presented (name, symbol, ISO-3166 flag code used by the storefront).
     *
     * @return array<string, array{name: string, symbol: string, flag: string}>
     */
    public static function currencyCatalog(): array
    {
        return [
            'USD' => ['name' => 'US Dollar',           'symbol' => '$',   'flag' => 'us'],
            'EUR' => ['name' => 'Euro',                'symbol' => '€',   'flag' => 'eu'],
            'GBP' => ['name' => 'British Pound',       'symbol' => '£',   'flag' => 'gb'],
            'JPY' => ['name' => 'Japanese Yen',        'symbol' => '¥',   'flag' => 'jp'],
            'AUD' => ['name' => 'Australian Dollar',   'symbol' => 'A$',  'flag' => 'au'],
            'CAD' => ['name' => 'Canadian Dollar',     'symbol' => 'C$',  'flag' => 'ca'],
            'CHF' => ['name' => 'Swiss Franc',         'symbol' => 'CHF', 'flag' => 'ch'],
            'CNY' => ['name' => 'Chinese Yuan',        'symbol' => '¥',   'flag' => 'cn'],
            'SGD' => ['name' => 'Singapore Dollar',    'symbol' => 'S$',  'flag' => 'sg'],
            'INR' => ['name' => 'Indian Rupee',        'symbol' => '₹',   'flag' => 'in'],
            'BDT' => ['name' => 'Bangladeshi Taka',    'symbol' => '৳',  'flag' => 'bd'],
            'PKR' => ['name' => 'Pakistani Rupee',     'symbol' => '₨',   'flag' => 'pk'],
            'LKR' => ['name' => 'Sri Lankan Rupee',    'symbol' => 'Rs',  'flag' => 'lk'],
            'NPR' => ['name' => 'Nepalese Rupee',      'symbol' => 'रू', 'flag' => 'np'],
            'IDR' => ['name' => 'Indonesian Rupiah',   'symbol' => 'Rp',  'flag' => 'id'],
            'MYR' => ['name' => 'Malaysian Ringgit',   'symbol' => 'RM',  'flag' => 'my'],
            'THB' => ['name' => 'Thai Baht',           'symbol' => '฿',   'flag' => 'th'],
            'PHP' => ['name' => 'Philippine Peso',     'symbol' => '₱',   'flag' => 'ph'],
            'VND' => ['name' => 'Vietnamese Dong',     'symbol' => '₫',   'flag' => 'vn'],
            'KRW' => ['name' => 'South Korean Won',    'symbol' => '₩',   'flag' => 'kr'],
            'TRY' => ['name' => 'Turkish Lira',        'symbol' => '₺',   'flag' => 'tr'],
            'AED' => ['name' => 'UAE Dirham',          'symbol' => 'AED', 'flag' => 'ae'],
            'SAR' => ['name' => 'Saudi Riyal',         'symbol' => 'SAR', 'flag' => 'sa'],
            'EGP' => ['name' => 'Egyptian Pound',      'symbol' => 'E£',  'flag' => 'eg'],
            'NGN' => ['name' => 'Nigerian Naira',      'symbol' => '₦',   'flag' => 'ng'],
            'KES' => ['name' => 'Kenyan Shilling',     'symbol' => 'KSh', 'flag' => 'ke'],
            'ZAR' => ['name' => 'South African Rand',  'symbol' => 'R',   'flag' => 'za'],
            'GHS' => ['name' => 'Ghanaian Cedi',       'symbol' => '₵',   'flag' => 'gh'],
            'BRL' => ['name' => 'Brazilian Real',      'symbol' => 'R$',  'flag' => 'br'],
            'MXN' => ['name' => 'Mexican Peso',        'symbol' => 'Mex$', 'flag' => 'mx'],
        ];
    }

    /**
     * Timezones the installer offers: the same list the admin Site Timezone
     * select renders, so the value saved here is one that screen can show.
     * UTC is kept on top because it is what an installation defaults to.
     *
     * @return array<string, string> zone identifier => "(UTC+06:00) Dhaka"
     */
    public static function timezoneCatalog(): array
    {
        $catalog = ['UTC' => '(UTC+00:00) UTC'];

        try {
            foreach (getJsonData('time_zone') as $item) {
                $catalog[(string) $item['zone']] ??= trim($item['utc'].' '.$item['name']);
            }
        } catch (Throwable) {
            //
        }

        return $catalog;
    }

    /**
     * The Email step's provider tiles, from config/installer.php.
     *
     * @return array<string, array{label: string, host: ?string, port: int, encryption: string, hint: string}>
     */
    public static function mailProviders(): array
    {
        $providers = [];

        foreach ((array) config('installer.mail_providers', []) as $id => $provider) {
            if (! is_array($provider) || ! in_array($provider['encryption'] ?? null, ['tls', 'ssl'], true)) {
                continue;
            }

            $providers[(string) $id] = [
                'label'      => (string) ($provider['label'] ?? $id),
                'host'       => isset($provider['host']) ? (string) $provider['host'] : null,
                'port'       => (int) ($provider['port'] ?? 587),
                'encryption' => (string) $provider['encryption'],
                'hint'       => ($provider['hint'] ?? '') !== '' ? __((string) $provider['hint']) : '',
            ];
        }

        return $providers;
    }

    /**
     * Resolve the authoritative application URL straight from the live HTTP
     * request (scheme + host + non-standard port).
     *
     * The installer NEVER trusts a client-submitted app_url. A buyer could
     * edit the read-only field with DevTools to spoof another domain (for
     * example app.digikash.com) and defeat the per-domain license lock on
     * the update server, so the host is always taken from the real request
     * that is loading or submitting the installer.
     */
    public static function resolveInstallUrl(): string
    {
        $host = trim((string) request()->getSchemeAndHttpHost());

        return rtrim($host, '/');
    }

    /**
     * @return array{installed: bool, can_install: bool, requirements: list<array{label: string, status: bool, help: string}>, writable: list<array{label: string, status: bool, help: string}>}
     */
    public function status(): array
    {
        $requirements = $this->requirements();
        $writable     = $this->writablePaths();

        return [
            'installed'    => $this->isInstalled(),
            'can_install'  => collect([...$requirements, ...$writable])->every(fn (array $check): bool => $check['status']),
            'requirements' => $requirements,
            'writable'     => $writable,
        ];
    }

    public function isInstalled(): bool
    {
        if (File::exists($this->lockPath())) {
            return true;
        }

        return $this->hasInstalledDatabase();
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
     * Privileges the bundled SQL import and later migrations need.
     */
    /**
     * Queue drivers the Options step offers. Both need nothing beyond the
     * database the installer already set up.
     */
    public const QUEUE_CONNECTIONS = ['database', 'sync'];

    private const REQUIRED_DATABASE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES'];

    /**
     * @param  array<string, mixed>                                                                                                                                                       $data
     * @return array{ok: bool, status: string, message: string, server_version: string, latency_ms: int, checks: list<array{key: string, label: string, status: string, detail: string}>}
     */
    public function testDatabaseConnection(array $data): array
    {
        $connection = (string) $data['db_connection'];

        if ($connection === 'sqlite') {
            try {
                $database = $this->prepareSqliteDatabase((string) $data['db_database']);
            } catch (Throwable $e) {
                throw new NotifyErrorException(__('SQLite database file could not be prepared. Check file and folder write permissions. Error: :message', ['message' => $e->getMessage()]));
            }

            return [
                'ok'             => true,
                'status'         => 'success',
                'message'        => __('SQLite database file is ready for installation.'),
                'server_version' => 'SQLite '.(class_exists(\SQLite3::class) ? (\SQLite3::version()['versionString'] ?? '') : ''),
                'latency_ms'     => 0,
                'checks'         => [
                    [
                        'key'    => 'file',
                        'label'  => __('SQLite file'),
                        'status' => 'success',
                        'detail' => __('The database file is available at :path.', ['path' => $this->relativeDatabasePath($database)]),
                    ],
                    [
                        'key'    => 'writable',
                        'label'  => __('Writable path'),
                        'status' => 'success',
                        'detail' => __('Laravel can write to the selected SQLite location.'),
                    ],
                ],
            ];
        }

        $database = trim((string) $data['db_database']);

        if ($database === '') {
            throw new NotifyErrorException(__('Database name is required.'));
        }

        $started = hrtime(true);

        ['pdo' => $serverPdo, 'host' => $host] = $this->openMysqlServerPdo($data);

        $latencyMs     = (int) round((hrtime(true) - $started) / 1_000_000);
        $serverVersion = (string) $serverPdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $existed       = $this->databaseReachable($data, $host);

        $host            = $this->ensureMysqlDatabase($data);
        $data['db_host'] = $host;

        $this->configureDatabase($data);
        $tableCount = $this->databaseTableCount();
        $privileges = $this->privilegeCheck($serverPdo, $database);

        $readyForImport = $tableCount === 0;
        $port           = $this->resolvePort($data);

        return [
            'ok'      => $readyForImport,
            'status'  => $readyForImport ? 'success' : 'warning',
            'message' => $readyForImport
                ? __('Database connection is ready. The installer can import DB/digikash.sql.')
                : __('Database connection works, but this database already has :count tables. Use a new empty database before importing.', ['count' => $tableCount]),
            'server_version' => $serverVersion,
            'latency_ms'     => $latencyMs,
            'checks'         => [
                [
                    'key'    => 'reachable',
                    'label'  => __('Server reachable'),
                    'status' => 'success',
                    'detail' => $host.':'.$port,
                ],
                [
                    'key'    => 'credentials',
                    'label'  => __('Credentials accepted'),
                    'status' => 'success',
                    'detail' => (string) $data['db_username'],
                ],
                [
                    'key'    => 'database',
                    'label'  => $existed ? __('Database found') : __('Database created'),
                    'status' => 'success',
                    'detail' => $existed
                        ? __(':database — already existed', ['database' => $database])
                        : __(':database — new', ['database' => $database]),
                ],
                [
                    'key'    => 'empty',
                    'label'  => __('Database is empty'),
                    'status' => $readyForImport ? 'success' : 'warning',
                    'detail' => $readyForImport
                        ? __('0 tables — ready for the bundled SQL file')
                        : __(':count tables found — installation will stop to protect them', ['count' => $tableCount]),
                ],
                $privileges,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>                                                                                                                                                                                                   $data
     * @return array{ok: bool, status: string, message: string, checks: list<array{label: string, status: string, detail: string}>, details?: list<array{key: string, label: string, value: string}>, license?: array<string, mixed>}
     */
    public function verifyEnvatoLicense(array $data): array
    {
        if (! (bool) config('installer.envato.required', true)) {
            return [
                'ok'      => true,
                'status'  => 'success',
                'message' => __('Envato license verification is disabled.'),
                'checks'  => [],
            ];
        }

        return $this->envatoLicenseVerifier->verify(
            (string) ($data['envato_purchase_code'] ?? ''),
            (string) ($data['app_url'] ?? '')
        );
    }

    /**
     * Check the SMTP settings from the installer's Email step. With a
     * recipient a real test message is sent; without one only the connection
     * and sign-in are checked, which is what the final install re-runs.
     *
     * @param  array<string, mixed>                                                                                                 $data
     * @return array{ok: bool, status: string, message: string, checks: list<array{label: string, status: string, detail: string}>}
     */
    public function verifySmtpSettings(array $data, ?string $recipient = null): array
    {
        return $this->smtpConnectionTester->verify([
            'mail_host'          => (string) ($data['mail_host'] ?? ''),
            'mail_port'          => (int) ($data['mail_port'] ?? 0),
            'mail_secure'        => (string) ($data['mail_secure'] ?? 'tls'),
            'mail_username'      => (string) ($data['mail_username'] ?? ''),
            'mail_password'      => (string) ($data['mail_password'] ?? ''),
            'email_from_name'    => (string) ($data['email_from_name'] ?? ''),
            'email_from_address' => (string) ($data['email_from_address'] ?? ''),
        ], $recipient);
    }

    /**
     * Stage groups the wizard's Installing screen shows, in order.
     */
    public const INSTALL_STAGE_GROUPS = ['verify', 'environment', 'database', 'import', 'seed', 'finish'];

    /**
     * Every stage run so far in this request. Kept on the instance so the
     * controller can still report how far a failed install got.
     *
     * @var list<array{group: string, label: string, status: string, ms: int, message: string, at: string}>
     */
    private array $stageLog = [];

    private int $seedersRun = 0;

    /**
     * @param  array<string, mixed>                                                                                                                                                                                     $data
     * @return array{admin_url: string, admin_email: string, stages: list<array{group: string, label: string, status: string, ms: int, message: string, at: string}>, log: list<string>, summary: array<string, mixed>}
     */
    public function install(array $data): array
    {
        $startedAt        = microtime(true);
        $this->stageLog   = [];
        $this->seedersRun = 0;

        if ($this->isInstalled()) {
            throw new NotifyErrorException(__('The application is already installed.'));
        }

        if (! $this->status()['can_install']) {
            throw new NotifyErrorException(__('Resolve the server requirement checks before installing.'));
        }

        // Authoritative host enforcement: discard whatever app_url the
        // client submitted and lock it to the real request host before the
        // value is written to .env or sent to the license/update server.
        $data['app_url'] = self::resolveInstallUrl();

        $license = $this->runStage(__('Verifying license'), function () use ($data): array {
            $license = $this->verifyEnvatoLicense($data);

            if (! $license['ok']) {
                throw new NotifyErrorException($license['message']);
            }

            return $license;
        }, 'verify');

        // The Email step is optional. When the buyer filled it in, its gate
        // only ran in the browser, so re-check the connection here before
        // anything is written rather than saving settings that cannot send.
        $configureMail = ! (bool) ($data['mail_skip'] ?? false);

        if ($configureMail) {
            $this->runStage(__('Checking mail server'), function () use ($data): void {
                $smtp = $this->verifySmtpSettings($data);

                if (! $smtp['ok']) {
                    throw new NotifyErrorException($smtp['message']);
                }
            }, 'verify');
        }

        $this->runStage(__('Clearing application caches'), fn () => Artisan::call('optimize:clear'), 'environment');

        if ($this->usesSqlDumpImport((string) $data['db_connection'])) {
            $this->runStage(__('Preparing MySQL database'), function () use (&$data) {
                $data['db_host'] = $this->prepareMysqlDatabase($data);
            }, 'database');
            $this->runStage(__('Writing .env file'), fn () => $this->writeEnvironment($data), 'environment');

            $dumpImported = (bool) $this->runStage(__('Importing DB/digikash.sql'), fn () => $this->importSqlDump(), 'import');

            // The bundled SQL dump is a point-in-time snapshot. Any migration
            // added to the codebase after that snapshot (e.g. wallet_earn_plans)
            // would otherwise leave the production DB short of tables. Always
            // run migrate so Laravel's migrations table picks up only the
            // entries that aren't already recorded in the dump.
            $this->runStage(__('Applying pending migrations'), fn () => Artisan::call('migrate', ['--force' => true]), 'import');

            // If the dump was missing/empty we never imported any reference
            // data — fall back to running the core seeders so settings,
            // payment gateways, features, etc. exist. All core seeders are
            // idempotent (firstOrCreate / updateOrCreate), so running them
            // after a partial dump would also be safe; we only opt-in here
            // to keep the happy path predictable.
            $this->runStage(__('Seeding reference data'), fn () => $this->seedAfterImport($dumpImported, (bool) ($data['seed_demo_data'] ?? false)), 'seed');
        } else {
            $this->runStage(__('Configuring database connection'), fn () => $this->configureDatabase($data), 'database');
            $this->runStage(__('Writing .env file'), fn () => $this->writeEnvironment($data), 'environment');
            $this->runStage(__('Running migrations'), fn () => Artisan::call('migrate', ['--force' => true]), 'import');
            $this->runStage(__('Running seeders'), fn () => $this->runSeeders((bool) ($data['seed_demo_data'] ?? false)), 'seed');
        }

        // The bundled dump is a point-in-time snapshot and the SQLite path never
        // imports it at all, so either way this installation can come up missing
        // reference data the current code reads. Reconciling here keeps that from
        // becoming something the buyer has to run a seeder by hand to fix.
        $this->runStage(__('Reconciling reference data'), fn () => Artisan::call('install:reconcile'), 'seed');

        $this->runStage(__('Seeding default language'), fn () => $this->seedDefaultLanguage(), 'seed');
        $this->runStage(__('Seeding default currency'), fn () => $this->seedDefaultCurrency((string) ($data['default_currency_code'] ?? self::DEFAULT_CURRENCY_CODE)), 'seed');
        $this->runStage(__('Seeding default settings'), fn () => $this->seedDefaultSettings(
            (string) $data['app_name'],
            (string) ($data['admin_prefix'] ?? self::DEFAULT_ADMIN_PREFIX),
            (string) ($data['site_timezone'] ?? ''),
        ), 'seed');

        if ($configureMail) {
            $this->runStage(__('Saving mail settings'), fn () => $this->storeMailSettings($data), 'seed');
        }

        $this->runStage(__('Applying install options'), fn () => $this->applyInstallOptions($data), 'seed');
        $this->runStage(__('Saving project license'), fn () => $this->storeProjectLicense($license, (string) $data['envato_purchase_code']), 'seed');

        $admin = $this->runStage(__('Creating super admin account'), fn () => $this->createAdmin($data), 'seed');
        $this->runStage(__('Writing installer lock file'), fn () => $this->markInstalled($data, $admin, $license), 'finish');

        // Create the public/storage symlink so logos, KYC docs and any
        // other uploaded media in storage/app/public become reachable via
        // /storage/* URLs. The release builder unlinks this on the seller
        // side — the buyer's install is where it needs to be put back.
        $this->runOptionalStage(__('Linking public storage'), fn () => Artisan::call('storage:link', ['--force' => true]), 'finish');

        $this->runStage(__('Finalising caches'), fn () => Artisan::call('optimize:clear'), 'finish');

        // The same optimisation the admin "Optimize" button runs. The site is
        // installed by now, so a host that cannot write the cache files gets
        // a skipped step rather than a failed install.
        if ((bool) ($data['optimize_caches'] ?? false)) {
            $this->runOptionalStage(__('Caching config and routes'), fn () => Artisan::call('app:optimize'), 'finish');
        }

        $adminUrl = url(trim((string) setting('admin_prefix', self::DEFAULT_ADMIN_PREFIX), '/').'/login');

        return [
            'admin_url'   => $adminUrl,
            'admin_email' => $admin->email,
            'stages'      => $this->stageLog,
            'log'         => $this->stageLogLines(),
            'summary'     => $this->installSummary($startedAt, $adminUrl, $admin->email, (string) $data['app_url']),
        ];
    }

    /**
     * How far the last install in this request got, for a failure response.
     *
     * @return array{stages: list<array{group: string, label: string, status: string, ms: int, message: string, at: string}>, log: list<string>}
     */
    public function stageReport(): array
    {
        return [
            'stages' => $this->stageLog,
            'log'    => $this->stageLogLines(),
        ];
    }

    /**
     * Execute one install stage and convert any unexpected throwable into a
     * NotifyErrorException with the stage label baked in. NotifyErrorException
     * already-thrown by the stage callback is re-raised as-is. Either way the
     * stage is recorded with its duration for the install report.
     *
     * @template TStageReturn
     *
     * @param  callable(): TStageReturn $callback
     * @return TStageReturn
     */
    private function runStage(string $label, callable $callback, string $group = 'setup'): mixed
    {
        $started = hrtime(true);

        try {
            $result = $callback();

            $this->recordStage($group, $label, 'done', $started);

            return $result;
        } catch (NotifyErrorException $e) {
            $this->recordStage($group, $label, 'failed', $started, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            Log::error(sprintf('Installer stage "%s" failed: %s', $label, $e->getMessage()), [
                'exception' => $e,
            ]);

            $this->recordStage($group, $label, 'failed', $started, $e->getMessage());

            throw new NotifyErrorException(__(':label failed: :message', [
                'label'   => $label,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * A stage the installation does not depend on: a failure is logged and
     * reported as skipped, never thrown.
     */
    private function runOptionalStage(string $label, callable $callback, string $group): void
    {
        $started = hrtime(true);

        try {
            $callback();

            $this->recordStage($group, $label, 'done', $started);
        } catch (Throwable $e) {
            Log::warning(sprintf('Installer step "%s" skipped: %s', $label, $e->getMessage()));

            $this->recordStage($group, $label, 'skipped', $started, $e->getMessage());
        }
    }

    private function recordStage(string $group, string $label, string $status, int $started, string $message = ''): void
    {
        $this->stageLog[] = [
            'group'   => $group,
            'label'   => $label,
            'status'  => $status,
            'ms'      => (int) round((hrtime(true) - $started) / 1_000_000),
            'message' => $message,
            'at'      => now()->format('H:i:s'),
        ];
    }

    /**
     * The live log the Installing screen prints: labels, outcomes and
     * durations only — stage messages come from exceptions the installer
     * already shows the buyer, and never carry a credential.
     *
     * @return list<string>
     */
    private function stageLogLines(): array
    {
        return array_map(fn (array $stage): string => sprintf('[%s] %s … %s', $stage['at'], $stage['label'], match ($stage['status']) {
            'done'    => 'ok ('.$this->formatDuration($stage['ms']).')',
            'skipped' => 'skipped: '.$stage['message'],
            default   => 'failed: '.$stage['message'],
        }), $this->stageLog);
    }

    private function formatDuration(int $milliseconds): string
    {
        return $milliseconds >= 1000
            ? number_format($milliseconds / 1000, 1).' s'
            : $milliseconds.' ms';
    }

    /**
     * What the Success screen shows about the finished installation.
     *
     * @return array{duration_s: int, tables_count: int, seeders_count: int, admin_login_url: string, admin_email: string, sql_dump_present: bool, env_exposed: ?bool}
     */
    private function installSummary(float $startedAt, string $adminUrl, string $adminEmail, string $appUrl): array
    {
        try {
            $tables = count(Schema::getTables());
        } catch (Throwable) {
            $tables = 0;
        }

        return [
            'duration_s'       => (int) round(microtime(true) - $startedAt),
            'tables_count'     => $tables,
            'seeders_count'    => $this->seedersRun,
            'admin_login_url'  => $adminUrl,
            'admin_email'      => $adminEmail,
            'sql_dump_present' => File::exists((string) config('installer.database_dump', base_path('DB/digikash.sql'))),
            'env_exposed'      => $this->environmentFileIsPublic($appUrl),
        ];
    }

    /**
     * Ask the site for its own .env over HTTP. True means anyone can read the
     * database password and APP_KEY; null means the server could not reach
     * itself (common behind NAT), which is not the same as safe.
     */
    public function environmentFileIsPublic(string $appUrl): ?bool
    {
        try {
            $response = Http::timeout(3)
                ->withoutRedirecting()
                ->get(rtrim($appUrl, '/').'/.env');
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && str_contains($response->body(), 'APP_KEY=');
    }

    /**
     * Platform-specific permission guidance shown next to the install error
     * banner. Each entry is a self-contained instruction the buyer can run
     * or follow without further context.
     *
     * @return list<string>
     */
    public function permissionGuidance(): array
    {
        return [
            __('Linux / VPS: run `sudo chown -R www-data:www-data storage bootstrap/cache public` then `sudo chmod -R 775 storage bootstrap/cache`. Replace `www-data` with your web server user (`nginx`, `apache`, or your cPanel user).'),
            __('cPanel / shared hosting: open File Manager, select `storage`, `bootstrap/cache`, and `public`, click Permissions, and set the value to 755 (recursive).'),
            __('Windows / IIS: grant the `IIS_IUSRS` and the AppPool identity Modify permission on `storage`, `bootstrap/cache`, `public`, and the `.env` file.'),
            __('Verify `open_basedir` and `disable_functions` in php.ini do not block `chmod`, `mkdir`, or `symlink`. Re-run the server check after applying the fix.'),
        ];
    }

    /**
     * @return list<array{label: string, status: bool, help: string}>
     */
    private function requirements(): array
    {
        $checks = [
            [
                'label'  => 'PHP 8.3+',
                'status' => PHP_VERSION_ID >= 80300,
                'help'   => 'Required by this Laravel application.',
            ],
        ];

        foreach (config('installer.required_extensions', []) as $label => $extension) {
            $checks[] = [
                'label'  => (string) $label,
                'status' => extension_loaded((string) $extension),
                'help'   => 'Required PHP extension.',
            ];
        }

        foreach (config('installer.required_functions', []) as $function => $help) {
            $checks[] = [
                'label'  => (string) $function.'()',
                'status' => $this->functionEnabled((string) $function),
                'help'   => (string) $help,
            ];
        }

        return $checks;
    }

    /**
     * A PHP function is usable only when it both exists and is not listed in
     * the `disable_functions` php.ini directive. Shared hosts frequently
     * disable `exec` while it still passes `function_exists()`, so both
     * checks are required.
     */
    private function functionEnabled(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }

        $disabled = array_map(
            static fn (string $name): string => strtolower(trim($name)),
            explode(',', (string) ini_get('disable_functions'))
        );

        return ! in_array(strtolower($function), $disabled, true);
    }

    /**
     * @return list<array{label: string, status: bool, help: string}>
     */
    private function writablePaths(): array
    {
        $checks = [];

        foreach (config('installer.writable_paths', []) as $label => $path) {
            $path   = (string) $path;
            $result = $this->probeWritable($path);

            $checks[] = [
                'label'  => (string) $label,
                'status' => $result['ok'],
                'help'   => $result['ok'] ? $path : __(':path — :reason', ['path' => $path, 'reason' => $result['reason']]),
            ];
        }

        return $checks;
    }

    /**
     * Actually try writing a probe file in the target location. Some hosts
     * (open_basedir, suexec/FastCGI user mismatch, ACL, Windows IIS) make
     * `is_writable()` report true while real writes still fail, so the
     * installer needs to test with a real I/O round-trip.
     *
     * @return array{ok: bool, reason: string}
     */
    private function probeWritable(string $path): array
    {
        $directory = File::exists($path) && File::isDirectory($path) ? $path : dirname($path);

        if (! File::exists($directory)) {
            try {
                File::ensureDirectoryExists($directory, 0775);
            } catch (Throwable $e) {
                return ['ok' => false, 'reason' => __('directory missing and cannot be created (:message)', ['message' => $e->getMessage()])];
            }
        }

        if (File::exists($path) && File::isFile($path) && ! File::isWritable($path)) {
            $this->attemptChmod($path, 0664);

            if (! File::isWritable($path)) {
                return ['ok' => false, 'reason' => __('file is not writable by the web server user')];
            }
        }

        if (! File::isWritable($directory)) {
            $this->attemptChmod($directory, 0775);

            if (! File::isWritable($directory)) {
                return ['ok' => false, 'reason' => __('directory is not writable by the web server user')];
            }
        }

        $probe = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'.digikash-probe-'.bin2hex(random_bytes(4));

        try {
            File::put($probe, 'probe');

            if (! File::exists($probe) || File::get($probe) !== 'probe') {
                return ['ok' => false, 'reason' => __('probe file could not be read back after writing')];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => __('write rejected by the host (:message)', ['message' => $e->getMessage()])];
        } finally {
            if (File::exists($probe)) {
                try {
                    File::delete($probe);
                } catch (Throwable) {
                    //
                }
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    private function attemptChmod(string $path, int $mode): void
    {
        try {
            @chmod($path, $mode);
        } catch (Throwable) {
            //
        }
    }

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
     * @param array<string, mixed> $data
     */
    private function configureDatabase(array $data): void
    {
        $connection = (string) $data['db_connection'];
        $database   = (string) $data['db_database'];

        if ($connection === 'sqlite') {
            $database = $this->prepareSqliteDatabase($database);
            Config::set('database.connections.sqlite.database', $database);
        } else {
            Config::set("database.connections.{$connection}.host", (string) $data['db_host']);
            Config::set("database.connections.{$connection}.port", (string) $data['db_port']);
            Config::set("database.connections.{$connection}.database", $database);
            Config::set("database.connections.{$connection}.username", (string) $data['db_username']);
            Config::set("database.connections.{$connection}.password", (string) ($data['db_password'] ?? ''));
        }

        Config::set('database.default', $connection);
        DB::purge($connection);
        DB::setDefaultConnection($connection);

        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable $e) {
            throw new NotifyErrorException(__('Database connection failed: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Ensure the MySQL database exists and is empty, returning the host that
     * actually connected so the caller can persist it to .env.
     *
     * @param array<string, mixed> $data
     */
    private function prepareMysqlDatabase(array $data): string
    {
        $connection = (string) $data['db_connection'];

        if (! in_array($connection, ['mysql', 'mariadb'], true)) {
            return (string) ($data['db_host'] ?? '127.0.0.1');
        }

        $host            = $this->ensureMysqlDatabase($data);
        $data['db_host'] = $host;

        $this->configureDatabase($data);

        if (! $this->databaseIsEmpty()) {
            throw new NotifyErrorException(__('The selected database already has tables. Use a new empty database name, or clear the database before importing the script.'));
        }

        return $host;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mysqlServerPdo(array $data): PDO
    {
        return $this->openMysqlServerPdo($data)['pdo'];
    }

    /**
     * Open a server-level (no dbname) MySQL PDO, transparently falling back
     * between 127.0.0.1 and localhost (TCP vs unix socket) when a *local*
     * host refuses the connection. Returns the host that actually worked so
     * it can be persisted to .env — buyers should not have to know whether
     * their server speaks TCP or a socket.
     *
     * @param  array<string, mixed>          $data
     * @return array{pdo: PDO, host: string}
     */
    private function openMysqlServerPdo(array $data): array
    {
        $port     = $this->resolvePort($data);
        $username = (string) $data['db_username'];
        $password = (string) ($data['db_password'] ?? '');

        $lastError = null;

        foreach ($this->candidateHosts((string) ($data['db_host'] ?? '')) as $host) {
            try {
                $pdo = new PDO(
                    "mysql:host={$host};port={$port};charset=utf8mb4",
                    $username,
                    $password,
                    [
                        PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE     => PDO::FETCH_ASSOC,
                        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                    ]
                );

                return ['pdo' => $pdo, 'host' => $host];
            } catch (Throwable $e) {
                $lastError = $e;

                // Auth / privilege errors are not host problems — stop trying
                // alternate hosts so the buyer sees the real cause at once.
                if (! $this->isHostConnectionError($e)) {
                    break;
                }
            }
        }

        throw new NotifyErrorException(__('Database server connection failed. Check host, port, username, and password. Error: :message', ['message' => $lastError?->getMessage() ?? 'unknown']));
    }

    /**
     * Candidate hosts to try, in order. When the buyer leaves a local host,
     * we automatically try the other local alias (127.0.0.1 ↔ localhost).
     *
     * @return list<string>
     */
    private function candidateHosts(string $host): array
    {
        $host = trim($host);

        if ($host === '') {
            $host = '127.0.0.1';
        }

        $candidates = [$host];

        if (in_array(strtolower($host), self::LOCAL_HOST_ALIASES, true)) {
            foreach (self::LOCAL_HOST_ALIASES as $alias) {
                if (strcasecmp($alias, $host) !== 0) {
                    $candidates[] = $alias;
                }
            }
        }

        return $candidates;
    }

    /**
     * Normalise the MySQL port. Almost every server uses 3306, so a blank or
     * non-numeric value falls back to the standard port rather than failing —
     * buyers should not have to think about it.
     *
     * @param array<string, mixed> $data
     */
    private function resolvePort(array $data): string
    {
        $port = trim((string) ($data['db_port'] ?? ''));

        return $port !== '' && ctype_digit($port) ? $port : '3306';
    }

    private function isHostConnectionError(Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (['2002', '2003', '2005', 'Connection refused', 'No such file or directory', "Can't connect", 'Unknown MySQL server host', 'timed out'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the named database is already reachable with the given
     * credentials. Used to SKIP `CREATE DATABASE` on shared hosting, where
     * the database is pre-created in the control panel and the user has no
     * privilege to create databases (which otherwise fails with a confusing
     * "1044 Access denied … to database" error).
     *
     * @param array<string, mixed> $data
     */
    private function databaseReachable(array $data, string $host): bool
    {
        $port     = $this->resolvePort($data);
        $database = (string) $data['db_database'];
        $username = (string) $data['db_username'];
        $password = (string) ($data['db_password'] ?? '');

        try {
            new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ensure the target database exists, creating it only when needed, and
     * return the host that actually connected.
     *
     * @param array<string, mixed> $data
     */
    private function ensureMysqlDatabase(array $data): string
    {
        $database = trim((string) $data['db_database']);

        if ($database === '') {
            throw new NotifyErrorException(__('Database name is required.'));
        }

        ['pdo' => $pdo, 'host' => $host] = $this->openMysqlServerPdo($data);

        // If the database is already reachable (typical on shared hosting,
        // where it is pre-created), never attempt CREATE — that is what
        // triggers the "1044 Access denied" error for restricted users.
        if ($this->databaseReachable($data, $host)) {
            return $host;
        }

        try {
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $this->quoteMysqlIdentifier($database)
            ));
        } catch (Throwable $e) {
            throw new NotifyErrorException(__('The database ":database" does not exist yet and this database user cannot create it. Create the database in your hosting control panel (for example cPanel → MySQL Databases) using exactly this name, then run the test again. Error: :message', [
                'database' => $database,
                'message'  => $e->getMessage(),
            ]));
        }

        return $host;
    }

    /**
     * A warning, never a blocker: grant rows come in shapes (roles, partial
     * revokes, proxy users) that cannot all be read with certainty, and an
     * import that really lacks a privilege fails with the server's own error.
     *
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function privilegeCheck(PDO $pdo, string $database): array
    {
        try {
            $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [
                'key'    => 'privileges',
                'label'  => __('Privileges'),
                'status' => 'warning',
                'detail' => __('Could not read this user\'s grants. Make sure it has :list on the database.', ['list' => implode(', ', self::REQUIRED_DATABASE_PRIVILEGES)]),
            ];
        }

        $granted = self::grantedPrivileges(array_map('strval', $grants), $database);
        $missing = array_values(array_diff(self::REQUIRED_DATABASE_PRIVILEGES, $granted));

        return [
            'key'    => 'privileges',
            'label'  => __('Privileges'),
            'status' => $missing === [] ? 'success' : 'warning',
            'detail' => $missing === []
                ? implode(' ', self::REQUIRED_DATABASE_PRIVILEGES)
                : __('Missing :list — grant them before installing, or the import may fail.', ['list' => implode(', ', $missing)]),
        ];
    }

    /**
     * The privileges a list of MySQL/MariaDB GRANT rows gives on one database:
     * global (*.*) grants and database-level grants whose name, or LIKE
     * pattern, matches. Table- and column-level grants are ignored because
     * the import creates new tables.
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

    private function databaseIsEmpty(): bool
    {
        return $this->databaseTableCount() === 0;
    }

    private function databaseTableCount(): int
    {
        try {
            return count(Schema::getTables());
        } catch (Throwable $e) {
            throw new NotifyErrorException(__('Database was created, but tables could not be inspected: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Import the bundled SQL dump.
     *
     * Returns true when statements were imported, false when the dump file
     * is missing or empty. A missing dump is no longer fatal — the install
     * flow falls back to `migrate` + `seed` so the install can finish even
     * if the dump isn't shipped with the release.
     *
     * Any real import error (bad SQL, dropped connection) still throws a
     * NotifyErrorException so the buyer sees an actionable banner.
     */
    private function importSqlDump(): bool
    {
        $dumpPath = (string) config('installer.database_dump', base_path('DB/digikash.sql'));

        if (! File::exists($dumpPath) || ! File::isReadable($dumpPath)) {
            Log::warning('Installer SQL dump missing or unreadable; falling back to migrations: '.$dumpPath);

            return false;
        }

        $sql = File::get($dumpPath);

        if (trim($sql) === '') {
            Log::warning('Installer SQL dump is empty; falling back to migrations: '.$dumpPath);

            return false;
        }

        try {
            DB::connection()->unprepared('SET FOREIGN_KEY_CHECKS=0');

            foreach ($this->splitSqlStatements($sql) as $statement) {
                if ($this->shouldSkipSqlImportStatement($statement)) {
                    continue;
                }

                DB::connection()->unprepared($statement);
            }

            DB::connection()->unprepared('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            try {
                DB::connection()->unprepared('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
                //
            }

            throw new NotifyErrorException(__('Database import failed from DB/digikash.sql: :message', ['message' => $e->getMessage()]));
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $statement  = '';
        $length     = strlen($sql);
        $quote      = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $char === '-' && $next === '-' && $this->isSqlCommentStart($sql[$i + 2] ?? '')) {
                $i = $this->skipUntilNewline($sql, $i + 2);

                continue;
            }

            if ($quote === null && $char === '#') {
                $i = $this->skipUntilNewline($sql, $i);

                continue;
            }

            if ($quote === null && $char === '/' && $next === '*') {
                $i = $this->skipBlockComment($sql, $i + 2);

                continue;
            }

            if (($char === "'" || $char === '"' || $char === '`') && ! $this->isEscaped($sql, $i)) {
                $quote = $quote === $char ? null : ($quote ?? $char);
            }

            if ($char === ';' && $quote === null) {
                $trimmed = trim($statement);

                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }

                $statement = '';

                continue;
            }

            $statement .= $char;
        }

        $trimmed = trim($statement);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private function shouldSkipSqlImportStatement(string $statement): bool
    {
        $normalized = ltrim($statement);

        return preg_match('/^(CREATE\s+DATABASE|USE\s+|DELIMITER\b)/i', $normalized) === 1;
    }

    private function isSqlCommentStart(string $char): bool
    {
        return $char === '' || ctype_space($char);
    }

    private function skipUntilNewline(string $sql, int $offset): int
    {
        $newline = strpos($sql, "\n", $offset);

        return $newline === false ? strlen($sql) : $newline;
    }

    private function skipBlockComment(string $sql, int $offset): int
    {
        $end = strpos($sql, '*/', $offset);

        return $end === false ? strlen($sql) : $end + 1;
    }

    private function isEscaped(string $sql, int $index): bool
    {
        $slashes = 0;

        for ($i = $index - 1; $i >= 0 && $sql[$i] === '\\'; $i--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }

    private function quoteMysqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function usesSqlDumpImport(string $connection): bool
    {
        return in_array($connection, ['mysql', 'mariadb'], true);
    }

    private function prepareSqliteDatabase(string $database): string
    {
        if ($database === ':memory:') {
            return $database;
        }

        $database = $this->absolutePath($database);

        File::ensureDirectoryExists(dirname($database));

        if (! File::exists($database)) {
            File::put($database, '');
        }

        return $database;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeEnvironment(array $data): void
    {
        $appKey = $this->generateApplicationKey();
        $values = $this->environmentValues($data, $appKey);

        Config::set('app.key', $appKey);
        Config::set('project_updater.server_url', $values['PROJECT_UPDATER_SERVER_URL']);
        Config::set('project_updater.item_id', $values['PROJECT_UPDATER_ENVATO_ITEM_ID']);
        Config::set('project_updater.product_slug', $values['PROJECT_UPDATER_PRODUCT_SLUG']);
        Config::set('project_updater.channel', $values['PROJECT_UPDATER_CHANNEL']);
        app()->forgetInstance('encrypter');
        // The Crypt facade keeps its own copy of the encrypter. Left alone, a
        // secret sealed later in this run (the SMTP password) would use the
        // old key and fail to decrypt on every request after install.
        Crypt::clearResolvedInstance('encrypter');

        $this->writeEnvironmentFile($values);
    }

    /**
     * The .env keys the installer owns. QUEUE_CONNECTION is written only when
     * the Options step chose one, so a submission without it keeps the
     * value .env.example ships.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function environmentValues(array $data, string $appKey): array
    {
        $connection = (string) $data['db_connection'];
        $database   = (string) $data['db_database'];

        if ($connection === 'sqlite' && $database !== ':memory:') {
            $database = $this->relativeDatabasePath($this->absolutePath($database));
        }

        $values = [
            'APP_NAME'                       => (string) $data['app_name'],
            'APP_ENV'                        => 'production',
            'APP_KEY'                        => $appKey,
            'APP_DEBUG'                      => 'false',
            'APP_URL'                        => rtrim((string) $data['app_url'], '/'),
            'DB_CONNECTION'                  => $connection,
            'DB_HOST'                        => (string) ($data['db_host'] ?? '127.0.0.1'),
            'DB_PORT'                        => (string) ($data['db_port'] ?? '3306'),
            'DB_DATABASE'                    => $database,
            'DB_USERNAME'                    => (string) ($data['db_username'] ?? ''),
            'DB_PASSWORD'                    => (string) ($data['db_password'] ?? ''),
            'PROJECT_UPDATER_SERVER_URL'     => (string) config('project_updater.server_url', 'https://updates.coevs.com'),
            'PROJECT_UPDATER_ENVATO_ITEM_ID' => (string) config('project_updater.item_id', '58275561'),
            'PROJECT_UPDATER_PRODUCT_SLUG'   => (string) config('project_updater.product_slug', 'digikash'),
            'PROJECT_UPDATER_CHANNEL'        => (string) config('project_updater.channel', 'stable'),
            'SESSION_DRIVER'                 => 'file',
        ];

        $queue = (string) ($data['queue_connection'] ?? '');

        if (in_array($queue, self::QUEUE_CONNECTIONS, true)) {
            $values['QUEUE_CONNECTION'] = $queue;
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnvironmentFile(array $values): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            if (File::exists(base_path('.env.example'))) {
                File::copy(base_path('.env.example'), $envPath);
            } else {
                File::put($envPath, '');
            }
        }

        $content = File::get($envPath);

        foreach ($values as $key => $value) {
            $line    = $key.'='.$this->formatEnvironmentValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $content) === 1) {
                $content = preg_replace($pattern, $line, $content) ?? $content;
            } else {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }

        $finalContent = rtrim($content).PHP_EOL;
        $bytesWritten = File::put($envPath, $finalContent);

        if ($bytesWritten === false || ! File::exists($envPath) || str_contains(File::get($envPath), 'APP_KEY=') === false) {
            throw new NotifyErrorException(__('Could not write the .env file at :path. Grant the web server user write permission to this file and the project root, then try again.', ['path' => $envPath]));
        }
    }

    private function generateApplicationKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
    }

    private function formatEnvironmentValue(mixed $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '""';
        }

        if (preg_match('/[\s#"\'=]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    private function absolutePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === ':memory:' || preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function relativeDatabasePath(string $path): string
    {
        $normalizedBase = str_replace('\\', '/', base_path());
        $normalizedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalizedPath, $normalizedBase.'/')) {
            return ltrim(substr($normalizedPath, strlen($normalizedBase)), '/');
        }

        return $path;
    }

    private function runSeeders(bool $includeDemoData): void
    {
        foreach (config('installer.core_seeders', []) as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
            $this->seedersRun++;
        }

        if ($includeDemoData) {
            $this->runDemoSeeders();
        }
    }

    private function runDemoSeeders(): void
    {
        foreach (config('installer.demo_seeders', []) as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
            $this->seedersRun++;
        }
    }

    /**
     * Seed what the SQL dump did not bring. A dump that imported already
     * holds the reference data, but never demo data — so the "Install demo
     * data" choice has to be honoured here too, or a MySQL install (the only
     * path that imports the dump) would silently ignore it.
     */
    private function seedAfterImport(bool $dumpImported, bool $includeDemoData): void
    {
        if (! $dumpImported) {
            $this->runSeeders($includeDemoData);

            return;
        }

        if ($includeDemoData) {
            $this->runDemoSeeders();
        }
    }

    /**
     * The Options step's site-level switches. A key the submission left out
     * keeps whatever the seeded default is, so the legacy form (which has no
     * Options step) changes nothing.
     *
     * @param array<string, mixed> $data
     */
    private function applyInstallOptions(array $data): void
    {
        if (array_key_exists('pwa_enabled', $data)) {
            Setting::set('pwa_enabled', (bool) $data['pwa_enabled'], 'bool');
        }
    }

    /**
     * The crontab line the Laravel scheduler needs, for this project path.
     */
    public static function cronCommand(): string
    {
        return '* * * * * cd '.str_replace('\\', '/', base_path()).' && php artisan schedule:run >> /dev/null 2>&1';
    }

    private function seedDefaultLanguage(): void
    {
        Language::query()->firstOrCreate(
            ['code' => 'en'],
            [
                'flag'       => 'us',
                'name'       => 'English',
                'is_default' => true,
                'status'     => true,
            ]
        );
    }

    private function seedDefaultSettings(string $appName, string $adminPrefix, string $timezone = ''): void
    {
        foreach (config('settings', []) as $section) {
            foreach ($section['elements'] ?? [] as $field) {
                $key = $field['key'] ?? null;

                if (! is_string($key) || Setting::has($key)) {
                    continue;
                }

                $value = $field['value'] ?? '';

                if (is_array($value)) {
                    continue;
                }

                Setting::add($key, $value, (string) ($field['data'] ?? 'string'));
            }
        }

        Setting::set('site_title', $appName, 'string');
        Setting::set('admin_prefix', $this->sanitizeAdminPrefix($adminPrefix), 'string');
        Setting::set('site_timezone', array_key_exists($timezone, self::timezoneCatalog()) ? $timezone : (string) config('app.timezone', 'UTC'), 'string');
        // Home redirect defaults to '/' so the public landing page is shown.
        // Previously this defaulted to the string 'login', which Laravel
        // resolved to '/login' — a URL that doesn't exist (the real user
        // login is at /user/login) — so every buyer landed on a 404 after
        // install.
        Setting::set('home_redirect', '/', 'string');
        Setting::flushCache();
    }

    /**
     * Save the SMTP settings verified on the Email step under the keys the
     * admin Mail Settings screen edits, so AppConfigService sends with them
     * from the first request. Setting::set seals the password with the
     * application key writeEnvironment() put in .env earlier in this run.
     *
     * @param array<string, mixed> $data
     */
    private function storeMailSettings(array $data): void
    {
        Setting::set('email_from_name', trim((string) $data['email_from_name']), 'string');
        Setting::set('email_from_address', trim((string) $data['email_from_address']), 'string');
        Setting::set('mail_host', trim((string) $data['mail_host']), 'string');
        Setting::set('mail_port', (int) $data['mail_port'], 'integer');
        Setting::set('mail_secure', (string) $data['mail_secure'], 'string');
        Setting::set('mail_username', trim((string) $data['mail_username']), 'string');
        Setting::set('mail_password', (string) $data['mail_password'], 'string');
    }

    private function sanitizeAdminPrefix(string $prefix): string
    {
        $prefix = strtolower(trim($prefix, " \t\n\r\0\x0B/"));

        if ($prefix === '' || preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $prefix) !== 1) {
            return self::DEFAULT_ADMIN_PREFIX;
        }

        return $prefix;
    }

    /**
     * Persist the buyer's chosen currency as the application default.
     *
     * The bundled SQL dump and seeders may insert a baseline set of
     * currencies, so this method updates the existing record when one
     * matches and otherwise creates a minimal row from the curated
     * catalog. Every other currency is flipped out of the default flag
     * so only one stays active as the site currency.
     */
    private function seedDefaultCurrency(string $code): void
    {
        $code     = strtoupper(trim($code));
        $catalog  = self::currencyCatalog();
        $fallback = $catalog[self::DEFAULT_CURRENCY_CODE];
        $info     = $catalog[$code] ?? $fallback;

        if (! isset($catalog[$code])) {
            $code = self::DEFAULT_CURRENCY_CODE;
        }

        if (! Schema::hasTable('currencies')) {
            return;
        }

        Currency::query()->where('code', '!=', $code)->update(['default' => false]);

        Currency::query()->updateOrCreate(
            ['code' => $code],
            [
                'name'          => $info['name'],
                'symbol'        => $info['symbol'],
                'flag'          => $info['flag'],
                'type'          => CurrencyType::FIAT,
                'exchange_rate' => 1,
                'rate_live'     => false,
                'auto_wallet'   => true,
                'default'       => true,
                'status'        => true,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAdmin(array $data): Admin
    {
        Role::query()->firstOrCreate(
            ['guard_name' => 'admin', 'name' => 'super-admin'],
            ['description' => 'Full platform access with every permission.']
        );

        $admin = Admin::query()->firstOrNew([
            'email' => (string) $data['admin_email'],
        ]);

        $admin->forceFill([
            'name'              => (string) $data['admin_name'],
            'email'             => (string) $data['admin_email'],
            'email_verified_at' => now(),
            'password'          => (string) $data['admin_password'],
            'status'            => true,
        ])->save();

        $admin->syncRoles(['super-admin']);

        $this->removeLegacyDefaultAdmin($admin);

        // Confirmation breadcrumb so a post-install check of storage/logs can
        // verify the admin was persisted, to which email, and on which
        // database connection — invaluable when a buyer reports "admin login
        // not set" even though every other stage succeeded.
        Log::info('Installer created super admin', [
            'admin_id'   => $admin->getKey(),
            'email'      => $admin->email,
            'connection' => $admin->getConnectionName() ?? config('database.default'),
            'database'   => $admin->getConnection()->getDatabaseName(),
        ]);

        return $admin;
    }

    private function removeLegacyDefaultAdmin(Admin $currentAdmin): void
    {
        Admin::query()
            ->where('email', self::LEGACY_DEFAULT_ADMIN_EMAIL)
            ->whereKeyNot($currentAdmin->getKey())
            ->get()
            ->each(function (Admin $legacyAdmin): void {
                $legacyAdmin->syncRoles([]);
                $legacyAdmin->delete();
            });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeProjectLicense(array $licenseVerification, string $purchaseCode): void
    {
        if (! Schema::hasTable('project_licenses')) {
            throw new NotifyErrorException(__('License was verified, but the project license table is missing after database setup.'));
        }

        $license = $licenseVerification['license'] ?? [];

        if (! is_array($license) || blank($license['license_token'] ?? null)) {
            throw new NotifyErrorException(__('License was verified, but the update server did not return a license token.'));
        }

        ProjectLicense::query()->updateOrCreate(
            [
                'product_slug' => (string) config('project_updater.product_slug', 'digikash'),
                'domain'       => (string) ($license['domain'] ?? request()->getHost()),
            ],
            [
                'item_id'         => (string) ($license['item_id'] ?? config('project_updater.item_id', '58275561')),
                'purchase_code'   => $purchaseCode,
                'license_token'   => (string) $license['license_token'],
                'buyer_username'  => $license['buyer_username'] ?? null,
                'status'          => (string) ($license['status'] ?? 'active'),
                'support_until'   => ProjectLicense::normalizeSupportUntil($license['supported_until'] ?? $license['support_until'] ?? null),
                'activated_at'    => now(),
                'last_checked_at' => now(),
                'metadata'        => $license['metadata'] ?? $license,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function markInstalled(array $data, Admin $admin, array $licenseVerification): void
    {
        $lockPath = $this->lockPath();

        File::ensureDirectoryExists(dirname($lockPath));

        $license = $licenseVerification['license'] ?? [];

        $payload = json_encode([
            'installed_at'   => now()->toIso8601String(),
            'app_name'       => (string) $data['app_name'],
            'app_url'        => (string) $data['app_url'],
            'admin_email'    => $admin->email,
            'db_connection'  => (string) $data['db_connection'],
            'envato_license' => [
                'item_id'        => is_array($license) ? ($license['item_id'] ?? null) : null,
                'buyer_username' => is_array($license) ? ($license['buyer_username'] ?? null) : null,
                'domain'         => is_array($license) ? ($license['domain'] ?? null) : null,
                'status'         => is_array($license) ? ($license['status'] ?? null) : null,
                'verified_at'    => now()->toIso8601String(),
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $bytesWritten = File::put($lockPath, $payload);

        if ($bytesWritten === false || ! File::exists($lockPath)) {
            throw new NotifyErrorException(__('Could not write the installer lock file at :path. Make the storage/app folder writable by the web server user.', ['path' => $lockPath]));
        }
    }

    private function lockPath(): string
    {
        return (string) config('installer.lock_file', storage_path('app/installed'));
    }
}
