<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A phone number as a screen may show it to whoever is looking at it.
 *
 * The SMS-code page names the number the code went to, so the customer can
 * tell a typo from a slow text. It names it to anyone at the screen, though,
 * so four digits before the last four are hidden: enough left to recognise
 * your own number, not enough to read a stranger's off a shoulder.
 *
 * Punctuation and the '+' stay where they were typed. A number with eight
 * digits or fewer keeps its first two and hides the rest up to the last two.
 */
final class MaskedPhone
{
    private const string DOT = '•';

    public static function forDisplay(string $phone): string
    {
        $total = preg_match_all('/\d/', $phone);

        if ($total === false || $total === 0) {
            return $phone;
        }

        [$from, $until] = $total > 8 ? [$total - 8, $total - 4] : [min(2, $total), max(min(2, $total), $total - 2)];

        $seen = 0;

        return (string) preg_replace_callback('/\d/', function (array $digit) use (&$seen, $from, $until): string {
            $index = $seen++;

            return $index >= $from && $index < $until ? self::DOT : $digit[0];
        }, $phone);
    }
}
