<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * Where a bill payment stands, as the customer and the operator see it.
 *
 * How it got there, and therefore what may safely be done to it next, is
 * {@see BillPaymentDispatchState}. The two move together: Failed is only
 * ever written alongside the Refunded dispatch state and a refund ledger row,
 * in one database transaction.
 *
 * The column is a plain string(32), so a later state needs no migration.
 */
enum BillPaymentStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => __('Pending'),
            self::Processing => __('Processing'),
            self::Completed  => __('Completed'),
            self::Failed     => __('Failed'),
        };
    }

    /**
     * The wording a customer reads on the receipt and in the history list.
     */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Pending    => __('Submitted'),
            self::Processing => __('Being processed'),
            self::Completed  => __('Paid'),
            self::Failed     => __('Failed — refunded'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending    => 'warning',
            self::Processing => 'info',
            self::Completed  => 'success',
            self::Failed     => 'danger',
        };
    }

    /** Nothing further happens to the payment once it is here. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
