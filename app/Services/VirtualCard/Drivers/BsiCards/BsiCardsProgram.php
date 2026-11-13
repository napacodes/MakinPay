<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers\BsiCards;

use App\Enums\VirtualCard\VirtualCardNetwork;
use App\Models\VirtualCard;

/**
 * The BSICARDS card program a card belongs to.
 *
 * BSICARDS sells each program as its own API under its own path, with its
 * own minimums and its own response wording. The platform picks the program
 * from the network the customer asked for, and records it on the card so
 * every later call goes to the program that issued it.
 */
enum BsiCardsProgram: string
{
    /** Digital Visa Wallet: name + email, 3-D Secure auto-approved, Apple/Google Pay. */
    case Visa = 'visa';

    /** Digital USD Mastercard: needs the ID front, ID back and a selfie. */
    case Mastercard = 'mastercard';

    public static function fromNetwork(VirtualCardNetwork|string|null $network): ?self
    {
        $value = $network instanceof VirtualCardNetwork ? $network->value : strtolower(trim((string) $network));

        return self::tryFrom($value);
    }

    /**
     * The program recorded at issuance, falling back to the card's network
     * for a card that predates the record.
     */
    public static function forCard(VirtualCard $card): ?self
    {
        $meta = is_array($card->meta) ? $card->meta : [];

        return self::tryFrom((string) ($meta['bsi_program'] ?? ''))
            ?? self::fromNetwork($card->network);
    }

    /**
     * The API path of one action in this program.
     *
     * @param 'create'|'fund'|'get'|'block'|'unblock'|'transactions'|'otp' $action
     */
    public function path(string $action): ?string
    {
        $prefix = match ($this) {
            self::Visa       => 'digital-wallet-visa/',
            self::Mastercard => 'corpexpenses-mastercard-usd/',
        };

        $endpoint = match ($action) {
            'create'  => 'create-card',
            'fund'    => 'fund-card',
            'get'     => 'get-card',
            'block'   => 'block-card',
            'unblock' => 'unblock-card',
            // Visa embeds its transactions in get-card; only Mastercard lists them apart.
            'transactions' => $this === self::Mastercard ? 'transactions' : null,
            // The Apple/Google Pay one-time code exists only for the Visa wallet.
            'otp'   => $this === self::Visa ? 'get-otp' : null,
            default => null,
        };

        return $endpoint === null ? null : $prefix.$endpoint;
    }

    /**
     * The smallest amount BSICARDS will load in one funding call, in USD.
     */
    public function minimumFunding(): string
    {
        return match ($this) {
            self::Visa       => '5.00',
            self::Mastercard => '3.00',
        };
    }

    /**
     * What BSICARDS itself puts on every new card of this program, charged to
     * the operator's BSICARDS balance at creation — not to the customer.
     */
    public function starterLoad(): string
    {
        return match ($this) {
            self::Visa       => '5.00',
            self::Mastercard => '3.00',
        };
    }

    public function requiresKycFiles(): bool
    {
        return $this === self::Mastercard;
    }

    public function label(): string
    {
        return match ($this) {
            self::Visa       => 'BSICARDS Visa',
            self::Mastercard => 'BSICARDS Mastercard',
        };
    }
}
