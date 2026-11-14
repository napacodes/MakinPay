<?php

declare(strict_types=1);

use App\Exceptions\NotifyErrorException;
use App\Services\ProjectUpdaterService;

/*
|--------------------------------------------------------------------------
| Protected paths
|--------------------------------------------------------------------------
|
| An update package must never be able to overwrite the buyer's own
| configuration, secrets, dependencies, or storage. `safeRelativePath()` is the
| single enforcement point for every file the updater writes, so these tests
| drive it directly with attacker-shaped paths.
|
| The method is private, which is deliberate: it is not part of the public API.
| Reflection is used here rather than widening the visibility purely for tests.
|
*/

beforeEach(function (): void {
    $this->updater = app(ProjectUpdaterService::class);

    $this->safePath = new ReflectionMethod(ProjectUpdaterService::class, 'safeRelativePath');
    $this->safePath->setAccessible(true);
});

function assertPathRejected(ReflectionMethod $method, object $subject, string $path): void
{
    expect(fn () => $method->invoke($subject, $path))
        ->toThrow(NotifyErrorException::class);
}

function assertPathAllowed(ReflectionMethod $method, object $subject, string $path): string
{
    return (string) $method->invoke($subject, $path);
}

it('rejects a package that targets .env', function (): void {
    assertPathRejected($this->safePath, $this->updater, '.env');
})->group('updater-security');

it('rejects a package that targets .env.example', function (): void {
    assertPathRejected($this->safePath, $this->updater, '.env.example');
})->group('updater-security');

it('rejects a package that targets a file inside .env-like names', function (): void {
    assertPathRejected($this->safePath, $this->updater, '.env.testing');
})->group('updater-security');

it('rejects a package that targets the storage directory', function (): void {
    assertPathRejected($this->safePath, $this->updater, 'storage');
    assertPathRejected($this->safePath, $this->updater, 'storage/app/config.json');
    assertPathRejected($this->safePath, $this->updater, 'storage/logs/laravel.log');
})->group('updater-security');

it('rejects a package that targets vendor', function (): void {
    assertPathRejected($this->safePath, $this->updater, 'vendor');
    assertPathRejected($this->safePath, $this->updater, 'vendor/autoload.php');
})->group('updater-security');

it('rejects a package that targets node_modules', function (): void {
    assertPathRejected($this->safePath, $this->updater, 'node_modules');
    assertPathRejected($this->safePath, $this->updater, 'node_modules/lodash/index.js');
})->group('updater-security');

it('rejects a package that targets the git directory', function (): void {
    assertPathRejected($this->safePath, $this->updater, '.git');
    assertPathRejected($this->safePath, $this->updater, '.git/config');
})->group('updater-security');

it('rejects a package that targets editor and AI configuration', function (string $path): void {
    assertPathRejected($this->safePath, $this->updater, $path);
})->with([
    '.vscode/settings.json',
    '.idea/workspace.xml',
    '.cursor',
    '.claude/settings.json',
    'AGENTS.md',
    'CLAUDE.md',
])->group('updater-security');

it('rejects a path traversal attempt', function (string $path): void {
    // A package that tries to escape its extraction directory.
    assertPathRejected($this->safePath, $this->updater, $path);
})->with([
    '../.env',
    '../../etc/passwd',
    'app/../../.env',
    'resources/../../../vendor/autoload.php',
])->group('updater-security');

it('rejects an empty path', function (): void {
    assertPathRejected($this->safePath, $this->updater, '');
})->group('updater-security');

it('allows ordinary application files', function (string $path): void {
    expect(assertPathAllowed($this->safePath, $this->updater, $path))->not->toBe('');
})->with([
    'app/Http/Controllers/HomeController.php',
    'resources/views/welcome.blade.php',
    'public/index.php',
    'config/app.php',
    'routes/web.php',
])->group('updater-security');

it('normalises a leading slash', function (): void {
    // A package may store paths with a leading slash; that must not slip a
    // protected path past the guard.
    assertPathRejected($this->safePath, $this->updater, '/.env');
    assertPathRejected($this->safePath, $this->updater, '/vendor/autoload.php');
})->group('updater-security');

it('normalises windows style separators', function (): void {
    assertPathRejected($this->safePath, $this->updater, 'vendor\\autoload.php');
    assertPathRejected($this->safePath, $this->updater, 'storage\\app\\x.php');
})->group('updater-security');

it('does not treat a mere prefix as protected', function (): void {
    /*
     * `storage` is protected, but `storagex/` is not the storage directory.
     * A substring check would wrongly reject a legitimate file, and a sloppy
     * boundary here is exactly the kind of bug that lets `storage-evil/`
     * through in the other direction.
     */
    expect(assertPathAllowed($this->safePath, $this->updater, 'storageextra/file.txt'))->toBe('storageextra/file.txt');
})->group('updater-security');
