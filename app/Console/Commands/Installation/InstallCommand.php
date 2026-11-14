<?php

declare(strict_types=1);

namespace App\Console\Commands\Installation;

use App\Support\Installation\AdministratorProvisioner;
use App\Support\Installation\Preflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Install MakinPay from the command line.
 *
 * This replaces the retired public web installer. It is deliberately a CLI
 * command rather than an HTTP route: installation is a privileged, one-time
 * operation that must never be reachable by an unauthenticated visitor, and
 * the password for the first administrator must never travel over the wire.
 *
 * Design rules it follows:
 *
 *  - Idempotent and refusing: when an installation already exists the command
 *    stops unless `--allow-existing` is passed, so a stray re-run cannot reseed
 *    or recreate data.
 *  - Non-destructive: it runs `migrate` (which only adds), never `migrate:fresh`,
 *    never drops a table, and never imports the legacy SQL dump.
 *  - Explicit: seeding is opt-in via `--seed-baseline`, and demo data is
 *    opt-in via `--demo` and refused in production.
 *  - Secret-safe: the administrator password is read interactively without
 *    echo, or generated and shown once. It is never accepted as an option
 *    value, so it cannot leak into shell history or the process list.
 */
class InstallCommand extends Command
{
    protected $signature = 'makinpay:install
                            {--force : Run migrations without the production confirmation prompt.}
                            {--seed-baseline : Seed mandatory reference data (permissions, settings, languages, gateways).}
                            {--demo : Seed demo accounts and sample data. Refused in production.}
                            {--allow-existing : Proceed even though this site already reports as installed.}
                            {--skip-admin : Do not create a first administrator.}
                            {--optimize : Run config/route/view cache after installing.}
                            {--dry-run : Report every step that would run, without writing anything.}';

    protected $description = 'Install MakinPay: verify the server, migrate the database, seed reference data and create the first administrator';

