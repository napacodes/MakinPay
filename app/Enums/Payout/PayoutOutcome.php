<?php

declare(strict_types=1);

namespace App\Enums\Payout;

use App\Enums\RemittanceStatus;
use App\Services\Payment\Payout\PayoutNotFoundException;
use App\Services\Payment\Payout\PayoutNotSentException;
use Throwable;

/**
 * What one provider call proved about a payout.
 *
 *   Completed  the provider confirms the money reached the recipient.
 *   Pending    the provider accepted the order and has not finished it.
 *   Failed     the provider confirms the payout will never happen.
 *   NotSent    certain that nothing able to move money reached the provider.
 *   NotFound   a lookup found no record. Always inconclusive.
 *   Unknown    anything else: a timeout, a 5xx, an unreadable answer.
 *
 * Only Failed and NotSent can ever lead to a refund. Unknown and NotFound
 * never do, because the provider may still pay.
 */
enum PayoutOutcome: string
{
    case Completed = 'completed';
    case Pending   = 'pending';
    case Failed    = 'failed';
    case NotSent   = 'not_sent';
    case NotFound  = 'not_found';
    case Unknown   = 'unknown';

    /**
     * Map a driver result status. A status that does not describe a payout
     * at the provider is unreadable, so it is Unknown.
     */
    public static function fromStatus(RemittanceStatus $status): self
    {
        return match ($status) {
            RemittanceStatus::Completed => self::Completed,
            RemittanceStatus::Failed    => self::Failed,
            RemittanceStatus::Submitted,
            RemittanceStatus::Processing,
            RemittanceStatus::Pending => self::Pending,
            default                   => self::Unknown,
        };
    }

    /**
     * Map what a driver threw. Only the two contract exceptions are
     * conclusive in any way; every other throwable is Unknown.
     */
    public static function fromThrowable(Throwable $exception): self
    {
        return match (true) {
            $exception instanceof PayoutNotSentException  => self::NotSent,
            $exception instanceof PayoutNotFoundException => self::NotFound,
            default                                       => self::Unknown,
        };
    }

    public function mayRefund(): bool
    {
        return $this === self::Failed || $this === self::NotSent;
    }
}
