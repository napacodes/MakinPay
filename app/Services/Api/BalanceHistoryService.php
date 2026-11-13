<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CurrencyService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;

/**
 * A wallet's closing balance for each day of a window, for the app's charts.
 *
 * Nothing stores a daily balance, so the series is rebuilt backwards from the
 * balance held now: yesterday's close is today's close minus what moved
 * today, and so on. Only COMPLETED rows move a balance; a pending or failed
 * row never did. The wallet side of a row is `payable_amount` in
 * `payable_currency`, the unit the money actually moved in, so a sender's
 * day shows the fee leaving with the amount. A row that never named the
 * wallet unit falls back to `net_amount` when that is in the wallet's own
 * currency, and is otherwise left out rather than guessed.
 *
 * Money stays in bcmath throughout and is quantized once per point, on the
 * way out. A series over several wallets is restated at today's stored rates
 * - the history of the rates is not kept, and a chart drawn with the rate the
 * customer sees today is the one that matches the total above it.
 */
class BalanceHistoryService
{
    /** @var array<string, int|null> days per range, null meaning since the first movement */
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90, '1y' => 365, 'all' => null];

    private const MAX_DAYS = 730;

    public function __construct(private readonly CurrencyService $currencies) {}

    /**
     * Every active wallet added up, day by day, in `$currency`.
     *
     * @return array{range: string, currency: string, decimals: int|null, points: list<array{date: string, balance: string}>, change: array{amount: string, percent: string|null}}
     */
    public function forUser(User $user, string $range, string $currency): array
    {
        $wallets = $user->wallets()->with('currency')->active()->get();
        $days    = $this->days($range, $wallets->map(fn (Wallet $wallet): ?CarbonImmutable => $this->firstMovement($wallet))->filter()->min());
        $rates   = $this->currencies->ratesByCode($wallets->map(fn (Wallet $wallet) => $wallet->currency?->code)->push($currency));
        $display = strtoupper($currency);
        $base    = strtoupper((string) siteCurrency());
        $factor  = $display === $base ? '1' : ($rates->get($display) ?? '1');

        $totals = array_fill(0, $days, DecimalMoney::normalize(0));

        foreach ($wallets as $wallet) {
            foreach ($this->series($wallet, $days) as $index => $balance) {
                $inBase   = $this->currencies->toBaseAmount($balance, $wallet->currency?->code, $rates);
                $restated = DecimalMoney::multiply($inBase, $factor);

                $totals[$index] = DecimalMoney::add($totals[$index], $restated, DecimalMoney::CALCULATION_SCALE);
            }
        }

        return $this->payload($range, $display, $totals, $days);
    }

    /**
     * One wallet, day by day, in its own currency.
     *
     * @return array{range: string, currency: string, decimals: int|null, points: list<array{date: string, balance: string}>, change: array{amount: string, percent: string|null}}
     */
    public function forWallet(Wallet $wallet, string $range): array
    {
        $days = $this->days($range, $this->firstMovement($wallet));

        return $this->payload($range, strtoupper((string) $wallet->currency?->code), $this->series($wallet, $days), $days);
    }

    public static function isRange(?string $range): bool
    {
        return $range !== null && array_key_exists($range, self::RANGES);
    }

    /**
     * Closing balances, oldest first: index 0 is `$days - 1` days ago and the
     * last index is today.
     *
     * @return list<string>
     */
    private function series(Wallet $wallet, int $days): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $code  = strtoupper((string) $wallet->currency?->code);

        // Every day after the oldest point is needed to walk back to it.
        $movements = $this->movementsByDay($wallet, $code, $today->subDays($days - 2));

        $closing = [$days - 1 => DecimalMoney::normalize((string) $wallet->balance)];

        for ($index = $days - 2; $index >= 0; $index--) {
            $nextDay         = $today->subDays($days - 2 - $index)->toDateString();
            $closing[$index] = DecimalMoney::subtract($closing[$index + 1], $movements[$nextDay] ?? '0', DecimalMoney::CALCULATION_SCALE);
        }

        ksort($closing);

        return array_values($closing);
    }

    /**
     * Signed wallet movement per calendar day, from `$from` onwards.
     *
     * @return array<string, string> date => amount, positive for money in
     */
    private function movementsByDay(Wallet $wallet, string $code, CarbonImmutable $from): array
    {
        // A sandbox payment never touched the balance, so walking back
        // through it would put money in the past that was never there.
        $rows = $wallet->transactions()
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'amount_flow', 'currency', 'net_amount', 'payable_amount', 'payable_currency']);

        $byDay = [];

        foreach ($rows as $row) {
            $moved = $this->walletSide($row, $code);
            $flow  = $row->amount_flow;

            if ($moved === null || ! $flow instanceof AmountFlow || $flow === AmountFlow::DEFAULT) {
                continue;
            }

            $day         = $row->created_at->toDateString();
            $signed      = $flow === AmountFlow::MINUS ? '-'.$moved : $moved;
            $byDay[$day] = DecimalMoney::add($byDay[$day] ?? '0', $signed, DecimalMoney::CALCULATION_SCALE);
        }

        return $byDay;
    }

    private function walletSide(Transaction $row, string $walletCode): ?string
    {
        $payableCode = strtoupper(trim((string) $row->getRawOriginal('payable_currency')));
        $payable     = $row->getRawOriginal('payable_amount');

        if ($payable !== null && $payableCode === $walletCode) {
            return DecimalMoney::normalize((string) $payable);
        }

        if (strtoupper(trim((string) $row->getRawOriginal('currency'))) === $walletCode) {
            return DecimalMoney::normalize((string) ($row->getRawOriginal('net_amount') ?? 0));
        }

        return null;
    }

    private function firstMovement(Wallet $wallet): ?CarbonImmutable
    {
        $first = $wallet->transactions()->where('status', TrxStatus::COMPLETED)->withoutSandbox()->min('created_at');

        return $first === null ? null : CarbonImmutable::parse((string) $first);
    }

    /**
     * How many daily points the range spans; `all` runs from the first
     * movement, capped so an old account cannot ask for years of rows.
     */
    private function days(string $range, ?CarbonImmutable $firstMovement): int
    {
        $days = array_key_exists($range, self::RANGES) ? self::RANGES[$range] : 30;

        if ($days === null) {
            $days = $firstMovement === null
                ? 2
                : (int) $firstMovement->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()) + 2;
        }

        return max(2, min($days, self::MAX_DAYS));
    }

    /**
     * @param  list<string>                                                                                                                                                        $balances calculation-scale, oldest first
     * @return array{range: string, currency: string, decimals: int|null, points: list<array{date: string, balance: string}>, change: array{amount: string, percent: string|null}}
     */
    private function payload(string $range, string $currency, array $balances, int $days): array
    {
        $today  = CarbonImmutable::now()->startOfDay();
        $points = [];

        foreach ($balances as $index => $balance) {
            $points[] = [
                'date'    => $today->subDays($days - 1 - $index)->toDateString(),
                'balance' => ApiMoney::amount($balance, $currency),
            ];
        }

        $first  = $balances[0]                    ?? '0';
        $last   = $balances[count($balances) - 1] ?? '0';
        $change = DecimalMoney::subtract($last, $first, DecimalMoney::CALCULATION_SCALE);

        return [
            'range'    => $range,
            'currency' => $currency,
            'decimals' => ApiMoney::decimals($currency),
            'points'   => $points,
            'change'   => [
                'amount'  => ApiMoney::amount($change, $currency),
                'percent' => DecimalMoney::compare($first, '0') > 0
                    ? DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide($change, $first), 100), 2)
                    : null,
            ],
        ];
    }
}