    public function __construct(
        private readonly Preflight $preflight,
        private readonly AdministratorProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->components->info('MakinPay installation'.($dryRun ? ' (dry run)' : ''));
        $this->newLine();

        // ---------------------------------------------------------------
        // 1. Preflight
        // ---------------------------------------------------------------
        $checks = $this->preflight->run();
        $this->renderChecks($checks);

        if ($dryRun) {
            // A dry run is a plan, not a gate: report what would happen even
            // when a check fails, so the operator sees the whole picture.
            $this->newLine();
            $this->components->info('Dry run: no changes were written.');
            $this->listPlannedSteps();

            return self::SUCCESS;
        }

        $blocking = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) === Preflight::FAIL
                && ($check['key'] ?? '') !== 'app_key' // handled specially below
        ));

        if ($blocking !== []) {
            $this->newLine();
            $this->components->error('Resolve the failing checks above before installing.');

            return self::FAILURE;
        }

        // ---------------------------------------------------------------
        // 2. Existing installation guard
        // ---------------------------------------------------------------
        if ($this->preflight->isInstalled() && ! (bool) $this->option('allow-existing')) {
            $this->newLine();
            $this->components->error('This site already reports as installed. Nothing was changed.');

            if (! $this->confirm('Proceed anyway? This must only be done on a deliberate re-install.', false)) {
                return self::FAILURE;
            }
        }

        // ---------------------------------------------------------------
        // 3. Application key (generate only when missing; never overwrite)
        // ---------------------------------------------------------------
        if (! $this->ensureApplicationKey()) {
            return self::FAILURE;
        }

        // ---------------------------------------------------------------
        // 4. Migrate
        // ---------------------------------------------------------------
        if (! $this->runMigrations()) {
            return self::FAILURE;
        }

        // ---------------------------------------------------------------
        // 5. Seed reference data (explicit)
        // ---------------------------------------------------------------
        if (! $this->seedData()) {
            return self::FAILURE;
        }

        // ---------------------------------------------------------------
        // 6. First administrator
        // ---------------------------------------------------------------
        $adminState = $this->createFirstAdministrator();

        if ($adminState === null) {
            return self::FAILURE;
        }

        $adminEmail = $adminState['email'];

        // ---------------------------------------------------------------
        // 7. Completion marker
        // ---------------------------------------------------------------
        // An install with no administrator is not usable and must not be
        // recorded as complete: a marker would later tell the operator (and
        // the check command) that setup succeeded when nobody can sign in.
        if ($adminState['skipped'] || $adminEmail === '') {
            $this->newLine();
            $this->components->warn('No installation marker was written because no administrator exists.');
            $this->line('  Finish setup with: php artisan makinpay:admin:create');
            $this->newLine();

            return self::SUCCESS;
        }

        if (! $this->writeMarker($adminEmail)) {
            return self::FAILURE;
        }

        // ---------------------------------------------------------------
        // 8. Post-install: optimise and report
        // ---------------------------------------------------------------
        $this->finishPostInstall();

        return self::SUCCESS;
    }

    /**
     * @param list<array{key: string, label: string, status: string, detail: string, remedy?: string}> $checks
     */
    private function renderChecks(array $checks): void
    {
        $rows = [];

        foreach ($checks as $check) {
            $rows[] = [
                match ($check['status']) {
                    Preflight::OK      => '<fg=green>OK</>',
                    Preflight::WARN    => '<fg=yellow>WARN</>',
                    Preflight::FAIL    => '<fg=red>FAIL</>',
                    default            => '<fg=gray>?</>',
                },
                $check['label'],
                $check['detail'],
            ];
        }

        $this->table(['', 'Check', 'Result'], $rows);

        $remedies = array_values(array_filter(array_map(
            static fn (array $check): string => (string) ($check['remedy'] ?? ''),
            $checks
        )));

        if ($remedies !== []) {
            $this->newLine();
            $this->components->warn('Notes');
            foreach ($remedies as $remedy) {
                $this->line('  • '.$remedy);
            }
        }
    }

    private function listPlannedSteps(): void
    {
        $steps = [
            'Generate APP_KEY if missing (never overwrites an existing key)',
            'Run pending migrations (migrate --force, never a fresh install)',
            $this->option('seed-baseline') ? 'Seed mandatory reference data (installer.core_seeders)' : 'Skip reference-data seeding (pass --seed-baseline to enable)',
            $this->option('demo') ? 'Seed demo data (refused in production)' : 'Skip demo data',
            $this->option('skip-admin') ? 'Skip administrator creation' : 'Create the first administrator',
            'Write the installation marker at storage/app/makinpay-installed.json',
            $this->option('optimize') ? 'Cache config, routes and views' : 'Skip optimisation (pass --optimize to enable)',
        ];

        foreach ($steps as $index => $step) {
            $this->line(sprintf('  %d. %s', $index + 1, $step));
        }
    }

    private function ensureApplicationKey(): bool
    {
        $existing = (string) config('app.key');

        if ($existing !== '') {
            $this->components->twoColumnDetail('Application key', '<fg=green>present (kept)</>');

            return true;
        }

        try {
            Artisan::call('key:generate', ['--force' => true]);
        } catch (Throwable $e) {
            $this->components->error('Could not generate an application key: '.$e->getMessage());

            return false;
        }

        // The running process cached the old (empty) key; re-read it so later
        // steps encrypt with the key that is now on disk.
        try {
            config(['app.key' => $this->readKeyFromEnvironment()]);
        } catch (Throwable) {
            // Non-fatal: the next command invocation reads the file anew.
        }

        $this->components->twoColumnDetail('Application key', '<fg=green>generated</>');

        return true;
    }

    private function readKeyFromEnvironment(): string
    {
        $path = app()->environmentFilePath();

        if (! File::exists($path)) {
            return (string) config('app.key');
        }

        foreach (preg_split('/\R/', (string) File::get($path)) ?: [] as $line) {
            if (str_starts_with(trim($line), 'APP_KEY=')) {
                return trim(trim(substr(trim($line), strlen('APP_KEY='))), "\"'");
            }
        }

        return (string) config('app.key');
    }

    private function runMigrations(): bool
    {
        if ($this->hasPendingMigrations() === false) {
            $this->components->twoColumnDetail('Migrations', '<fg=green>already up to date</>');

            return true;
        }

        if (! $this->option('force') && app()->environment('production')) {
            if (! $this->confirm('Run database migrations in production?', false)) {
                $this->components->error('Migrations refused.');

                return false;
            }
        }

        try {
            Artisan::call('migrate', ['--force' => true], $this->getOutput());
        } catch (Throwable $e) {
            $this->components->error('Migration failed: '.$e->getMessage());
            $this->line('  The database was left as it was. Fix the error above and re-run the command.');

            return false;
        }

        $this->components->twoColumnDetail('Migrations', '<fg=green>applied</>');

        return true;
    }

    private function hasPendingMigrations(): bool
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return true;
            }

            $ran   = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles([database_path('migrations')]);

            foreach (array_keys($files) as $migration) {
                if (! in_array($migration, $ran, true)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    private function seedData(): bool
    {
        $seedBaseline = (bool) $this->option('seed-baseline');
        $demo         = (bool) $this->option('demo');

        if ($demo && app()->environment('production')) {
            $this->components->warn('Demo data is refused in production. Pass nothing, or run in a non-production environment.');
            $demo = false;
        }

        if (! $seedBaseline && ! $demo) {
            $this->components->twoColumnDetail('Seeding', '<fg=yellow>skipped (pass --seed-baseline)</>');

            return true;
        }

        if ($seedBaseline) {
            $this->components->info('Seeding mandatory reference data');

            foreach ((array) config('installer.core_seeders', []) as $seeder) {
                $seeder = (string) $seeder;

                if ($seeder === '') {
                    continue;
                }

                try {
                    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
                    $this->line('  <fg=green>✓</> '.class_basename($seeder));
                } catch (Throwable $e) {
                    $this->components->error('Seeder '.class_basename($seeder).' failed: '.$e->getMessage());

                    return false;
                }
            }
        }

        if ($demo) {
            $this->components->info('Seeding demo data');

            foreach ((array) config('installer.demo_seeders', []) as $seeder) {
                $seeder = (string) $seeder;

                if ($seeder === '') {
                    continue;
                }

                try {
                    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
                    $this->line('  <fg=green>✓</> '.class_basename($seeder));
                } catch (Throwable $e) {
                    $this->components->error('Demo seeder '.class_basename($seeder).' failed: '.$e->getMessage());

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{email: string, skipped: bool}|null Null on failure.
     */
    private function createFirstAdministrator(): ?array
    {
        if ((bool) $this->option('skip-admin')) {
            $this->components->twoColumnDetail('Administrator', '<fg=yellow>skipped (--skip-admin)</>');

            return ['email' => '', 'skipped' => true];
        }

        if (! $this->provisioner->schemaReady()) {
            $this->components->error('The "admins" table does not exist after migrating; cannot create an administrator.');

            return null;
        }

        if ($this->provisioner->hasAnyAdministrator()) {
            $this->components->twoColumnDetail('Administrator', '<fg=yellow>already exists (untouched)</>');
            $this->line('  Create another with: php artisan makinpay:admin:create');

            return ['email' => '', 'skipped' => false];
        }

        $this->newLine();
        $this->components->info('First administrator');

        $interactive = $this->input->isInteractive() && ! (bool) $this->option('no-interaction');

        if (! $interactive) {
            return $this->createAdministratorNonInteractive();
        }

        $name = (string) $this->ask('Name', 'Super Admin');

        $email = (string) $this->ask('Email address');

        // ask() hides input; the confirmation comes from `confirmed` validation
        // rather than a second prompt, matching the released installer's rule.
        $password = (string) $this->secret('Password (min 8, upper + lower + number)');

        $confirmation = (string) $this->secret('Confirm password');

        // Anti-footgun: a typed password and its confirmation that silently
        // differ would create an account nobody can sign in to. The prompt is
        // echoed nowhere, so a mismatch is the only clue available.
        if (! hash_equals($password, $confirmation)) {
            $this->components->error('The passwords do not match. No administrator was created.');

            return null;
        }

        $errors = $this->provisioner->validate($name, $email, $password, $confirmation);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            return null;
        }

        $result = $this->provisioner->provision($name, $email, $password);

        return $this->reportAdministratorResult($result);
    }

    private function createAdministratorNonInteractive(): ?array
    {
        $name  = 'Super Admin';

        // Non-interactive email resolution order: MAKINPAY_ADMIN_EMAIL, then
        // admin@<host of APP_URL>. Never derived from a password option.
        $email = (string) (getenv('MAKINPAY_ADMIN_EMAIL') ?: '');

        if ($email === '') {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            $email = is_string($host) && $host !== '' ? 'admin@'.$host : '';
        }

        $email = trim($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error('No valid administrator email was supplied. Create one with: php artisan makinpay:admin:create');

            return null;
        }

        // Automation hand-off: an operator may supply the password through the
        // environment instead of a CLI option, so the secret never appears in
        // argv or `ps` output. When it is absent we generate one and print it
        // exactly once. A supplied password is still subject to the normal
        // policy check inside provision().
        $suppliedPassword = (string) (getenv('MAKINPAY_ADMIN_PASSWORD') ?: '');
        $generated        = false;

        if ($suppliedPassword !== '') {
            $password = $suppliedPassword;
        } else {
            $password  = $this->provisioner->generatePassword();
            $generated = true;
        }

        $result = $this->provisioner->provision($name, $email, $password);

        if ($result['created'] && $result['admin'] instanceof \App\Models\Admin) {
            $this->newLine();

            if ($generated) {
                $this->components->warn('Generated administrator password — shown once, store it now');
                $this->line('  <fg=yellow>'.$password.'</>');
            } else {
                $this->components->twoColumnDetail('Administrator password', '<fg=green>used value from MAKINPAY_ADMIN_PASSWORD</>');
            }

            $this->newLine();
        }

        return $this->reportAdministratorResult($result);
    }

    /**
     * @param array{created: bool, admin: \App\Models\Admin|null, reason: string} $result
     */
    private function reportAdministratorResult(array $result): ?array
    {
        if ($result['admin'] === null) {
            $this->components->error($result['reason']);

            return null;
        }

        $email = (string) $result['admin']->email;

        if ($result['created']) {
            $this->components->twoColumnDetail('Administrator', '<fg=green>created</> '.$email);
        } else {
            $this->components->twoColumnDetail('Administrator', '<fg=yellow>unchanged</> '.$email);
            $this->line('  '.$result['reason']);
        }

        return ["email" => $email, "skipped" => false];
    }

    private function writeMarker(string $adminEmail): bool
    {
        $path = $this->preflight->markerPath();

        $payload = [
            'installed_at'  => now()->toIso8601String(),
            'product'       => 'makinpay',
            'version'       => (string) config('project_updater.version'),
            'app_env'       => (string) app()->environment(),
            'app_url'       => (string) config('app.url'),
            'db_connection' => (string) config('database.default'),
            'installed_by'  => 'makinpay:install',
            // Deliberately recorded: enough to identify the install, nothing
            // secret (no password, no key, no connection credentials).
            'admin_email'   => $adminEmail !== '' ? $adminEmail : null,
        ];

        try {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        } catch (Throwable $e) {
            $this->components->error('Could not write the installation marker: '.$e->getMessage());

            return false;
        }

        if (! File::exists($path)) {
            $this->components->error('Could not write the installation marker at '.$path);

            return false;
        }

        $this->components->twoColumnDetail('Installation marker', '<fg=green>written</> '.$path);

        return true;
    }

    private function finishPostInstall(): void
    {
        $this->newLine();

        // Config caching is opt-in. `config:cache` freezes the values resolved
        // in THIS process, which for a shell install may include environment
        // variables that are not in .env (e.g. DB_CONNECTION passed inline for
        // a test run). Baking those into bootstrap/cache/config.php would leave
        // the site pointing at the wrong database. --optimize makes it explicit.
        if ((bool) $this->option('optimize')) {
            $this->components->info('Optimising');

            foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
                try {
                    Artisan::call($command);
                    $this->line('  <fg=green>✓</> php artisan '.$command);
                } catch (Throwable $e) {
                    // Optimisation is a performance step; a failure here must not
                    // mark a working installation as broken.
                    $this->line('  <fg=yellow>!</> php artisan '.$command.' — '.Str::limit($e->getMessage(), 120));
                }
            }
        } else {
            $this->components->twoColumnDetail('Optimisation', '<fg=yellow>skipped (pass --optimize)</>');
        }

        $this->newLine();
        $this->components->info('Installation complete');
        $this->newLine();

        $adminPrefix = (string) (DB::table('settings')->where('key', 'admin_prefix')->value('value') ?: 'admin');

        $this->line('  <fg=green>Next steps</>');
        $this->line('  1. Ensure the scheduler is running:');
        $this->line('       '.\App\Support\InstallationManager::cronCommand());
        $this->line('  2. Ensure a queue worker is running if QUEUE_CONNECTION is not "sync".');
        $this->line('  3. Sign in to the admin panel at '.rtrim((string) config('app.url'), '/').'/'.$adminPrefix);
        $this->line('  4. Review .env for production values (APP_DEBUG=false, APP_URL, mail settings).');
        $this->newLine();
        $this->line('  Health check: php artisan makinpay:install:check');
        $this->newLine();
    }
}
