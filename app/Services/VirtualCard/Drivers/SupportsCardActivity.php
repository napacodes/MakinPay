<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers;

use App\Models\VirtualCard;

/**
 * A provider that can list what a card was used for. Each row is returned
 * already normalised, so the activity service never sees provider wording:
 *
 *   reference         string   the provider's own id for the row, stable across calls
 *   kind              string   purchase | refund | topup | withdraw | decline | fee
 *   status            string   pending | settled | declined | reversed
 *   amount            string   in the card's currency, positive
 *   currency          string
 *   merchant          ?string
 *   category          ?string
 *   channel           ?string  online | contactless | atm | intl | wallet
 *   original_amount   ?string  what the merchant charged, when in another currency
 *   original_currency ?string
 *   decline_reason    ?string  limit | frozen | control | insufficient | issuer
 *   occurred_at       string   ISO-8601
 */
interface SupportsCardActivity
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listCardActivity(VirtualCard $card): array;
}
