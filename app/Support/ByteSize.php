<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Human byte sizes that survive a host without ext-intl.
 *
 * `Number::fileSize()` hard-requires the extension, which is absent on a good
 * share of shared hosts and took the whole media library down with a 500. The
 * media asset row carried the local fallback on its own; the storage rail
 * reports the same kind of number, so the fallback lives here rather than in
 * two places.
 */
final class ByteSize
{
    /** @var list<string> */
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * @param bool|null $intlAvailable Overrides the extension probe. Callers
     *                                 that own the seam — a model exposing it
     *                                 so a test can force the fallback — pass
     *                                 their own answer.
     */
    public static function human(int|float|null $bytes, int $precision = 0, ?bool $intlAvailable = null): string
    {
        $bytes = (int) ($bytes ?? 0);

        if ($intlAvailable ?? extension_loaded('intl')) {
            return Number::fileSize($bytes, $precision);
        }

        $value = (float) $bytes;
        $unit  = 0;

        while (($value / 1024) > 0.9 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $precision).' '.self::UNITS[$unit];
    }
}
