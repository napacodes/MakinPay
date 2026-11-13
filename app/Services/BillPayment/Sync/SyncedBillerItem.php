<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Sync;

use App\Support\DecimalMoney;
use InvalidArgumentException;

/**
 * One package of a synced biller (a TV bouquet, a fixed top-up), as the
 * provider lists it.
 *
 *   externalCode  the provider's code, sent back when this item is paid
 *   amount        the provider's price as an exact decimal string, never a
 *                 float; "0" where the provider lists no fixed price
 *   meta          provider facts kept on the biller's external meta
 */
readonly class SyncedBillerItem
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $externalCode,
        public string $name,
        public string $amount,
        public array $meta = [],
    ) {
        if (trim($externalCode) === '') {
            throw new InvalidArgumentException('A synced biller item needs an external code.');
        }

        if (! is_numeric($amount) || DecimalMoney::compare($amount, '0') < 0) {
            throw new InvalidArgumentException('A synced biller item amount must be a decimal string of zero or more.');
        }
    }
}
