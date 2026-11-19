<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Storage root used by this process, created by tests/Pest.php.
     */
    protected static string $storageRoot = '';

    protected function setUp(): void
    {
        static::assertTestingDatabaseIsDisposable();

        parent::setUp();

        static::redirectStorage();

        /*
         * Tests seed aggressively but must not inherit the developer's cached
         * config, which may point at the real MySQL database.
         */
        $this->app->make(Kernel::class)->call('config:clear');
    }

    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Point storage_path() at the temporary tree built in tests/Pest.php.
     *
     * storage_path() is resolved from the container, so this covers every call
     * site: the installation marker, config/installer.php's writable paths,
     * the updater's package/extract/backup directories.
     */
    protected static function redirectStorage(): void
    {
        $root = (string) (getenv('MAKINPAY_TEST_STORAGE_ROOT') ?: '');

        if ($root === '' || ! is_dir($root)) {
            throw new \RuntimeException(
                'MAKINPAY_TEST_STORAGE_ROOT is not set. Tests must run through '
                .'tests/Pest.php so they never write to the real storage/app.'
            );
        }

        static::$storageRoot = $root;

        /*
         * Capture the real storage root before redirecting it: the disk roots
         * in config/filesystems.php were evaluated from it while the container
         * booted, so they still point at the repository's storage/app.
         */
        $realStorage = rtrim(str_replace('\\', '/', storage_path()), '/');

        app()->useStoragePath($root);

        /*
         * Redirect every local disk that lived under the real storage tree.
         * Without this, storage_path() is redirected but Storage::disk('public')
         * still writes to storage/app/public — which is tracked in git, so a
         * test that seeds media overwrites the repository's real images.
         */
        $disks = (array) config('filesystems.disks', []);

        foreach ($disks as $name => $disk) {
            $diskRoot = is_array($disk) ? ($disk['root'] ?? null) : null;

            if (! is_string($diskRoot) || $diskRoot === '') {
                continue;
            }

            $normalized = rtrim(str_replace('\\', '/', $diskRoot), '/');

            if (! str_starts_with($normalized, $realStorage)) {
                continue;
            }

            $relative = ltrim(substr($normalized, strlen($realStorage)), '/');

            $disks[$name]['root'] = $root.($relative === '' ? '' : '/'.$relative);

            // Drop any instance already resolved against the old root.
            Storage::forgetDisk($name);
        }

        config(['filesystems.disks' => $disks]);

        /*
         * config/installer.php was built from the real storage_path() while
         * the container booted, before useStoragePath() ran. Re-point the
         * writable-path checks at the temporary tree so the installer tests
         * assert on paths that actually belong to the test.
         */
        config(['installer.writable_paths' => [
            'Environment file'  => base_path('.env'),
            'Storage'           => $root,
            'Storage app'       => $root.'/app',
            'Cache directory'   => $root.'/framework/cache',
            'Session directory' => $root.'/framework/sessions',
            'View cache'        => $root.'/framework/views',
            'Application logs'  => $root.'/logs',
            'Bootstrap cache'   => base_path('bootstrap/cache'),
            'Public folder'     => base_path('public'),
        ]]);

        config(['project_updater.storage_disk' => 'local']);
    }

    protected function tearDown(): void
    {
        /*
         * Remove the installation markers a test may have written. The parent
         * temporary directory is deleted at process exit, but a single test
         * must not leak a marker into the next one.
         */
        if (static::$storageRoot !== '') {
            foreach (['/app/makinpay-installed.json', '/app/installed'] as $relative) {
                $path = static::$storageRoot.$relative;

                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Refuse to run against anything that is not SQLite unless the operator
     * has deliberately named a disposable MySQL test schema.
     *
     * This is the most important guard in the suite: an accidental run against
     * Laragon's MySQL would apply migrations to a real schema.
     */
    private static function assertTestingDatabaseIsDisposable(): void
    {
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite'));

        if (in_array($connection, ['sqlite'], true)) {
            return;
        }

        $explicit = (string) (getenv('MAKINPAY_ALLOW_MYSQL_TESTS') ?: '');

        if ($connection === 'mysql' && $explicit !== '') {
            $database = (string) (getenv('DB_DATABASE') ?: '');

            /*
             * The name must be stated and must be the opted-in one. An unset
             * DB_DATABASE is NOT acceptable: config/database.php falls back to
             * the default `laravel` schema, so "unset" means "some other
             * database than the one authorised", not "harmless".
             */
            if ($database !== '' && $database === $explicit) {
                return;
            }
        }

        throw new \RuntimeException(sprintf(
            'Refusing to run MakinPay tests against the "%s" database. '
            .'The suite requires SQLite, or MySQL with MAKINPAY_ALLOW_MYSQL_TESTS '
            .'set to the disposable test schema name.',
            $connection
        ));
    }
}
