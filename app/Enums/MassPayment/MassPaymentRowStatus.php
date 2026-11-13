<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * Where one line of a mass payment stands.
 *
 *   Invalid   failed validation at draft time; never debited.
 *   Valid     passed validation; waiting for the batch to be confirmed.
 *   Skipped   invalid, and the owner chose to pay the rest; never debited.
 *   Pending   confirmed: its debit is reserved and PENDING.
 *   Paid      the recipient was credited and the debit completed.
 *   Failed    the debit failed and exactly one refund released its money.
 *   Reviewed  flagged for review and closed by an admin; no money moved.
 *
 * The column is a plain string(16).
 */
enum MassPaymentRowStatus: string
{
    case Invalid  = 'invalid';
    case Valid    = 'valid';
    case Skipped  = 'skipped';
    case Pending  = 'pending';
    case Paid     = 'paid';
    case Failed   = 'failed';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Invalid  => __('Invalid'),
            self::Valid    => __('Ready'),
            self::Skipped  => __('Skipped'),
            self::Pending  => __('Pending'),
            self::Paid     => __('Paid'),
            self::Failed   => __('Failed — refunded'),
            self::Reviewed => __('Closed after review'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Invalid  => 'danger',
            self::Valid    => 'success',
            self::Skipped  => 'secondary',
            self::Pending  => 'info',
            self::Paid     => 'success',
            self::Failed   => 'danger',
            self::Reviewed => 'dark',
        };
    }

    /** Nothing further happens to the line once it is here. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Skipped, self::Paid, self::Failed, self::Reviewed], true);
    }
}
