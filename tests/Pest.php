<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
|
| Registers the Laravel-aware expectations helpers and binds the test case.
|
*/

uses(Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Isolated storage directory
|--------------------------------------------------------------------------
|
| Tests write installation markers, update packages, extraction directories
| and recovery backups. They must not do it in the repository's real
| storage/app, because that directory carries the developer's own state (and,
| on an installed copy, the real installation marker).
|
| A per-process temporary directory is created here and removed when PHP
| exits. `Application::useStoragePath()` is what makes this effective:
| storage_path() is read from the container, so overriding the container's
| value redirects every call site, including
| Preflight::markerPath() and config('installer.writable_paths').
|
*/

$makinpayTestRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    .DIRECTORY_SEPARATOR
    .'makinpay-tests-'.getmypid().'-'.substr(md5((string) hrtime(true)), 0, 8);

foreach ([
    '',
    '/app',
    '/app/public',
    '/framework',
    '/framework/cache',
    '/framework/cache/data',
    '/framework/sessions',
    '/framework/views',
    '/logs',
] as $subdirectory) {
    $path = $makinpayTestRoot.$subdirectory;

    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

/*
 * Publish the paths as environment values so config/installer.php (which is
 * built from storage_path() at config-load time) resolves into the temp tree.
 */
putenv('MAKINPAY_TEST_STORAGE_ROOT='.$makinpayTestRoot);
$_ENV['MAKINPAY_TEST_STORAGE_ROOT']    = $makinpayTestRoot;
$_SERVER['MAKINPAY_TEST_STORAGE_ROOT'] = $makinpayTestRoot;

/*
 * The installer reads the administrator email and password with getenv(),
 * deliberately: a password must never travel as a command-line argument where
 * `ps` could read it. Tests therefore cannot pass it in an options array, and
 * exporting it here (rather than around an Artisan::call) keeps Pest's usual
 * `$this->artisan()` mocking and memory handling intact.
 *
 * Individual tests still override these when they need a different value.
 */
putenv('MAKINPAY_ADMIN_EMAIL=owner@makinpay.test');
putenv('MAKINPAY_ADMIN_PASSWORD=Str0ngPass!23');

/*
 * RSA key generation needs an OpenSSL configuration file. Some Windows PHP
 * builds ship one under extras/ssl without pointing openssl.cnf at it, which
 * makes openssl_pkey_new() fail with "configuration file routines::no such
 * file". The updater security tests generate a real keypair, so resolve a
 * usable config here rather than skipping those tests.
 */
if (getenv('OPENSSL_CONF') === false || ! is_file((string) getenv('OPENSSL_CONF'))) {
    $candidates = [
        PHP_BINARY ? dirname(PHP_BINARY).'/extras/ssl/openssl.cnf' : '',
        PHP_BINARY ? dirname(PHP_BINARY).'/extras/openssl/openssl.cnf' : '',
        '/etc/ssl/openssl.cnf',
        '/usr/local/ssl/openssl.cnf',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            putenv('OPENSSL_CONF='.$candidate);
            $_ENV['OPENSSL_CONF']    = $candidate;
            $_SERVER['OPENSSL_CONF'] = $candidate;
            break;
        }
    }
}

/*
 * Remove the tree when the process ends. Pest may fork workers in --parallel
 * mode, hence the pid-specific directory name: no two workers can collide.
 */
register_shutdown_function(static function () use ($makinpayTestRoot): void {
    if (! is_dir($makinpayTestRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($makinpayTestRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($makinpayTestRoot);
});
