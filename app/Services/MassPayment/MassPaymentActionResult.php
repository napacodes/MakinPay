<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use InvalidArgumentException;

/**
 * How one processor step on a mass payment line or batch ended.
 *
 *   done              this call moved the line or the batch, and any money with it
 *   already_resolved  an earlier call already did it; nothing moved
 *   anomaly           the ledger disagreed with the line or the batch, which was
 *                     flagged for review; nothing moved
 *   held              the sender cannot pay right now; nothing moved and the
 *                     batch is to be held
 *   not_ready         the batch still has lines to pay, so it cannot be finalized
 */
readonly class MassPaymentActionResult
{
    public const DONE = 'done';

    public const ALREADY_RESOLVED = 'already_resolved';

    public const ANOMALY = 'anomaly';

    public const HELD = 'held';

    public const NOT_READY = 'not_ready';

    public function __construct(
        public string $outcome,
        public ?MassPaymentBatch $batch = null,
        public ?MassPaymentRow $row = null,
    ) {
        if (! in_array($outcome, [self::DONE, self::ALREADY_RESOLVED, self::ANOMALY, self::HELD, self::NOT_READY], true)) {
            throw new InvalidArgumentException("Unknown mass payment action outcome [{$outcome}].");
        }
    }

    public static function done(?MassPaymentBatch $batch = null, ?MassPaymentRow $row = null): self
    {
        return new self(self::DONE, $batch, $row);
    }

    public static function alreadyResolved(?MassPaymentBatch $batch = null, ?MassPaymentRow $row = null): self
    {
        return new self(self::ALREADY_RESOLVED, $batch, $row);
    }

    public static function anomaly(?MassPaymentBatch $batch = null, ?MassPaymentRow $row = null): self
    {
        return new self(self::ANOMALY, $batch, $row);
    }

    public static function held(?MassPaymentBatch $batch = null, ?MassPaymentRow $row = null): self
    {
        return new self(self::HELD, $batch, $row);
    }

    public static function notReady(?MassPaymentBatch $batch = null, ?MassPaymentRow $row = null): self
    {
        return new self(self::NOT_READY, $batch, $row);
    }

    public function is(string $outcome): bool
    {
        return $this->outcome === $outcome;
    }
}
