<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers;

/**
 * A driver that can be told which top-up it is performing.
 *
 * Opt-in on purpose. VirtualCardProviderInterface::topUpCard() cannot grow a
 * parameter without breaking every custom driver already deployed against it,
 * so idempotent funding is declared separately and detected at runtime.
 *
 * A driver implementing this promises that two calls carrying the same
 * `$reference` fund the card once. That promise is what allows a verified
 * retry after an ambiguous outcome; a driver without it is never retried
 * automatically.
 */
interface SupportsIdempotentTopUp
{
    /**
     * @param string $reference deterministic, minted once per attempt and reused by every retry
     * @param string $amount    decimal string in the card's currency
     */
    public function topUpCardWithReference(string $reference, string $amount, $cardID): array;
}
