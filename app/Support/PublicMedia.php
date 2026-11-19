<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Resolve stored media references to paths that are actually reachable in the
 * browser.
 *
 * Content is stored with disk-relative paths ("images/2025/04/10/hero.png"),
 * because that is what the uploader writes and what the DigiKash export
 * contains. Those files live on the `public` disk, which is exposed at
 * `storage/`, not at the web root. Handing the raw value to asset() therefore
 * produces a URL that 404s.
 *
 * This mirrors the resolution the codebase already uses for brand assets
 * (Setting::normalizeStoredAssetPath) and for <x-img> (Img::imageUrl): anything
 * that is not already served from public/ but does exist on the public disk
 * gets the `storage/` prefix. Values that resolve nowhere are reported once and
 * swapped for the shared placeholder so a missing file degrades to a tidy image
 * instead of a broken one, and is still visible in the logs.
 */
final class PublicMedia
{
    /**
     * Extensions we treat as media references. Used only to avoid a filesystem
     * lookup for every heading, slug and URL that passes through normalize().
     */
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'];

    /**
     * Placeholder used when a reference resolves nowhere. Relative to public/.
     */
    public const PLACEHOLDER = 'general/static/default/placeholder.png';

    /**
     * Per-request cache of path => resolved path.
     *
     * @var array<string, string>
     */
    private static array $resolved = [];

    /**
     * Paths already logged as missing, so a repeated image logs once.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

    /**
     * Recursively rewrite media references inside a decoded JSON structure.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? self::normalizePath($value) : $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    /**
     * Rewrite a single stored media reference so asset() can serve it.
     *
     * Values that are not media references are returned untouched.
     */
    public static function normalizePath(string $value): string
    {
        $path = ltrim(trim($value), '/');

        if ($path === '' || str_starts_with($path, 'storage/') || filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        if (! self::looksLikeMedia($path)) {
            return $value;
        }

        return self::resolve($path);
    }

    /**
     * True when the reference currently resolves to a servable file.
     */
    public static function exists(string $value): bool
    {
        $path = ltrim(trim($value), '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return is_file(public_path($path)) || Storage::disk('public')->exists($path);
    }

    /**
     * Drop the per-request caches. Used by tests that change the disk.
     */
    public static function flush(): void
    {
        self::$resolved = [];
        self::$reported = [];
    }

    private static function resolve(string $path): string
    {
        if (array_key_exists($path, self::$resolved)) {
            return self::$resolved[$path];
        }

        if (is_file(public_path($path))) {
            return self::$resolved[$path] = $path;
        }

        if (Storage::disk('public')->exists($path)) {
            return self::$resolved[$path] = 'storage/'.$path;
        }

        if (! isset(self::$reported[$path])) {
            self::$reported[$path] = true;

            Log::warning('Media reference does not resolve to a file on the public disk.', [
                'path'   => $path,
                'public' => public_path($path),
                'disk'   => 'storage/app/public/'.$path,
            ]);
        }

        return self::$resolved[$path] = self::PLACEHOLDER;
    }

    private static function looksLikeMedia(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::EXTENSIONS, true);
    }
}
