<?php

namespace App\Services\Observability;

/**
 * Turns one occurrence into the identity of a bug.
 *
 * Two events share a fingerprint when they share a cause, not when they share
 * a message. A message carries ids, amounts, timestamps and quoted values that
 * differ on every occurrence, so it is normalised down to its shape before it
 * is hashed. Without that, one broken webhook produces four hundred separate
 * groups and the Issues list becomes the log file it was meant to replace.
 */
class ErrorFingerprinter
{
    /**
     * Deterministic hash of exception class, normalised message and the first
     * application frame.
     *
     * The column is char(32), so the sha256 is truncated rather than swapped
     * for a weaker digest — the collision budget across one installation's
     * error groups is nowhere near 128 bits.
     */
    public function fingerprint(string $exceptionClass, ?string $message, ?string $appFrame): string
    {
        $material = implode('|', [
            $exceptionClass,
            $this->normaliseMessage((string) $message),
            (string) $appFrame,
        ]);

        return substr(hash('sha256', $material), 0, 32);
    }

    /**
     * Strip everything that varies between two occurrences of one bug.
     *
     * Order matters: quoted strings go first, because a quoted value often
     * contains the digits and hyphens the later rules would otherwise chew
     * into a different shape each time.
     */
    public function normaliseMessage(string $message): string
    {
        $normalised = $message;

        $patterns = [
            '/([\'"])(?:\\.|(?!\1).)*\1/'                                         => '<str>',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/'                                        => '<ulid>',
            '/\b\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?\b/'                  => '<time>',
            '/\b[0-9a-f]{16,}\b/i'                                                => '<hex>',
            '/\b\d+(\.\d+)?\b/'                                                   => '<n>',
            '/\s+/'                                                               => ' ',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $normalised = preg_replace($pattern, $replacement, $normalised) ?? $normalised;
        }

        return trim(mb_strtolower($normalised));
    }

    /**
     * A short human label for the row: the file and line the bug lives at.
     */
    public function culprit(?string $file, ?int $line, ?string $function = null): string
    {
        if (blank($file)) {
            return '';
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $file);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        if (blank($function)) {
            return $line !== null ? $relative.':'.$line : $relative;
        }

        return $relative.':'.$line.' in '.$function;
    }
}
