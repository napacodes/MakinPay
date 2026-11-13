<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Models\BillPayment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The timing rules of provider polling, read from
 * config('bill_payments.reconcile') with safe fallbacks: when the next status
 * check is due, the final-status time a provider may promise, and the moment
 * a payment it never resolves goes to a person.
 *
 * Pure: it writes nothing. BillPaymentService and BillPaymentStatusChecker
 * apply it.
 */
final class BillPaymentPollingSchedule
{
    /** @var list<int> */
    private const DEFAULT_BACKOFF = [5, 10, 15, 30, 60];

    /**
     * Minutes to wait before the next status check, after `$attempts` checks.
     * The last configured value repeats.
     */
    public static function backoffMinutes(int $attempts): int
    {
        $backoff = self::backoff();

        return $backoff[min(max(0, $attempts), count($backoff) - 1)];
    }

    /** When the next status check is due, after `$attempts` checks. */
    public static function nextCheckAt(int $attempts): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(self::backoffMinutes($attempts));
    }

    /**
     * The final-status time a provider gave, as a moment to stop waiting for:
     * null when it is not a parseable time or is already past, and never
     * later than the reconcile deadline counted from the hand-over.
     */
    public static function finalStatusAt(mixed $value, ?CarbonInterface $submittedAt): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            return null;
        }

        if (! $at->isAfter(CarbonImmutable::now())) {
            return null;
        }

        $cap = self::deadlineFrom($submittedAt);

        return $at->isAfter($cap) ? $cap : $at;
    }

    /**
     * When a payment still waiting on its provider goes to a person: the
     * provider's final-status time, or the deadline counted from the
     * hand-over, plus the grace period.
     */
    public static function escalateAt(BillPayment $payment): CarbonImmutable
    {
        $final = $payment->provider_final_status_at?->toImmutable()
            ?? self::deadlineFrom($payment->submitted_at ?? $payment->created_at);

        return $final->addMinutes(self::graceMinutes());
    }

    /** Whether a payment still waiting on its provider should now go to a person. */
    public static function isOverdue(BillPayment $payment): bool
    {
        return CarbonImmutable::now()->isAfter(self::escalateAt($payment))
            || (int) $payment->provider_check_attempts >= self::maxAttempts();
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('bill_payments.reconcile.max_attempts', 60));
    }

    /** How long a resolved payment may still be asked about. */
    public static function recheckTerminalDays(): int
    {
        return max(0, (int) config('bill_payments.reconcile.recheck_terminal_days', 7));
    }

    /**
     * Whether a resolved payment was resolved too long ago to be asked about
     * again: its resolution time (processed_at, falling back to updated_at)
     * is unknown or older than `recheck_terminal_days`. The status checker
     * and the webhook controller both use it, so the two never disagree.
     */
    public static function resolvedTooLongAgo(BillPayment $payment): bool
    {
        $resolvedAt = $payment->processed_at ?? $payment->updated_at;

        return $resolvedAt === null
            || $resolvedAt->lessThan(CarbonImmutable::now()->subDays(self::recheckTerminalDays()));
    }

    private static function deadlineFrom(?CarbonInterface $submittedAt): CarbonImmutable
    {
        $hours = max(1, (int) config('bill_payments.reconcile.deadline_hours', 48));

        return ($submittedAt?->toImmutable() ?? CarbonImmutable::now())->addHours($hours);
    }

    private static function graceMinutes(): int
    {
        return max(0, (int) config('bill_payments.reconcile.grace_minutes', 30));
    }

    /** @return non-empty-list<int> */
    private static function backoff(): array
    {
        $configured = config('bill_payments.reconcile.backoff_minutes', self::DEFAULT_BACKOFF);
        $minutes    = [];

        foreach (is_array($configured) ? $configured : [] as $value) {
            if (is_numeric($value) && (int) $value >= 1) {
                $minutes[] = (int) $value;
            }
        }

        return $minutes === [] ? self::DEFAULT_BACKOFF : $minutes;
    }
}
