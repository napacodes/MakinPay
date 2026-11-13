<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers;

use App\Models\VirtualCard;

/**
 * A driver whose provider will not load less than a fixed amount at a time.
 *
 * Opt-in, like the other Supports* contracts. VirtualCardManager asks before
 * it debits the wallet, so a top-up the provider would refuse is refused
 * while nothing has moved — rather than debited, sent, refused and refunded.
 */
interface SupportsFundingMinimum
{
    /**
     * @return string|null decimal string in the card's currency, or null for no minimum
     */
    public function minimumFunding(VirtualCard $card): ?string;
}
