<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a merchant has taken, one row per currency and never added across
 * them: all time, today against yesterday with today hour by hour, and the
 * last thirty days as a daily series for a sparkline.
 *
 * A payment lands on the merchant's ledger as a RECEIVE_PAYMENT row whose
 * amount is the net after the fee and whose payable_amount is what the
 * customer paid. Gross is therefore payable_amount where a row has one, and
 * amount where it does not (a row written before payable_amount existed).
 */
class MerchantTakings
{
    public const SERIES_DAYS = 30;

    /**
     * @return list<array<string, mixed>>
     */
    public function for(User $user): array
    {
        $today     = now()->startOfDay();
        $yesterday = $today->copy()->subDay();
        $since     = $today->copy()->subDays(self::SERIES_DAYS - 1);

        $allTime = $this->totals($this->rows($user))->keyBy('currency');
        $todays  = $this->totals($this->rows($user)->where('created_at', '>=', $today))->keyBy('currency');
        $before  = $this->totals($this->rows($user)->where('created_at', '>=', $yesterday)->where('created_at', '<', $today))->keyBy('currency');

        $days = $this->rows($user)
            ->where('created_at', '>=', $since)
            ->selectRaw('currency, DATE(created_at) as day, SUM(COALESCE(payable_amount, amount)) as gross')
            ->groupBy('currency', 'day')
            ->get()
            ->groupBy('currency');

        $hours = $this->hourly($user, $today);

        return $allTime->map(function ($row, string $currency) use ($todays, $before, $days, $since, $hours, $today): array {
            $todayRow     = $todays->get($currency);
            $yesterdayRow = $before->get($currency);

            $byDay  = ($days->get($currency) ?? collect())->keyBy('day');
            $series = [];
            for ($i = 0; $i < self::SERIES_DAYS; $i++) {
                $day      = $since->copy()->addDays($i)->toDateString();
                $series[] = ['date' => $day, 'gross' => ApiMoney::amount($byDay->get($day)?->gross ?? 0, $currency)];
            }

            $todayGross     = (string) ($todayRow?->gross ?? '0');
            $yesterdayGross = (string) ($yesterdayRow?->gross ?? '0');

            return [
                'currency' => $currency,
                'decimals' => ApiMoney::decimals($currency),
                'payments' => (int) $row->payments,
                'gross'    => ApiMoney::amount($row->gross, $currency),
                'fees'     => ApiMoney::amount($row->fees, $currency),
                'net'      => ApiMoney::amount($row->net, $currency),
                'today'    => [
                    'payments' => (int) ($todayRow?->payments ?? 0),
                    'gross'    => ApiMoney::amount($todayGross, $currency),
                    'fees'     => ApiMoney::amount($todayRow?->fees ?? 0, $currency),
                    'net'      => ApiMoney::amount($todayRow?->net ?? 0, $currency),
                    // When the server's day began, so a phone in another zone can
                    // place hourly[0] on its own clock.
                    'from'   => $today->toIso8601String(),
                    'hourly' => array_map(
                        fn (string $gross): string => ApiMoney::amount($gross, $currency),
                        $hours[$currency] ?? array_fill(0, 24, '0'),
                    ),
                ],
                'yesterday_gross' => ApiMoney::amount($yesterdayGross, $currency),
                'change_pct'      => $this->changePct($todayGross, $yesterdayGross),
                'series'          => $series,
            ];
        })->values()->all();
    }

    /**
     * Today against yesterday, to one decimal; null when yesterday took
     * nothing, since a rise from zero has no percentage.
     */
    private function changePct(string $today, string $yesterday): ?string
    {
        if (DecimalMoney::compare($yesterday, '0') <= 0) {
            return null;
        }

        $change = DecimalMoney::divide(DecimalMoney::multiply(DecimalMoney::subtract($today, $yesterday, 8), '100', 8), $yesterday, 8);

        return number_format((float) $change, 1, '.', '');
    }

    /**
     * Today's gross per currency in 24 hourly buckets, midnight first, in the
     * application's time zone - the hour the shop saw the payment in.
     *
     * Grouped in SQL, so a busy shop's day is 24 rows a currency, not every
     * payment. The hour is cut from the stored timestamp's text, characters
     * 12-13 of 'YYYY-MM-DD HH:MM:SS': HOUR() is MySQL's and strftime() is
     * SQLite's, but SUBSTR reads the same on both, and the timestamps are
     * stored in the application's time zone. toBase() skips the user each
     * Transaction otherwise loads with it.
     *
     * @return array<string, list<string>>
     */
    private function hourly(User $user, CarbonInterface $today): array
    {
        $hours = [];

        $rows = $this->rows($user)
            ->where('created_at', '>=', $today)
            ->selectRaw('currency, SUBSTR(created_at, 12, 2) as hour, SUM(COALESCE(payable_amount, amount)) as gross')
            ->groupBy('currency', 'hour')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $hour     = (int) $row->hour;

            $hours[$currency] ??= array_fill(0, 24, '0');
            $hours[$currency][$hour] = DecimalMoney::add($hours[$currency][$hour], (string) $row->gross);
        }

        return $hours;
    }

    /**
     * Completed payments that paid out. Sandbox charges are left out: their
     * net was never credited (the Paid screen says so), the figures are
     * across all the user's shops whatever mode each is in, and the business
     * app shows them as real takings with no test label.
     */
    private function rows(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::RECEIVE_PAYMENT)
            ->where('amount_flow', AmountFlow::PLUS)
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox();
    }

    private function totals(Builder $rows): Collection
    {
        return $rows
            ->selectRaw('currency, COUNT(*) as payments, SUM(COALESCE(payable_amount, amount)) as gross, SUM(fee) as fees, SUM(COALESCE(net_amount, amount)) as net')
            ->groupBy('currency')
            ->get();
    }
}
