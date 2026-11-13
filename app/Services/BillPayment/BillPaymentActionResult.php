<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Models\BillPayment;
use InvalidArgumentException;

/**
 * How a settle or refund request ended.
 *
 *   done              this call moved the payment, and any money with it
 *   already_resolved  an earlier call already did the same thing; nothing moved
 *   anomaly           the ledger row was changed outside Bill payments, so the
 *                     payment was parked for review and nothing moved
 */
readonly class BillPaymentActionResult
{
    public const DONE = 'done';

    public const ALREADY_RESOLVED = 'already_resolved';

    public const ANOMALY = 'anomaly';

    public function __construct(
        public BillPayment $payment,
        public string $outcome,
    ) {
        if (! in_array($outcome, [self::DONE, self::ALREADY_RESOLVED, self::ANOMALY], true)) {
            throw new InvalidArgumentException("Unknown bill payment action outcome [{$outcome}].");
        }
    }

    public static function done(BillPayment $payment): self
    {
        return new self($payment, self::DONE);
    }

    public static function alreadyResolved(BillPayment $payment): self
    {
        return new self($payment, self::ALREADY_RESOLVED);
    }

    public static function anomaly(BillPayment $payment): self
    {
        return new self($payment, self::ANOMALY);
    }
}
