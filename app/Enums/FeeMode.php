<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which side of the typed amount a fee lands on.
 *
 * A customer with 1.00 in a wallet, a 20% fee and a 1.00 minimum could not
 * withdraw at all under OnTop: 1.00 needed 1.20, and the 0.83 that fitted
 * was below the minimum. Every exchange and most payout rails take the fee
 * out of the figure typed instead, so Deducted is what their users expect;
 * OnTop is what a cash-out at a mobile-money counter does, and what every
 * installation did before the switch existed. Hence the default.
 *
 * The mode is read once per flow from the admin settings - layer C - so an
 * operator chooses it and nothing here hardcodes it.
 */
enum FeeMode: string
{
    case OnTop    = 'on_top';
    case Deducted = 'deducted';

    public static function withdraw(): self
    {
        return self::fromSetting('withdraw_fee_mode');
    }

    public static function exchange(): self
    {
        return self::fromSetting('exchange_fee_mode');
    }

    /**
     * An unknown or missing value is the mode the platform always had, so a
     * half-upgraded installation keeps charging the way it did.
     */
    public static function fromSetting(string $key): self
    {
        return self::tryFrom((string) setting($key, self::OnTop->value)) ?? self::OnTop;
    }

    public function isDeducted(): bool
    {
        return $this === self::Deducted;
    }

    /**
     * Label => value, the shape the settings select renders from.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'Added on top of the amount (customer pays amount + fee)'   => self::OnTop->value,
            'Deducted from the amount (customer receives amount - fee)' => self::Deducted->value,
        ];
    }
}
