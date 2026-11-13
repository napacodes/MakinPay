<?php

namespace App\Services\Media;

/**
 * Administrator-controlled upload limits and file-type allow-lists.
 *
 * The size cap and every allowed extension used to be hardcoded — the KYC
 * document guard in FileManageTrait carried its own list that did not even
 * include `jpeg`, so a photo straight off a phone passed form validation and
 * was then rejected by an abort(406) the customer read as "Failed to submit
 * KYC". These now live in Settings -> Media & Uploads.
 *
 * Every reader falls back to the previous hardcoded value, so an installation
 * whose settings rows have not been seeded yet behaves exactly as before.
 */
final class MediaSettings
{
    public const DEFAULT_MAX_UPLOAD_SIZE_MB = 5;

    /** @var list<string> */
    public const DEFAULT_IMAGE_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'svg', 'ico'];

    /** @var list<string> */
    public const DEFAULT_DOCUMENT_EXTENSIONS = [
        'zip', 'rar', 'pdf', 'doc', 'docx', 'txt', 'csv', 'xml', 'json',
        'ppt', 'pptx', 'ods', 'odt', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
    ];

    /** @var list<string> */
    public const DEFAULT_IDENTITY_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /**
     * What a customer may upload as proof of a payment. Fixed, not an
     * administrator's list: never SVG, whose script runs when opened.
     *
     * @var list<string>
     */
    public const PAYMENT_PROOF_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    /**
     * Raster images a customer may upload as a picture (avatar, logo, an
     * account document). Fixed, for the same reason as the proofs.
     *
     * @var list<string>
     */
    public const CUSTOMER_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Types a browser runs as a document when the file is opened straight
     * from /storage: an SVG's script, an XHTML or HTML page. Fine in the
     * administrator's own uploads, never in a customer's - a customer who
     * sends support the direct link would run script on this origin.
     *
     * @var list<string>
     */
    public const SCRIPTABLE_EXTENSIONS = ['svg', 'svgz', 'xml', 'xhtml', 'xht', 'html', 'htm', 'shtml'];

    /**
     * An administrator's list with the scriptable types taken out, for an
     * upload a customer makes.
     *
     * @param  list<string> $extensions
     * @return list<string>
     */
    public static function customerSafe(array $extensions): array
    {
        return array_values(array_diff($extensions, self::SCRIPTABLE_EXTENSIONS));
    }

    /**
     * The cap the administrator asked for, in megabytes.
     */
    public static function maxUploadSizeMb(): int
    {
        $configured = (int) setting('max_upload_size', self::DEFAULT_MAX_UPLOAD_SIZE_MB);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_UPLOAD_SIZE_MB;
    }

    /**
     * The cap actually enforced: what the administrator asked for, capped by
     * what PHP itself will accept. Beyond the PHP ceiling the web server
     * discards the request body before Laravel runs, and the customer sees a
     * confusing "field is required" instead of a size message.
     */
    public static function effectiveMaxUploadSizeMb(): int
    {
        $server = self::serverMaxUploadSizeMb();

        return $server === null
            ? self::maxUploadSizeMb()
            : max(1, min(self::maxUploadSizeMb(), $server));
    }

    public static function effectiveMaxUploadSizeKb(): int
    {
        return self::effectiveMaxUploadSizeMb() * 1024;
    }

    /**
     * The ceiling PHP imposes, or null when neither directive is readable or
     * both are unlimited.
     */
    public static function serverMaxUploadSizeMb(): ?int
    {
        $limits = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes($directive);

            if ($bytes !== null && $bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return $limits === [] ? null : max(1, intdiv(min($limits), 1024 * 1024));
    }

    /**
     * True when the administrator has asked for more than PHP will accept, so
     * the settings screen can say the server needs raising too.
     */
    public static function exceedsServerLimit(): bool
    {
        $server = self::serverMaxUploadSizeMb();

        return $server !== null && self::maxUploadSizeMb() > $server;
    }

    /**
     * Extensions no administrator value can admit.
     *
     * The lists are typed into a settings screen and flow straight into the
     * `mimes:` rules and the uploader's guard, and Laravel's mimes rule lets a
     * PHP file through when "php" is in the list. Uploads are stored under
     * their own extension on the public disk, so one careless entry would turn
     * the KYC form into a way to run code on the server.
     *
     * @var list<string>
     */
    public const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
        'htaccess', 'htpasswd', 'html', 'htm', 'shtml', 'xhtml', 'svgz',
        'js', 'mjs', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'dll', 'jar', 'jsp', 'asp', 'aspx',
    ];

    public static function isForbiddenExtension(string $extension): bool
    {
        return in_array(strtolower(ltrim(trim($extension), '.')), self::FORBIDDEN_EXTENSIONS, true);
    }

    /** @return list<string> */
    public static function imageExtensions(): array
    {
        return self::extensions('media_image_extensions', self::DEFAULT_IMAGE_EXTENSIONS);
    }

    /** @return list<string> */
    public static function documentExtensions(): array
    {
        return self::extensions('media_document_extensions', self::DEFAULT_DOCUMENT_EXTENSIONS);
    }

    /** @return list<string> */
    public static function identityDocumentExtensions(): array
    {
        return self::extensions('media_identity_extensions', self::DEFAULT_IDENTITY_EXTENSIONS);
    }

    /**
     * The backstop list for a general file upload.
     *
     * Identity documents travel through the same uploader as attachments, so
     * the guard has to accept everything either policy allows. Each feature
     * still validates against its own narrower list first; this only stops the
     * guard rejecting a file the form had already accepted.
     *
     * @return list<string>
     */
    public static function fileGuardExtensions(): array
    {
        return array_values(array_unique(array_merge(
            self::documentExtensions(),
            self::identityDocumentExtensions()
        )));
    }

    /**
     * Normalise an administrator's comma or space separated list. An empty or
     * entirely unusable value falls back rather than blocking every upload.
     *
     * @param  list<string> $fallback
     * @return list<string>
     */
    private static function extensions(string $key, array $fallback): array
    {
        $raw = (string) setting($key, '');

        $parsed = [];

        foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $candidate) {
            $extension = strtolower(ltrim(trim((string) $candidate), '.'));

            if ($extension !== '' && preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1 && ! self::isForbiddenExtension($extension)) {
                $parsed[] = $extension;
            }
        }

        $parsed = array_values(array_unique($parsed));

        return $parsed === [] ? $fallback : $parsed;
    }

    /**
     * Resolve a php.ini size directive to bytes. Returns null when unset, and
     * 0 when the directive means unlimited (post_max_size = 0).
     */
    private static function iniBytes(string $directive): ?int
    {
        $raw = ini_get($directive);

        if ($raw === false || trim((string) $raw) === '') {
            return null;
        }

        $raw   = trim((string) $raw);
        $unit  = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }
}
