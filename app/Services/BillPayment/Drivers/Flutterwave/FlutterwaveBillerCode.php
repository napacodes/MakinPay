<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Flutterwave;

/**
 * How a Flutterwave biller is written in `billers.external_code`.
 *
 * Flutterwave's `biller_code` is not unique (its own docs list BIL123 for
 * two different billers), so the code is prefixed with the country:
 *
 *   NG:BIL119         a package biller; the item comes from the payment's
 *                     item code (the item's `external_code`, e.g. CB141)
 *   NG:BIL112:UB157   an open-amount biller bound to one item
 *
 * The biller and item codes end up in the URL path, so only letters, digits,
 * dashes and underscores are accepted.
 */
final class FlutterwaveBillerCode
{
    private const PATTERN = '/^([A-Z]{2}):([A-Za-z0-9_-]{1,64})(?::([A-Za-z0-9_-]{1,64}))?$/';

    private const ITEM_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * The country, biller and item a payment is for, or null when the code
     * cannot be read, names no item, or names two different items.
     *
     * @return array{country: string, biller: string, item: string}|null
     */
    public static function parse(string $external, ?string $itemCode): ?array
    {
        if (preg_match(self::PATTERN, trim($external), $matches) !== 1) {
            return null;
        }

        $boundItem = ($matches[3] ?? '') === '' ? null : $matches[3];
        $itemCode  = $itemCode           === null || trim($itemCode) === '' ? null : trim($itemCode);

        if ($boundItem !== null && $itemCode !== null && $boundItem !== $itemCode) {
            return null;
        }

        $item = $boundItem ?? $itemCode;

        if ($item === null || preg_match(self::ITEM_PATTERN, $item) !== 1) {
            return null;
        }

        return [
            'country' => $matches[1],
            'biller'  => $matches[2],
            'item'    => $item,
        ];
    }

    /** The external code for a biller, or for an open biller bound to one item. */
    public static function format(string $country, string $biller, ?string $item = null): string
    {
        $code = strtoupper(trim($country)).':'.trim($biller);

        return $item === null || trim($item) === '' ? $code : $code.':'.trim($item);
    }
}
