<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where a file on a P2P payment account lives - a payment QR, a bank letter,
 * whatever a payment method's file field asks for - and how it is handed out.
 *
 * These were stored on the `public` disk, which the storage symlink exposes to
 * the web, under the identity-document mime list: anyone holding the path -
 * and the path was printed on the account and on every order's payment
 * details - could fetch the file with no session at all. New files go to the
 * private `local` disk, beside the orders' chat payment proofs, and are
 * reached only through the owner's account or an order they are a party to.
 *
 * Files stored before stay where they are and stay readable through the same
 * controlled routes, until `php artisan p2p:privatise-payment-files` moves
 * them - the operator's call on their own data, as for KYC documents.
 */
final class P2PPaymentFileDisk
{
    public const DISK = 'local';

    public const PREFIX = 'p2p/payment-account-files';

    /** Where the public-disk files were written before. */
    public const LEGACY_PREFIX = 'p2p/payment-accounts';

    public static function store(UploadedFile $file): string
    {
        return (string) $file->store(self::PREFIX, self::DISK);
    }

    /**
     * True only for a path this feature wrote, on either disk. A value from a
     * payment account or an order snapshot is served only when it passes, so
     * no stored string can reach any other file.
     */
    public static function isStoredFile(mixed $stored): bool
    {
        if (! is_string($stored) || $stored === '' || str_contains($stored, '..') || str_contains($stored, '\\')) {
            return false;
        }

        $path = self::normalise($stored);

        return str_starts_with($path, self::PREFIX.'/') || str_starts_with($path, self::LEGACY_PREFIX.'/');
    }

    public static function isLegacy(string $stored): bool
    {
        return str_starts_with(self::normalise($stored), self::LEGACY_PREFIX.'/');
    }

    /**
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
     * The value of a file field among an account's rendered details, or null
     * when that key is not a file this feature stored.
     *
     * @param array<int, mixed> $details
     */
    public static function fromDetails(array $details, string $key): ?string
    {
        foreach ($details as $detail) {
            if (is_array($detail)
                && (string) ($detail['key'] ?? '')  === $key
                && (string) ($detail['type'] ?? '') === 'file'
                && self::isStoredFile($detail['value'] ?? null)) {
                return (string) $detail['value'];
            }
        }

        return null;
    }

    /**
     * Stream the file inline with the headers the chat's payment proofs use:
     * never sniffed, sandboxed, never cached, never indexed.
     */
    public static function response(?string $stored): StreamedResponse
    {
        abort_unless($stored !== null && self::isStoredFile($stored), 404);

        [$disk, $path] = self::locate($stored);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Disposition'     => 'inline; filename="'.basename($path).'"',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Referrer-Policy'         => 'no-referrer',
            'X-Robots-Tag'            => 'noindex, nofollow',
            'Cache-Control'           => 'private, no-store, max-age=0',
        ]);
    }

    public static function normalise(string $stored): string
    {
        $path = ltrim($stored, '/');

        return str_starts_with($path, 'storage/') ? substr($path, 8) : $path;
    }
}
