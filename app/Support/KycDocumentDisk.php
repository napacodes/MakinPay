<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a KYC identity document lives, and who is allowed to know.
 *
 * Every other upload in this application goes through FileManageTrait onto
 * the `public` disk, which is symlinked into the web root — correct for an
 * avatar and wrong for a passport. A KYC document was reachable at
 * /storage/files/YYYY/MM/DD/<name>.jpg by anyone holding the URL, with no
 * session, no permission and no owner check anywhere along the way.
 *
 * New documents go to a private disk outside the web root, and are reached
 * only through an authorised controller action. Three places touch these
 * files — the upload, the fingerprinter and the reviewer's viewer — and this
 * class is the one thing all three ask, so they cannot drift into disagreeing
 * about where a document is.
 *
 * Documents uploaded before this change stay where they are. Their paths stay
 * readable here so a reviewer still sees last month's submission, but they
 * remain exposed until `php artisan kyc:privatise-documents` moves them,
 * which is the operator's call to make on their own data.
 */
final class KycDocumentDisk
{
    /** The private disk, declared in config/filesystems.php. */
    public const DISK = 'kyc';

    /** Marks a path as living on the private disk rather than the public one. */
    public const PREFIX = 'kyc-documents';

    /**
     * Store an uploaded document privately and return the path to persist.
     */
    public static function store(UploadedFile $file): string
    {
        $name = Str::uuid()->toString().'.'.Str::lower($file->getClientOriginalExtension());

        $directory = self::PREFIX.'/'.date('Y/m/d');

        $file->storeAs($directory, $name, ['disk' => self::DISK]);

        return $directory.'/'.$name;
    }

    /**
     * True when this path is one of the pre-fix documents still on the public
     * disk. Kept readable on purpose: a reviewer opening a submission from
     * last month should still see what the applicant sent.
     */
    public static function isLegacy(string $stored): bool
    {
        return ! str_starts_with(self::normalise($stored), self::PREFIX.'/');
    }

    /**
     * The disk holding this document, and its path on that disk.
     *
     * @return array{0: Filesystem, 1: string}
     */
    public static function locate(string $stored): array
    {
        $path = self::normalise($stored);

        return self::isLegacy($stored)
            ? [Storage::disk('public'), $path]
            : [Storage::disk(self::DISK), $path];
    }

    /**
     * Delete a document this installation stored privately.
     *
     * For the one case where a stored document belongs to nothing: a
     * submission whose transaction rolled back after the upload. Only ever the
     * private disk - a legacy document on the public disk is the operator's to
     * move or keep (kyc:privatise-documents), never something to delete as a
     * side effect.
     */
    public static function forget(string $stored): void
    {
        if (self::isLegacy($stored)) {
            return;
        }

        Storage::disk(self::DISK)->delete(self::normalise($stored));
    }

    public static function exists(string $stored): bool
    {
        [$disk, $path] = self::locate($stored);

        return $disk->exists($path);
    }

    /**
     * Strip the leading slash and the `storage/` prefix a few older rows
     * carry, so callers can hand over whatever the column holds.
     */
    public static function normalise(string $stored): string
    {
        $path = ltrim($stored, '/');

        return str_starts_with($path, 'storage/') ? substr($path, 8) : $path;
    }
}
