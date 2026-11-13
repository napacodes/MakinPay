<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\ScheduledPayment;
use App\Models\ScheduledPaymentRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Api\Insights\TransactionCategorizer;
use App\Services\CurrencyService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;

/**
 * The calendar side of scheduled payments: when a schedule runs next,
 * what the next thirty days hold, whether the wallets cover it, and the
 * subscriptions the ledger shows being paid every month without one.
 */
class ScheduledPaymentService
{
    public const RUN_HOUR = 9;

    public const HORIZON_DAYS = 30;

    public function __construct(private readonly CurrencyService $currencies) {}

    /**
     * The first run strictly after a moment, on the schedule's cadence,
     * shifted off a weekend by its rule; null once the schedule is spent.
     */
    public static function nextRunAfter(ScheduledPayment $schedule, CarbonImmutable $after): ?CarbonImmutable
    {
        $start = CarbonImmutable::parse($schedule->starts_on)->startOfDay()->setHour(self::RUN_HOUR);
        $end   = $schedule->ends_on === null ? null : CarbonImmutable::parse($schedule->ends_on)->endOfDay();
        for ($i = 0; $i < 400; $i++) {
            $candidate = self::occurrence($schedule, $start, $i);
            if ($candidate === null) {
                return null;
            }
            $shifted = self::shiftWeekend($candidate, $schedule->weekend_rule);
            if ($end !== null && $candidate > $end) {
                return null;
            }
            if ($shifted > $after) {
                return $shifted;
            }
        }

        return null;
    }

    /**
     * Every run in a window, for the timeline and the coverage check.
     *
     * @return list<CarbonImmutable>
     */
    public static function occurrencesBetween(ScheduledPayment $schedule, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out    = [];
        $next   = $schedule->next_run_at?->toImmutable() ?? self::nextRunAfter($schedule, $from->subSecond());
        $cursor = $next;
        $guard  = 0;
        while ($cursor !== null && $cursor <= $to && $guard++ < 200) {
            if ($cursor >= $from) {
                $out[] = $cursor;
            }
            if (! $schedule->isRecurring()) {
                break;
            }
            $cursor = self::nextRunAfter($schedule, $cursor);
        }

        return $out;
    }

    /**
     * The i-th run counting from the start date, before the weekend rule.
     */
    private static function occurrence(ScheduledPayment $schedule, CarbonImmutable $start, int $i): ?CarbonImmutable
    {
        switch ($schedule->cadence) {
            case 'once':
                return $i === 0 ? $start : null;
            case 'weekly':
                $days = array_values(array_filter(array_map('intval', (array) ($schedule->weekdays ?? [])), fn (int $d) => $d >= 1 && $d <= 7));
                if ($days === []) {
                    return $start->addWeeks($i);
                }
                sort($days);
                // Walk the weekdays in order from the start date onward.
                $week      = intdiv($i, count($days));
                $slot      = $days[$i % count($days)];
                $base      = $start->startOfWeek()->addWeeks($week)->setHour(self::RUN_HOUR);
                $candidate = $base->addDays($slot - 1);

                return $candidate < $start ? self::occurrence($schedule, $start, $i + 1) : $candidate;
            case 'monthly':
                $day   = max(1, min(31, (int) ($schedule->day_of_month ?? $start->day)));
                $month = $start->startOfMonth()->addMonths($i);

                return $month->setDay(min($day, $month->daysInMonth))->setHour(self::RUN_HOUR);
            default:
                $every = max(1, (int) ($schedule->interval_days ?? 1));

                return $start->addDays($i * $every);
        }
    }

