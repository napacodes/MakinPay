<?php

declare(strict_types=1);

namespace App\Services\Addons;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * An add-on package (.zip) checked before a single file of it lands on disk.
 *
 * Installing a package runs its PHP on this server, so the signature is not
 * optional: the archive comment carries an RSA-SHA256 signature, made with
 * the Coevs release key, over a digest of every entry's name and contents.
 * Any edited, added or removed file breaks it. The matching public key is the
 * one core updates are verified with (project_updater.public_key).
 *
 * Entries are checked before extraction: one top-level folder named after
 * the slug, no absolute paths, no `..`, no backslashes, no symlinks, and a
 * ceiling on the unpacked size.
 */
final class AddonPackage
{
    public const SIGNATURE_PREFIX = 'digikash-addon-signature:v1:';

    public const MAX_UNPACKED_BYTES = 100 * 1024 * 1024;

    private const SYMLINK_MODE = 0120000;

    private const FILE_TYPE_MASK = 0170000;

    public function __construct(private readonly ?string $publicKey = null) {}

    /**
     * Verifies the package and extracts it into a fresh staging directory.
     * `path` is the add-on folder inside `staging`.
     *
     * @return array{manifest: AddonManifest, path: string, staging: string}
     *
     * @throws AddonOperationFailed
     */
    public function stage(string $zipPath, string $stagingRoot): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw AddonOperationFailed::because('The PHP zip extension is required to install add-ons.');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw AddonOperationFailed::because('The package could not be opened as a zip file.');
        }

        $staging = rtrim(str_replace('\\', '/', $stagingRoot), '/').'/'.Str::uuid();

        try {
            $slug = $this->assertSafeEntries($zip);
            $this->assertSigned($zip);

            if ($zip->locateName($slug.'/'.AddonManifest::FILE) === false) {
                throw AddonOperationFailed::because("The package has no addon.json inside its {$slug} folder.");
            }

            File::ensureDirectoryExists($staging);

            if (! $zip->extractTo($staging)) {
                throw AddonOperationFailed::because('The package could not be extracted.');
            }
        } catch (AddonOperationFailed $failed) {
            File::deleteDirectory($staging);

            throw $failed;
        } finally {
            $zip->close();
        }

        try {
            $manifest = AddonManifest::fromFile($staging.'/'.$slug.'/'.AddonManifest::FILE);
        } catch (InvalidAddonManifest $invalid) {
            File::deleteDirectory($staging);

            throw new AddonOperationFailed($invalid->errors);
        }

        if ($manifest->slug !== $slug) {
            File::deleteDirectory($staging);

            throw AddonOperationFailed::because("The package folder {$slug} does not match the add-on slug {$manifest->slug}.");
        }

        return ['manifest' => $manifest, 'path' => $staging.'/'.$slug, 'staging' => $staging];
    }

    /**
     * The value the release key signs: every file entry's name and SHA-256,
     * sorted, so the digest does not depend on entry order.
     */
    public static function digest(ZipArchive $zip): string
    {
        $lines = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '/')) {
                continue;
            }

            $lines[] = $name."\n".hash('sha256', (string) $zip->getFromIndex($index));
        }

        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    private function assertSigned(ZipArchive $zip): void
    {
        $publicKey = $this->publicKey ?? (string) config('project_updater.public_key');

        if (trim($publicKey) === '') {
            throw AddonOperationFailed::because('No release signing key is configured, so the package cannot be verified.');
        }

        $comment = (string) $zip->getArchiveComment();

        if (! str_starts_with($comment, self::SIGNATURE_PREFIX)) {
            throw AddonOperationFailed::because('The package is not signed. Only packages published by Coevs can be installed.');
        }

        $signature = base64_decode(substr($comment, strlen(self::SIGNATURE_PREFIX)), true);

        if ($signature === false || $signature === ''
                                 || openssl_verify(self::digest($zip), $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw AddonOperationFailed::because('The package signature does not match. It may have been modified after it was published.');
        }
    }

    /**
     * @return string the single top-level folder, which must be the slug
     */
    private function assertSafeEntries(ZipArchive $zip): string
    {
        if ($zip->numFiles === 0) {
            throw AddonOperationFailed::because('The package is empty.');
        }

        $root     = null;
        $unpacked = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (! $this->isSafeEntryName($name)) {
                throw AddonOperationFailed::because("The package contains an unsafe path: {$name}");
            }

            if ($zip->getExternalAttributesIndex($index, $system, $attributes)
                && $system === ZipArchive::OPSYS_UNIX
                && ((($attributes >> 16) & self::FILE_TYPE_MASK) === self::SYMLINK_MODE)) {
                throw AddonOperationFailed::because("The package contains a symbolic link: {$name}");
            }

            $segments = explode('/', $name);

            if (count($segments) < 2) {
                throw AddonOperationFailed::because('Everything in the package must sit inside one folder named after the add-on.');
            }

            $root ??= $segments[0];

            if ($segments[0] !== $root) {
                throw AddonOperationFailed::because('The package must contain exactly one top-level folder.');
            }

            $unpacked += (int) ($zip->statIndex($index)['size'] ?? 0);

            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                throw AddonOperationFailed::because('The package unpacks to more than 100 MB.');
            }
        }

        if ($root === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $root) !== 1) {
            throw AddonOperationFailed::because('The package folder must be named after the add-on slug.');
        }

        return $root;
    }

    private function isSafeEntryName(string $name): bool
    {
        return $name !== ''
            && ! str_contains($name, '\\')
            && ! str_contains($name, "\0")
            && ! str_starts_with($name, '/')
            && preg_match('/^[A-Za-z]:/', $name) !== 1
            && ! in_array('..', explode('/', $name), true);
    }
}
