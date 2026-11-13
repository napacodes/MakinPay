<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout;

use RuntimeException;
use Throwable;

/**
 * Certain that nothing able to move money reached the provider.
 *
 * This is the only exception a payout driver may throw that can lead to a
 * refund, so a driver throws it only where the provider provably did
 * nothing: a pre-flight refusal, a failed quote, or a definitive 4xx before
 * the step that releases funds. Every other failure must stay a plain
 * throwable, which the dispatcher treats as an unknown outcome.
 */
class PayoutNotSentException extends RuntimeException
{
    public const MAX_REASON_LENGTH = 255;

    public function __construct(
        protected string $stage,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::sanitise($reason), 0, $previous);
    }

    /** The step that refused: preflight, quote, initialize, create. */
    public function stage(): string
    {
        return $this->stage;
    }

    /** The sanitised, truncated reason, safe to store on the row. */
    public function reason(): string
    {
        return $this->getMessage();
    }

    /**
     * Strip markup and control characters and cap the length, so a provider
     * message can be stored and shown without carrying a body along.
     */
    public static function sanitise(string $reason): string
    {
        $clean = preg_replace('/[[:cntrl:]\s]+/u', ' ', strip_tags($reason)) ?? '';
        $clean = trim($clean);

        if ($clean === '') {
            return __('The payout provider refused the request.');
        }

        return mb_strimwidth($clean, 0, self::MAX_REASON_LENGTH, '…');
    }
}