    private static function shiftWeekend(CarbonImmutable $date, string $rule): CarbonImmutable
    {
        if ($rule === 'none' || ! $date->isWeekend()) {
            return $date;
        }
        if ($rule === 'before') {
            return $date->isSaturday() ? $date->subDay() : $date->subDays(2);
        }

        return $date->isSaturday() ? $date->addDays(2) : $date->addDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $display   = $user->displayCurrency();
        $now       = CarbonImmutable::now();
        $horizon   = $now->addDays(self::HORIZON_DAYS)->endOfDay();
        $schedules = $user->scheduledPayments()->whereIn('status', ['active', 'paused'])->with(['wallet.currency', 'vault', 'topUpWallet.currency'])->orderBy('next_run_at')->get();

        $upcoming  = [];
        $perWallet = [];
        $total     = '0';
        foreach ($schedules->where('status', 'active') as $schedule) {
            foreach (self::occurrencesBetween($schedule, $now->startOfDay(), $horizon) as $when) {
                $upcoming[] = ['date' => $when->toDateString(), 'at' => $when->toIso8601String(), 'schedule' => $this->describe($schedule, $display), 'source' => 'schedule'];
                $code       = (string) $schedule->currency;
                $perWallet[$schedule->wallet_id] ??= ['wallet_id' => $schedule->wallet?->uuid, 'currency' => $code, 'due' => '0', 'balance' => (string) ($schedule->wallet?->balance ?? '0')];
                $perWallet[$schedule->wallet_id]['due'] = DecimalMoney::add($perWallet[$schedule->wallet_id]['due'], (string) $schedule->amount, DecimalMoney::CALCULATION_SCALE);
                $rate                                   = $this->currencies->crossRate($code, $display) ?? '1';
                $total                                  = DecimalMoney::add($total, DecimalMoney::multiply((string) $schedule->amount, $rate), DecimalMoney::CALCULATION_SCALE);
            }
        }
        $detected = $this->detectSubscriptions($user);
        foreach ($detected as $sub) {
            if ($sub['next_expected'] !== null && $sub['next_expected'] <= $horizon->toDateString()) {
                $upcoming[] = ['date' => $sub['next_expected'], 'at' => null, 'schedule' => null, 'detected' => $sub, 'source' => 'detected'];
            }
        }
        usort($upcoming, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        $covered = true;
        foreach ($perWallet as &$w) {
            $w['covered'] = DecimalMoney::compare($w['balance'], $w['due']) >= 0;
            $w['due']     = ApiMoney::amount($w['due'], $w['currency']);
            $w['balance'] = ApiMoney::amount($w['balance'], $w['currency']);
            $covered      = $covered && $w['covered'];
        }
        unset($w);

        $history = ScheduledPaymentRun::query()
            ->whereIn('scheduled_payment_id', $user->scheduledPayments()->select('id'))
            ->with('schedule')
            ->latest('due_at')
            ->limit(50)
            ->get()
            ->map(fn (ScheduledPaymentRun $r): array => $this->describeRun($r) + ['name' => $r->schedule?->note ?: ($r->schedule?->recipient_name ?: __('Scheduled payment')), 'schedule_id' => $r->scheduled_payment_id])
            ->values()
            ->all();

        return [
            'currency' => $display,
            'decimals' => ApiMoney::decimals($display),
            'summary'  => [
                'next_30_days' => ApiMoney::amount($total, $display),
                'count'        => count(array_filter($upcoming, fn (array $u) => $u['source'] === 'schedule')),
                'covered'      => $covered,
                'wallets'      => array_values($perWallet),
            ],
            'upcoming'  => $upcoming,
            'recurring' => $schedules->filter(fn (ScheduledPayment $s) => $s->status === 'active' && $s->isRecurring())->map(fn (ScheduledPayment $s): array => $this->describe($s, $display))->values()->all(),
            'paused'    => $schedules->where('status', 'paused')->map(fn (ScheduledPayment $s): array => $this->describe($s, $display))->values()->all(),
            'once'      => $schedules->filter(fn (ScheduledPayment $s) => $s->status === 'active' && ! $s->isRecurring())->map(fn (ScheduledPayment $s): array => $this->describe($s, $display))->values()->all(),
            'history'   => $history,
            'detected'  => $detected,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(ScheduledPayment $schedule, string $display, bool $withRuns = false): array
    {
        $code = (string) $schedule->currency;
        $out  = [
            'id'               => $schedule->id,
            'type'             => $schedule->type,
            'name'             => $schedule->note ?: ($schedule->recipient_name ?: ($schedule->vault?->name ?? __('Scheduled payment'))),
            'note'             => $schedule->note,
            'recipient'        => $schedule->recipient,
            'recipient_name'   => $schedule->recipient_name,
            'vault_id'         => $schedule->vault_id,
            'vault_name'       => $schedule->vault?->name,
            'payload'          => $schedule->payload,
            'wallet_id'        => $schedule->wallet?->uuid,
            'wallet_last4'     => substr((string) $schedule->wallet?->uuid, -4),
            'amount'           => ApiMoney::amount((string) $schedule->amount, $code),
            'currency'         => $code,
            'decimals'         => ApiMoney::decimals($code),
            'cadence'          => $schedule->cadence,
            'interval_days'    => $schedule->interval_days,
            'weekdays'         => $schedule->weekdays,
            'day_of_month'     => $schedule->day_of_month,
            'starts_on'        => $schedule->starts_on?->toDateString(),
            'ends_on'          => $schedule->ends_on?->toDateString(),
            'weekend_rule'     => $schedule->weekend_rule,
            'low_balance'      => $schedule->low_balance,
            'retry_days'       => $schedule->retry_days,
            'top_up_wallet_id' => $schedule->topUpWallet?->uuid,
            'remind'           => $schedule->remind,
            'remind_at'        => $schedule->remind_at,
            'status'           => $schedule->status,
            'next_run_at'      => $schedule->next_run_at?->toIso8601String(),
            'last_run_at'      => $schedule->last_run_at?->toIso8601String(),
            'last_status'      => $schedule->last_status,
            'failed_reason'    => $schedule->failed_reason,
            'retry_count'      => $schedule->retry_count,
            'paused_at'        => $schedule->paused_at?->toIso8601String(),
            'created_at'       => $schedule->created_at?->toIso8601String(),
        ];
        if ($withRuns) {
            $out['runs'] = $schedule->runs()->latest('due_at')->limit(50)->get()->map(fn (ScheduledPaymentRun $r): array => $this->describeRun($r))->values()->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRun(ScheduledPaymentRun $run): array
    {
        return [
            'id'          => $run->id,
            'due_at'      => $run->due_at?->toIso8601String(),
            'executed_at' => $run->executed_at?->toIso8601String(),
            'attempt'     => $run->attempt,
            'status'      => $run->status,
            'reference'   => $run->reference,
            'amount'      => ApiMoney::amount((string) $run->amount, (string) $run->currency),
            'currency'    => $run->currency,
            'reason'      => $run->reason,
        ];
    }

    /**
     * Merchants the ledger shows being paid the same amount about a month
     * apart, twice or more in the last four months - read-only, so the
     * customer sees them on the timeline without a schedule of their own.
     *
     * @return list<array<string, mixed>>
     */
    public function detectSubscriptions(User $user): array
    {
        $since = CarbonImmutable::now()->subDays(125);
        $rows  = $user->transactions()
            ->where('status', TrxStatus::COMPLETED)
            ->where('amount_flow', AmountFlow::MINUS)
            ->whereIn('trx_type', [TrxType::PAYMENT, TrxType::SUBSCRIPTION, TrxType::SUBSCRIPTION_RENEWAL])
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = TransactionCategorizer::merchantKey($row);
            if ($key === null) {
                continue;
            }
            $groups[$key.'|'.(string) $row->currency.'|'.DecimalMoney::normalize((string) $row->amount, 2)][] = $row;
        }

        $out = [];
        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $monthly = true;
            for ($i = 1; $i < count($rows); $i++) {
                $gap = $rows[$i - 1]->created_at->diffInDays($rows[$i]->created_at);
                if ($gap < 25 || $gap > 35) {
                    $monthly = false;
                    break;
                }
            }
            if (! $monthly) {
                continue;
            }
            /** @var Transaction $last */
            $last  = $rows[count($rows) - 1];
            $out[] = [
                'name'          => TransactionCategorizer::merchantName($last),
                'amount'        => ApiMoney::amount((string) $last->amount, (string) $last->currency),
                'currency'      => (string) $last->currency,
                'count'         => count($rows),
                'last_at'       => $last->created_at?->toDateString(),
                'next_expected' => $last->created_at?->addMonthNoOverflow()->toDateString(),
                'category'      => TransactionCategorizer::forUser((int) $user->id)->categoryOf($last),
                'source'        => 'card',
            ];
        }

        return $out;
    }
}
