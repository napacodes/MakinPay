<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything a dompdf render needs to know about the site's branding.
 *
 * Lifted verbatim out of `Frontend\TransactionController@downloadPdf`, which
 * was the only copy until the admin gained a receipt route of its own. The
 * logic is worth keeping in one place: it resolves an operator-supplied
 * setting into a filesystem path, and a second divergent copy of that is how
 * one side of the product ends up rendering a receipt with no logo — or
 * reading a path the other side would have rejected.
 */
class PdfBranding
{
    /**
     * The site logo in a form dompdf can actually draw.
     *
     * A remote URL is handed back untouched (with remote fetching switched on
     * for it); a local file is inlined as a data URI, because dompdf resolves
     * relative paths against its own chroot rather than the document root and
     * silently draws nothing when it misses. Returns an empty string when no
     * logo is configured or none of the candidates exist — every caller must
     * treat the wordmark as the fallback.
     */
    public static function siteLogo(): string
    {
        $setting = (string) setting('logo', '');

        if ($setting === '') {
            return '';
        }

        try {
            if (Str::startsWith($setting, ['http://', 'https://'])) {
                config(['dompdf.options.isRemoteEnabled' => true]);

                return $setting;
            }

            foreach (self::candidatePaths($setting) as $path) {
                if (! is_file($path)) {
                    continue;
                }

                $mime = function_exists('mime_content_type')
                    ? (mime_content_type($path) ?: 'image/png')
                    : 'image/png';

                return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        } catch (Throwable $e) {
            // A missing logo must never cost the customer their receipt.
            Log::warning('PDF logo resolution failed: '.$e->getMessage());
        }

        return '';
    }

    /**
     * Let dompdf reach the images the document references, and confine it to
     * the document root while it does.
     */
    public static function configureRenderer(): void
    {
        config([
            'dompdf.options.isRemoteEnabled' => true,
            'dompdf.options.chroot'          => public_path(),
        ]);
    }

    /**
     * The three shapes the `logo` setting has taken across installations: a
     * path relative to `public/`, one relative to the public storage link, and
     * one already stored on the public disk.
     *
     * @return list<string>
     */
    private static function candidatePaths(string $setting): array
    {
        $candidates = [public_path(ltrim($setting, '/'))];

        $publicRelative = Str::startsWith($setting, ['storage/', '/storage/'])
            ? ltrim($setting, '/')
            : 'storage/'.ltrim($setting, '/');

        $candidates[] = public_path($publicRelative);

        if (Storage::disk('public')->exists($setting)) {
            $candidates[] = Storage::disk('public')->path($setting);
        }

        return $candidates;
    }
}
