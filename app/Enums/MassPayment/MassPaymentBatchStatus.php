<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * Where a mass payment batch stands.
 *
 *   Draft               built and previewed; no money has moved.
 *   Discarded           the owner threw the draft away.
 *   Expired             the draft was never confirmed in time.
 *   AwaitingApproval    a confirmed payout batch: the total is reserved and
 *                       every payable line has a PENDING debit, but nothing
 *                       is sent until an admin approves it.
 *   Queued              confirmed: the total is reserved and every payable
 *                       line has a PENDING debit, waiting for the processor.
 *   Processing          the processor is paying or refunding lines.
 *   NeedsReview         held: the sender became unavailable or the ledger
 *                       disagreed with the batch. Nothing moves on its own.
 *   Completed           every line that was not skipped was paid.
 *   PartiallyCompleted  some lines were paid and the rest refunded.
 *   Failed              no line was paid; everything reserved was refunded.
 *   ClosedAfterReview   an admin closed it after manual reconciliation.
 *
 * The column is a plain string(24), so a later state needs no migration.
 */
enum MassPaymentBatchStatus: string
{
    case Draft              = 'draft';
    case Discarded          = 'discarded';
    case Expired            = 'expired';
    case AwaitingApproval   = 'awaiting_approval';
    case Queued             = 'queued';
    case Processing         = 'processing';
    case NeedsReview        = 'needs_review';
    case Completed          = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Failed             = 'failed';
    case ClosedAfterReview  = 'closed_after_review';

    /** How the operator reads the state on the admin screens. */
    public function label(): string
    {
        return match ($this) {
            self::Draft              => __('Draft'),
            self::Discarded          => __('Discarded'),
            self::Expired            => __('Expired'),
            self::AwaitingApproval   => __('Awaiting approval'),
            self::Queued             => __('Queued'),
            self::Processing         => __('Processing'),
            self::NeedsReview        => __('Needs review'),
            self::Completed          => __('Completed'),
            self::PartiallyCompleted => __('Partially completed'),
            self::Failed             => __('Failed'),
            self::ClosedAfterReview  => __('Closed after review'),
        };
    }

    /** The wording the batch owner reads in the history list and the summary. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Draft              => __('Awaiting confirmation'),
            self::Discarded          => __('Discarded'),
            self::Expired            => __('Expired'),
            self::AwaitingApproval   => __('Awaiting approval — funds reserved'),
            self::Queued             => __('Queued'),
            self::Processing         => __('Paying out'),
            self::NeedsReview        => __('On hold'),
            self::Completed          => __('Paid'),
            self::PartiallyCompleted => __('Partly paid — rest refunded'),
            self::Failed             => __('Not paid — refunded'),
            self::ClosedAfterReview  => __('Closed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft              => 'secondary',
            self::Discarded          => 'secondary',
            self::Expired            => 'secondary',
            self::AwaitingApproval   => 'info',
            self::Queued             => 'info',
            self::Processing         => 'primary',
            self::NeedsReview        => 'warning',
            self::Completed          => 'success',
            self::PartiallyCompleted => 'warning',
            self::Failed             => 'danger',
            self::ClosedAfterReview  => 'dark',
        };
    }

    /** Nothing further happens to the batch once it is here. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Discarded,
            self::Expired,
            self::Completed,
            self::PartiallyCompleted,
            self::Failed,
            self::ClosedAfterReview,
        ], true);
    }

    /** Money is reserved from the source wallet and not yet paid or released. */
    public function holdsMoney(): bool
    {
        return in_array($this, [self::AwaitingApproval, self::Queued, self::Processing, self::NeedsReview], true);
    }
}
