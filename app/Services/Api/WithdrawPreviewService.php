<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawAccount;
use App\Models\WithdrawMethod;
use App\Models\WithdrawSchedule;
use App\Services\PaymentService;
use App\Services\Tax\TaxPresenter;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;

/**
 * What a payout would cost and when it would land, before the customer
 * commits to it.
 *
 * The fee comes from the same PaymentService arithmetic that later charges
 * it, so the review screen and the ledger cannot disagree. The arrival is
 * an estimate: the method's processing time counted forward over the days
 * the withdraw schedule is open, because a "1 day" bank payout filed on a
 * Friday with weekends closed lands on Monday, not Saturday.
 */
class WithdrawPreviewService
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function __construct(private readonly PaymentService $payments) {}

    /**
     * @return array{currency: string, decimals: int|null, amount: string, fee: string, tax: array{amount: string, lines: list<array{name: string, amount: string}>}, total: string, fee_mode: string, min_amount: string|null, max_amount: string|null, within_limits: bool, daily_used: string, open_today: bool, next_open_day: string|null, arrives_at: string|null, processing: array{value: int|null, unit: string|null}}
     */
    public function quote(User $user, Wallet $wallet, WithdrawAccount $account, string $entered): array
    {
        $method   = $account->withdrawMethod;
        $currency = (string) $method->currency;
        $details  = $this->payments->quoteWithdraw($method, $entered, $user);

        $openToday = WithdrawSchedule::isWithdrawEnabledToday();
        $nextOpen  = $this->nextOpenDay(CarbonImmutable::now(), includeToday: false);

        // `amount` is what the payout rail sends and `total` is what the
        // wallet is debited. Under `deducted` the total is the typed figure
        // and the amount is that less the fee; under `on_top` the other way
        // round. `fee_mode` says which, so the app can label the pair as
        // "You receive" / "You pay" the right way round. GF6: `tax` is on
        // top in both modes, a line per rule, and is included in `total`.
        return [
            'currency'      => $currency,
            'decimals'      => ApiMoney::decimals($currency),
            'amount'        => ApiMoney::amount($details['netAmount'], $currency),
            'fee'           => ApiMoney::amount($details['payableCharge'], $currency),
            'tax'           => TaxPresenter::api($details['tax']),
            'total'         => ApiMoney::amount($details['payableAmount'], $currency),
            'fee_mode'      => $details['feeMode']->value,
            'min_amount'    => $details['min'] === null ? null : ApiMoney::amount($details['min'], $currency),
            'max_amount'    => $details['max'] === null ? null : ApiMoney::amount($details['max'], $currency),
            'within_limits' => $details['withinLimits'],
            'daily_used'    => ApiMoney::amount($this->usedToday($user, $currency), $currency),
            'open_today'    => $openToday,
            'next_open_day' => $nextOpen?->toDateString(),
            'arrives_at'    => $this->arrival($method, $openToday, $nextOpen)?->toIso8601String(),
            'processing'    => [
                'value' => $method->process_time_value === null ? null : (int) $method->process_time_value,
                'unit'  => $method->process_time_unit,
            ],
        ];
    }

    /**
     * What the customer has already sent out today in this currency, pending
     * or settled - a refused payout came back and does not count.
     */
    private function usedToday(User $user, string $currency): string
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::WITHDRAW)
            ->whereIn('status', [TrxStatus::PENDING, TrxStatus::COMPLETED])
            ->where('payable_currency', $currency)
            ->whereDate('created_at', CarbonImmutable::today())
            ->get(['payable_amount'])
            ->reduce(fn (string $carry, Transaction $trx): string => DecimalMoney::add($carry, (string) $trx->payable_amount, DecimalMoney::CALCULATION_SCALE), '0');
    }

    /**
     * The processing time counted from when the payout would be filed - now
     * if payouts are open, else the next open day - over open days only for
     * a time given in days. Hours and minutes are taken as they are, since
     * an automatic method does not wait for the schedule.
     */
    private function arrival(WithdrawMethod $method, bool $openToday, ?CarbonImmutable $nextOpen): ?CarbonImmutable
    {
        $value = (int) ($method->process_time_value ?? 0);
        $unit  = strtolower((string) ($method->process_time_unit ?? 'day'));
        $from  = $openToday ? CarbonImmutable::now() : $nextOpen;

        if ($from === null) {
            return null;
        }

        if ($value <= 0) {
            return $from;
        }

        return match (true) {
            str_starts_with($unit, 'minute') => $from->addMinutes($value),
            str_starts_with($unit, 'hour')   => $from->addHours($value),
            str_starts_with($unit, 'week')   => $this->addOpenDays($from, $value * 5),
            default                          => $this->addOpenDays($from, $value),
        };
    }

    private function addOpenDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $open = $this->openDays();
        $at   = $from;

        for ($added = 0; $added < $days; $added++) {
            $at = $at->addDay();
            for ($guard = 0; $guard < 7 && $open !== [] && ! in_array($at->format('l'), $open, true); $guard++) {
                $at = $at->addDay();
            }
        }

        return $at->setTime(18, 0);
    }

    private function nextOpenDay(CarbonImmutable $from, bool $includeToday): ?CarbonImmutable
    {
        $open = $this->openDays();

        if ($open === []) {
            return null;
        }

        $at = $includeToday ? $from : $from->addDay();
        for ($guard = 0; $guard < 7; $guard++) {
            if (in_array($at->format('l'), $open, true)) {
                return $at->startOfDay();
            }
            $at = $at->addDay();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function openDays(): array
    {
        $enabled = WithdrawSchedule::query()->where('status', true)->pluck('day')->map(fn ($day) => ucfirst(strtolower((string) $day)))->all();

        return array_values(array_intersect(self::DAYS, $enabled));
    }
}
