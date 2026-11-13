<?php

namespace App\Support;

/**
 * Raise PHP's memory ceiling for a long operation — never lower it.
 *
 * Four places ask for 512M before walking a vendor tree or zipping storage,
 * and every one of them means "at least this much". Written as a plain
 * ini_set they also mean "at most this much", which is a different and much
 * worse instruction on a host that was configured with more.
 *
 * It cost an afternoon once: the test suite died in the translation loader at
 * 512M, thousands of tests after the updater test that had quietly taken the
 * ceiling down from the 2G the run was started with. Nothing near the crash
 * had anything to do with the updater.
 */
class MemoryLimit
{
    /**
     * Ensure at least $limit is available, leaving a larger ceiling alone.
     */
    public static function atLeast(string $limit): void
    {
        if (! function_exists('ini_set')) {
            return;
        }

        $current = self::bytes((string) ini_get('memory_limit'));

        // -1 is unlimited. No fixed ceiling is an improvement on it.
        if ($current === -1 || $current >= self::bytes($limit)) {
            return;
        }

        @ini_set('memory_limit', $limit);
    }

    /**
     * A shorthand byte value — "512M", "1G", "-1" — as an integer.
     *
     * -1 survives as -1 rather than becoming a very small ceiling, which is
     * the whole point of reading it at all.
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
