<?php

declare(strict_types=1);

namespace App\Enums\VirtualCard;

/**
 * How far a top-up attempt got, and therefore what may safely be done to it.
 *
 * The distinction the whole recovery story rests on is Reserved vs Submitting.
 * The move between them is committed *before* the provider request goes out,
 * so after a crash the two are never confused:
 *
 *   Reserved     wallet debited, ledger row written, provider never contacted.
 *                The only state where compensating automatically is safe.
 *   Submitting   the request went out. What happened to it is unknown.
 *   Succeeded    the provider conclusively confirmed the funding.
 *   Declined     the provider conclusively refused it.
 *   NeedsReview  the outcome cannot be established. An operator's problem,
 *                never a cron job's — refunding might double-pay a funded
 *                card, retrying might fund it twice.
 *   Settled      terminal success: ledger completed, card credited.
 *   Refunded     terminal compensation: wallet restored, ledger failed.
 *
 * The column itself stays a plain string, matching RemittanceTransfer's
 * `dispatch_state`; this enum is where the vocabulary lives.
 */
enum VirtualCardTopupDispatchState: string
{
    case Reserved    = 'reserved';
    case Submitting  = 'submitting';
    case Succeeded   = 'succeeded';
    case Declined    = 'declined';
    case NeedsReview = 'needs_review';
    case Settled     = 'settled';
    case Refunded    = 'refunded';

    /** Nothing further should happen to the attempt on its own. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Settled, self::Refunded], true);
    }

    /**
     * Whether the provider is known never to have been contacted.
     *
     * Only true for Reserved, and the caller must still confirm
     * `submitted_at` is null before acting on it.
     */
    public function isSafeToCompensate(): bool
    {
        return $this === self::Reserved;
    }
}
