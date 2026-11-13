<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * How far a payout line got with its provider, and therefore what may safely
 * be done to it.
 *
 *   Reserved          wallet debited, ledger row PENDING, the provider never
 *                     asked (`submitted_at` is null). The only state a line
 *                     may be refunded from without asking anyone.
 *   Submitting        claimed for the driver's send() call. What happened to
 *                     that call is not yet recorded.
 *   AwaitingOperator  the Manual driver queued it: an operator pays the
 *                     account and records the reference.
 *   AwaitingProvider  the provider accepted it, or its answer was unknown,
 *                     and reconcile is asking with read-only lookups.
 *   NeedsReview       the outcome is still unknown past the deadline, or the
 *                     provider contradicted the line. Never refunded or
 *                     re-sent on its own: a refund might double-pay, a resend
 *                     might pay twice. An admin decides per line.
 *   Settled           terminal success: ledger row completed.
 *   Refunded          terminal compensation: wallet restored, refund written.
 *
 * Wallet lines carry no dispatch state at all (`dispatch_state` is null). The
 * column stays a plain string; this enum is where the vocabulary lives. It
 * shares its words with BillPaymentDispatchState on purpose but is kept
 * separate, so neither module's states can change the other's.
 *
 * This is not the GF4 `needs_review` boolean on the row: that marks a ledger
 * anomaly and stops every runner, while NeedsReview here marks one line with
 * an unknown provider outcome and blocks nothing else in the batch.
 */
enum MassPaymentDispatchState: string
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

    /** The wording the batch owner reads next to the line. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Reserved         => __('Waiting to be sent'),
            self::Submitting       => __('Sending'),
            self::AwaitingOperator => __('Being processed'),
            self::AwaitingProvider => __('Sent — awaiting confirmation'),
            self::NeedsReview      => __('Being checked'),
            self::Settled          => __('Paid'),
            self::Refunded         => __('Not paid — refunded'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Reserved         => 'secondary',
            self::Submitting       => 'primary',
            self::AwaitingOperator => 'info',
            self::AwaitingProvider => 'info',
            self::NeedsReview      => 'warning',
            self::Settled          => 'success',
            self::Refunded         => 'danger',
        };
    }

    /**
     * The provider may have been asked, so the line must never be refunded or
     * re-sent without a definitive answer.
     */
    public function isInFlight(): bool
    {
        return in_array($this, [
            self::Submitting,
            self::AwaitingOperator,
            self::AwaitingProvider,
            self::NeedsReview,
        ], true);
    }

    /** Nothing further happens to the line on its own. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Settled, self::Refunded], true);
    }
}
