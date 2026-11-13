<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * How far a bill payment got, and therefore what may safely be done to it.
 *
 *   Reserved          wallet debited, ledger row PENDING, the fulfiller never
 *                     called (`submitted_at` is null).
 *   Submitting        claimed for the driver's pay() call. What happened to
 *                     that call is not yet recorded.
 *   AwaitingOperator  the Manual driver queued it: an operator pays the biller
 *                     and records the receipt.
 *   AwaitingProvider  a provider accepted it and the result is still to come.
 *   NeedsReview       the outcome is unknown, or the ledger row was changed
 *                     outside the module. Never refunded or retried on its
 *                     own: a refund might double-pay, a retry might pay twice.
 *   Settled           terminal success: ledger row completed.
 *   Refunded          terminal compensation: wallet restored, ledger row
 *                     failed, refund row written.
 *
 * The column itself stays a plain string, matching the virtual-card top-up
 * and remittance `dispatch_state`; this enum is where the vocabulary lives.
 */
enum BillPaymentDispatchState: string
{
    case Reserved         = 'reserved';
    case Submitting       = 'submitting';
    case AwaitingOperator = 'awaiting_operator';
    case AwaitingProvider = 'awaiting_provider';
    case NeedsReview      = 'needs_review';
    case Settled          = 'settled';
    case Refunded         = 'refunded';

    /** How the operator reads the stage on the admin screens. */
    public function label(): string
    {
        return match ($this) {
            self::Reserved         => __('Reserved'),
            self::Submitting       => __('Handing over'),
            self::AwaitingOperator => __('Waiting for an operator'),
            self::AwaitingProvider => __('Waiting for the provider'),
            self::NeedsReview      => __('Needs review'),
            self::Settled          => __('Settled'),
            self::Refunded         => __('Refunded'),
        };
    }

    /** Nothing further should happen to the payment on its own. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Settled, self::Refunded], true);
    }
}
