<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AgentStatus;
use App\Enums\KycStatus;
use App\Enums\MerchantStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\Currency;
use App\Models\KycSubmission;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyService;
use App\Support\LedgerReporting;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What the dashboard counts, and what money did over a range.
 *
 * Every figure is restated into the base currency through CurrencyService, so
 * a site taking deposits in six currencies gets one comparable headline rather
 * than six that cannot be added.
 *
 * A delta is null, never zero and never a percentage, whenever the prior
 * period had nothing to compare against. A brand-new install showing
 * "+infinity%" on its second transaction is worse than showing nothing.
 */
class DashboardMetricsService
{
    /**
     * Days of shape behind each counter card's sparkline.
     */
    private const COUNTER_DAYS = 7;

    /**
     * How recently an agent must have moved money to count as active.
     */
    private const AGENT_ACTIVE_DAYS = 30;

    /**
     * The most columns the money chart will draw. A longer range is folded
     * into this many buckets rather than drawn one stripe per day.
     */
    private const COLUMN_BUDGET = 30;

    /**
     * Currencies listed under the treasury block, and the swatch palette they
     * cycle through.
     */
    private const TREASURY_ROWS = 7;

    private const TREASURY_SWATCHES = 6;

    /**
     * The debit legs of a payment. Their credit mirrors — receive_payment,
     * receive_money — are deliberately absent: counting both would report
     * every transfer twice.
     *
     * @var array<int, TrxType>
     */
    private const PAYMENT_TYPES = [TrxType::SEND_MONEY, TrxType::PAYMENT];

    public function __construct(private readonly CurrencyService $currencies) {}

    /**
     * The five headline counters, each with the seven-day shape behind it.
     *
     * Every figure is a count the database returned. A delta that cannot be
     * computed honestly — nothing in the prior period to compare against — is
     * null, and the card renders without its chip rather than inventing zero.
     *
     * @return list<array<string, mixed>>
     */
    public function counters(): array
    {
        $days       = self::COUNTER_DAYS;
        $windowOpen = now()->subDays($days - 1)->startOfDay();
        $priorOpen  = now()->subDays($days * 2 - 1)->startOfDay();
        $dates      = $this->recentDates($days);
        $todayKey   = now()->toDateString();

        $signupsByDay  = $this->countByDay(User::query(), 'created_at', $windowOpen);
        $signupsWindow = array_sum($signupsByDay);
        $signupsPrior  = User::query()->where('created_at', '>=', $priorOpen)->where('created_at', '<', $windowOpen)->count();
        $signupsToday  = $signupsByDay[$todayKey] ?? 0;
        $totalUsers    = User::query()->count();
        $usersBefore   = max(0, $totalUsers - $signupsWindow);

        $kycApproved  = KycSubmission::query()->where('status', KycStatus::APPROVED)->distinct()->count('user_id');
        $kycByDay     = $this->countByDay(KycSubmission::query()->where('status', KycStatus::APPROVED), 'updated_at', $windowOpen);
        $kycBefore    = max(0, $kycApproved - array_sum($kycByDay));
        $kycSeries    = $this->shareSeries($dates, $kycByDay, $kycBefore, $signupsByDay, $usersBefore);
        $kycRateNow   = $totalUsers > 0 ? $kycApproved / $totalUsers * 100 : null;
        $kycRatePrior = $kycSeries === [] ? null : $kycSeries[0];

        $merchantsTotal  = Merchant::query()->where('status', MerchantStatus::APPROVED)->count();
        $merchantsByDay  = $this->countByDay(Merchant::query()->where('status', MerchantStatus::APPROVED), 'updated_at', $windowOpen);
        $merchantsWeek   = array_sum($merchantsByDay);
        $merchantsBefore = max(0, $merchantsTotal - $merchantsWeek);

        // A merchant's sandbox test traffic is not platform activity, and its
        // charges always "succeed", which would flatter the success rate.
        $trxByDay     = $this->countByDay(Transaction::query()->without('user')->withoutSandbox(), 'created_at', $windowOpen);
        $trxToday     = $trxByDay[$todayKey] ?? 0;
        $trxDoneToday = Transaction::query()->without('user')->withoutSandbox()->where('status', TrxStatus::COMPLETED)->where('created_at', '>=', now()->startOfDay())->count();
        $successRate  = $trxToday > 0 ? $trxDoneToday / $trxToday * 100 : null;

        $agentsNow    = $this->activeAgents(now()->subDays(self::AGENT_ACTIVE_DAYS), now());
        $agentsBefore = $this->activeAgents(now()->subDays(self::AGENT_ACTIVE_DAYS + $days), now()->subDays($days));
        $agentsByDay  = $this->agentsByDay($windowOpen);

        return [
            [
                'key'   => 'customers',
                'tone'  => 'brand',
                'label' => __('Total customers'),
                'sub'   => __('All registered accounts'),
                'tip'   => __('All registered user accounts'),
                'value' => number_format($totalUsers),
                'delta' => $this->percentChip($this->deltaPercent((float) $signupsWindow, (float) $signupsPrior, true)),
                'note'  => __(':week this week · :today today', [
                    'week'  => $this->signed($signupsWindow),
                    'today' => number_format($signupsToday),
                ]),
                'spark' => $this->seriesFor($dates, $signupsByDay),
                'link'  => route('admin.user.index'),
            ],
            [
                'key'   => 'kyc',
                'tone'  => 'success',
                'label' => __('KYC verified'),
                'sub'   => trans_choice('{0}No approved identities|{1}:count approved identity|[2,*]:count approved identities', $kycApproved, ['count' => number_format($kycApproved)]),
                'tip'   => __('Share of customers with an approved KYC'),
                'value' => $kycRateNow === null ? '—' : round($kycRateNow).'%',
                'delta' => $this->pointChip($kycRateNow === null || $kycRatePrior === null ? null : $kycRateNow - $kycRatePrior),
                'note'  => __('of all customers'),
                'spark' => $kycSeries,
                'link'  => route('admin.kyc.index'),
            ],
            [
                'key'   => 'merchants',
                'tone'  => 'teal',
                'label' => __('Active merchants'),
                'sub'   => __('Approved business profiles'),
                'tip'   => __('Merchant profiles with approved status'),
                'value' => number_format($merchantsTotal),
                'delta' => $merchantsWeek === 0 ? null : ['text' => $this->signed($merchantsWeek), 'direction' => 'up'],
                'note'  => __('approved this week'),
                'spark' => $this->cumulativeFor($dates, $merchantsByDay, $merchantsBefore),
                'link'  => route('admin.merchant.index'),
            ],
            [
                'key'   => 'transactions',
                'tone'  => 'info',
                'label' => __('Transactions today'),
                'sub'   => __('All types · any status'),
                'tip'   => __('All transactions created today, any status'),
                'value' => number_format($trxToday),
                'delta' => $successRate === null ? null : ['text' => number_format($successRate, 1).'%', 'direction' => 'flat'],
                'note'  => __('completed successfully'),
                'spark' => $this->seriesFor($dates, $trxByDay),
                'link'  => route('admin.transaction'),
            ],
            [
                'key'   => 'agents',
                'tone'  => 'warning',
                'label' => __('Active agents'),
                'sub'   => __('Transacted in last :days days', ['days' => self::AGENT_ACTIVE_DAYS]),
                'tip'   => __('Approved agents who transacted in the last :days days', ['days' => self::AGENT_ACTIVE_DAYS]),
                'value' => number_format($agentsNow),
                'delta' => $agentsNow === $agentsBefore
                    ? ['text' => '0', 'direction' => 'flat']
                    : ['text' => $this->signed($agentsNow - $agentsBefore), 'direction' => $agentsNow > $agentsBefore ? 'up' : 'down'],
                'note'  => __('vs last week'),
                'spark' => $this->seriesFor($dates, $agentsByDay),
                'link'  => route('admin.agent.index'),
            ],
        ];
    }

    /**
     * Money in, money out, payments and fee revenue over one range, each with
     * the same span immediately before it to compare against.
     *
     * @return array{
     *     base: string,
     *     rows: list<array<string, mixed>>,
     *     net: array{value: float, display: string, positive: bool},
     *     has_data: bool
     * }
     */
    public function moneyKpis(DashboardRange $range): array
    {
        $base    = $this->baseCode();
        $current = $this->flowTotals($range->start, $range->end);
        $span    = $range->start->diffInSeconds($range->end);
        $prior   = $this->flowTotals(
            $range->start->copy()->subSeconds($span + 1),
            $range->start->copy()->subSecond(),
        );

        $definitions = [
            ['key' => 'in', 'label' => __('Money in'), 'tip' => __('Completed deposits in this range'), 'invert' => false],
            ['key' => 'out', 'label' => __('Money out'), 'tip' => __('Completed withdrawals in this range'), 'invert' => true],
            ['key' => 'payments', 'label' => __('Payments & transfers'), 'tip' => __('Wallet-to-wallet, merchant and link payments'), 'invert' => false],
            ['key' => 'fees', 'label' => __('Fee revenue'), 'tip' => __('Platform fees earned on completed transactions'), 'invert' => false],
        ];

        $rows = [];

        foreach ($definitions as $definition) {
            $key   = $definition['key'];
            $value = $current[$key];
            $delta = $this->deltaPercent($value, $prior[$key], true);

            $rows[] = [
                'key'     => $key,
                'label'   => $definition['label'],
                'tip'     => $definition['tip'],
                'value'   => $value,
                'display' => Money::compact($value, $base),
                'full'    => Money::format($value, $base),
                'delta'   => $delta === null ? null : [
                    'text' => ($delta >= 0 ? '+' : '−').number_format(abs($delta), 1).'%',
                    /*
                     * More money leaving is not good news, so the money-out
                     * cell warns on a rise rather than congratulating it.
                     */
                    'direction' => $delta >= 0
                        ? ($definition['invert'] ? 'warn' : 'up')
                        : ($definition['invert'] ? 'up' : 'warn'),
                ],
            ];
        }

        $net = $current['in'] - $current['out'];

        return [
            'base' => $base,
            'rows' => $rows,
            'net'  => [
                'value'    => $net,
                'display'  => ($net >= 0 ? '+' : '−').Money::compact(abs($net), $base),
                'positive' => $net >= 0,
            ],
            'has_data' => $current['in'] > 0 || $current['out'] > 0 || $current['payments'] > 0 || $current['fees'] > 0,
        ];
    }

    /**
     * The bar chart: one bucket per column, the fee polyline over it, the
     * y-axis ticks beside it, and which bucket was busiest.
     *
     * A range longer than the column budget is folded — a 90-day custom span
     * becomes 30 three-day columns rather than 90 stripes a pixel wide.
     *
     * @return array{
     *     buckets: list<array<string, mixed>>,
     *     y_ticks: list<array{top: string, label: string}>,
     *     fee_points: string,
     *     busiest: string|null,
     *     max: float,
     *     has_data: bool
     * }
     */
    public function chart(DashboardRange $range): array
    {
        $base    = $this->baseCode();
        $buckets = $range->preset === 'today'
            ? $this->hourlyBuckets($range)
            : $this->dailyBuckets($range);

        $max    = 0.0;
        $feeMax = 0.0;

        foreach ($buckets as $bucket) {
            $max    = max($max, $bucket['in'], $bucket['out']);
            $feeMax = max($feeMax, $bucket['fee']);
        }

        /*
         * Head-room above the tallest bar, so the top of the chart is not the
         * top of one column — and the fee line gets its own scale, because a
         * fee is a small percentage of a bar and would otherwise trace the
         * x-axis.
         */
        $ceiling = $max    > 0 ? $max    * 1.08 : 0.0;
        $feeRoof = $feeMax > 0 ? $feeMax * 1.6 : 0.0;

        $rows       = [];
        $feePoints  = [];
        $busiest    = null;
        $busiestSum = 0.0;
        $count      = max(1, count($buckets));

        foreach (array_values($buckets) as $index => $bucket) {
            $sum = $bucket['in'] + $bucket['out'];

            if ($sum > $busiestSum) {
                $busiestSum = $sum;
                $busiest    = $bucket['long'];
            }

            $rows[] = [
                'label'   => $bucket['label'],
                'long'    => $bucket['long'],
                'in'      => $bucket['in'],
                'out'     => $bucket['out'],
                'fee'     => $bucket['fee'],
                'in_pct'  => $ceiling > 0 ? round($bucket['in'] / $ceiling * 100, 1) : 0.0,
                'out_pct' => $ceiling > 0 ? round($bucket['out'] / $ceiling * 100, 1) : 0.0,
                'tip'     => __('In :in · Out :out · Fees :fee', [
                    'in'  => Money::compact($bucket['in'], $base),
                    'out' => Money::compact($bucket['out'], $base),
                    'fee' => Money::compact($bucket['fee'], $base),
                ]),
            ];

            $feePoints[] = round(($index + 0.5) / $count * 100, 2).','
                .round($feeRoof > 0 ? 100 - $bucket['fee'] / $feeRoof * 100 : 100, 2);
        }

        $ticks = [];

        foreach ([0.25, 0.5, 0.75, 1.0] as $fraction) {
            $ticks[] = [
                'top'   => round(100 - $fraction * 100).'%',
                'label' => Money::compact($ceiling * $fraction, $base),
            ];
        }

        return [
            'buckets'    => $rows,
            'y_ticks'    => $ceiling > 0 ? $ticks : [],
            'fee_points' => implode(' ', $feePoints),
            'busiest'    => $busiestSum > 0 ? $busiest : null,
            'max'        => $ceiling,
            'has_data'   => $max > 0 || $feeMax > 0,
        ];
    }

    /**
     * What customers are holding right now, by currency.
     *
     * The database adds the balances up; hydrating a wallet model per customer
     * to do it in PHP costs roughly 11.5 KB each and buys nothing.
     *
     * @return array{
     *     base: string,
     *     total: float,
     *     wallets: int,
     *     currencies: int,
     *     delta: array{text: string, positive: bool}|null,
     *     rows: list<array<string, mixed>>,
     *     shown: int,
     *     vaults: array{total: float, count: int, currencies: int, shown: int, rows: list<array<string, mixed>>},
     *     liability: float
     * }
     *
     * The wallet figures and the week's delta are wallets only, as they always
     * were. `vaults` is the savings-vault money beside them and `liability`
     * the two added up - not every obligation: stakes, escrow and card
     * balances are elsewhere.
     */
    public function treasury(): array
    {
        $currencies = Currency::query()
            ->withCount('wallets')
            ->withSum('wallets', 'balance')
            ->whereHas('wallets')
            ->get();

        $base    = $this->baseCode();
        $rates   = $this->currencies->ratesByCode($currencies->pluck('code'));
        $default = Currency::getDefault();

        $rows = $currencies
            ->map(function (Currency $currency) use ($rates, $default): array {
                $native = (float) ($currency->wallets_sum_balance ?? 0);

                return [
                    'code'       => (string) $currency->code,
                    'name'       => (string) $currency->name,
                    'wallets'    => (int) $currency->wallets_count,
                    'status'     => (bool) $currency->status,
                    'is_default' => $default !== null && strcasecmp((string) $currency->code, (string) $default->code) === 0,
                    // What the wallets actually hold, beside what it is worth
                    // in the reporting currency. The panel shows the second;
                    // the first is what reconciles against the wallets table.
                    'native' => $native,
                    'total'  => (float) $this->currencies->toBaseAmount($native, $currency->code, $rates),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $total  = (float) $rows->sum('total');
        $vaults = $this->treasuryVaults($rates);

        return [
            'base'       => $base,
            'total'      => $total,
            'wallets'    => (int) $rows->sum('wallets'),
            'currencies' => $rows->count(),
            'delta'      => $this->treasuryWeekDelta($total),
            // Money customers moved out of a wallet into a savings vault. It
            // left the wallet figures above, and is still theirs.
            'vaults'    => $vaults,
            'liability' => $total + $vaults['total'],
            'shown'     => min(self::TREASURY_ROWS, $rows->count()),
            'rows'      => $rows
                ->take(self::TREASURY_ROWS)
                ->values()
                ->map(function (array $row, int $index) use ($total): array {
                    $share = $total > 0 ? $row['total'] / $total * 100 : 0.0;

                    return $row + [
                        'share' => $share,
                        /*
                         * A currency holding a tenth of a percent rounded to
                         * "0.0%", which reads as empty when it is not.
                         */
                        'share_display' => $share <= 0 ? '0%' : ($share < 1 ? '<1%' : number_format($share, 1).'%'),
                        'swatch'        => $index % self::TREASURY_SWATCHES,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * Savings-vault money by currency, restated in the base currency.
     *
     * A separate read from the wallets', so a site that has not run the vault
     * migration still gets its wallet figures. Closed vaults are emptied when
     * they close, so a positive balance is the whole filter.
     *
     * @param  Collection<string, string>                                                                     $rates the wallet query's rates; a vault's currency is always its wallet's
     * @return array{total: float, count: int, currencies: int, shown: int, rows: list<array<string, mixed>>}
     */
    private function treasuryVaults(Collection $rates): array
    {
        $empty = ['total' => 0.0, 'count' => 0, 'currencies' => 0, 'shown' => 0, 'rows' => []];

        if (! Schema::hasTable('vaults')) {
            return $empty;
        }

        // vaults.balance in full: the join brings wallets.balance along.
        $holding = fn (Builder $query) => $query->where('vaults.balance', '>', 0);

        $rows = Currency::query()
            ->withCount(['vaults' => $holding])
            ->withSum('vaults', 'balance')
            ->whereHas('vaults', $holding)
            ->get()
            ->map(fn (Currency $currency): array => [
                'code'   => (string) $currency->code,
                'name'   => (string) $currency->name,
                'vaults' => (int) $currency->vaults_count,
                'native' => (string) ($currency->vaults_sum_balance ?? '0'),
                'total'  => (float) $this->currencies->toBaseAmount((string) ($currency->vaults_sum_balance ?? '0'), $currency->code, $rates),
            ])
            ->sortByDesc('total')
            ->values();

        if ($rows->isEmpty()) {
            return $empty;
        }

        return [
            'total'      => (float) $rows->sum('total'),
            'count'      => (int) $rows->sum('vaults'),
            'currencies' => $rows->count(),
            'shown'      => min(self::TREASURY_ROWS, $rows->count()),
            'rows'       => $rows->take(self::TREASURY_ROWS)->values()->all(),
        ];
    }

    /**
     * How the treasury moved this week.
     *
     * Nothing stores last week's balance, so it is derived from the flow that
     * produced the change: completed deposits, less withdrawals, less fees,
     * over the last seven days. Transfers between customers net to zero across
     * the treasury, which is why they are not in it. A week that opened at or
     * below zero has no percentage to report and gets null.
     *
     * @return array{text: string, positive: bool}|null
     */
    private function treasuryWeekDelta(float $total): ?array
    {
        $flow     = $this->flowTotals(now()->subDays(6)->startOfDay(), now()->endOfDay());
        $movement = $flow['in'] - $flow['out'] - $flow['fees'];
        $opening  = $total      - $movement;

        if ($opening <= 0.0 || $movement === 0.0) {
            return null;
        }

        $percent = $movement / $opening * 100;

        return [
            'text'     => ($percent >= 0 ? '+' : '−').number_format(abs($percent), 1).'%',
            'positive' => $percent >= 0,
        ];
    }

    /**
     * Completed money in, out, payments and fees between two instants, all
     * restated into the base currency.
     *
     * Amount and fee are asked for separately because each carries its own
     * reporting currency: a row restated by the FX snapshot pairs base_amount
     * with base_currency, and grouping the fee by the amount's currency would
     * convert it through the wrong rate.
     *
     * @return array{in: float, out: float, payments: float, fees: float}
     */
    private function flowTotals(Carbon $start, Carbon $end): array
    {
        // Sandbox payments complete without moving money, so neither query
        // counts them.
        $amounts = Transaction::query()
            ->without('user')
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->whereBetween('created_at', [$start, $end])
            ->reorder()
            ->selectRaw(
                'trx_type, '.LedgerReporting::currency('amount').' as currency,'
                .' SUM('.LedgerReporting::amount('amount').') as amount'
            )
            ->groupByRaw('trx_type, '.LedgerReporting::currency('amount'))
            ->get();

        $fees = Transaction::query()
            ->without('user')
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->whereBetween('created_at', [$start, $end])
            ->reorder()
            ->selectRaw(
                LedgerReporting::currency('fee').' as currency,'
                .' SUM('.LedgerReporting::amount('fee').') as amount'
            )
            ->groupByRaw(LedgerReporting::currency('fee'))
            ->get();

        $rates = $this->currencies->ratesByCode($amounts->pluck('currency')->concat($fees->pluck('currency')));
        $sum   = fn (Collection $group): float => (float) $this->currencies->sumInBaseCurrency($group, 'amount', $rates);

        $byType   = $amounts->groupBy(fn ($row): string => $this->typeValue($row->trx_type));
        $payments = collect();

        foreach (self::PAYMENT_TYPES as $type) {
            $payments = $payments->concat($byType[$type->value] ?? collect());
        }

        return [
            'in'       => $sum($byType[TrxType::DEPOSIT->value] ?? collect()),
            'out'      => $sum($byType[TrxType::WITHDRAW->value] ?? collect()),
            'payments' => $sum($payments),
            'fees'     => $sum($fees),
        ];
    }

    /**
     * One column per day, folding a long range into at most COLUMN_BUDGET.
     *
     * @return list<array{label: string, long: string, in: float, out: float, fee: float}>
     */
    private function dailyBuckets(DashboardRange $range): array
    {
        $totals = $this->flowBySlot($range->start, $range->end, 'DATE(created_at)');
        $days   = [];

        for ($cursor = $range->start->copy()->startOfDay(); $cursor->lte($range->end); $cursor->addDay()) {
            $days[] = $cursor->copy();
        }
        $size   = (int) max(1, ceil(count($days) / self::COLUMN_BUDGET));
        $chunks = array_chunk($days, $size);

        /*
         * Thirty day numbers across the width of one panel is a grey smear.
         * A week gets a weekday under every column; anything longer labels
         * every nth, and the columns in between keep their tooltip.
         */
        $folded = $size > 1;
        $every  = count($chunks) <= 7 ? 1 : (int) ceil(count($chunks) / ($folded ? 6 : 7));

        $buckets = [];

        foreach ($chunks as $index => $chunk) {
            $in = $out = $fee = 0.0;

            foreach ($chunk as $day) {
                $key = $day->toDateString();
                $in  += $totals[$key]['in']  ?? 0.0;
                $out += $totals[$key]['out'] ?? 0.0;
                $fee += $totals[$key]['fee'] ?? 0.0;
            }

            $first = $chunk[0];
            $label = count($chunks) <= 7
                ? $first->isoFormat('ddd')
                : ($folded ? $first->isoFormat('D MMM') : $first->isoFormat('DD'));

            $buckets[] = [
                'label' => $index % $every === 0 ? $label : '',
                'long'  => $first->isoFormat('D MMM'),
                'in'    => $in,
                'out'   => $out,
                'fee'   => $fee,
            ];
        }

        return $buckets;
    }

    /**
     * Twenty-four columns for a single day.
     *
     * @return list<array{label: string, long: string, in: float, out: float, fee: float}>
     */
    private function hourlyBuckets(DashboardRange $range): array
    {
        $totals  = $this->flowBySlot($range->start, $range->end, $this->hourExpression());
        $buckets = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $label = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

            $buckets[] = [
                'label' => $hour % 4 === 0 ? $label : '',
                'long'  => $label.':00',
                'in'    => $totals[$hour]['in']  ?? 0.0,
                'out'   => $totals[$hour]['out'] ?? 0.0,
                'fee'   => $totals[$hour]['fee'] ?? 0.0,
            ];
        }

        return $buckets;
    }

    /**
     * The hour of a timestamp, in the dialect of whatever database this is.
     *
     * MySQL has HOUR(); SQLite, which the test suite runs on, does not, and
     * naming a function the engine has never heard of is a hard error rather
     * than a wrong answer.
     */
    private function hourExpression(): string
    {
        return match (Schema::getConnection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', created_at) AS INTEGER)",
            'pgsql'  => 'EXTRACT(HOUR FROM created_at)',
            'sqlsrv' => 'DATEPART(hour, created_at)',
            default  => 'HOUR(created_at)',
        };
    }

    /**
     * Deposits, withdrawals and fees per time slot, in base currency.
     *
     * @return array<array-key, array{in: float, out: float, fee: float}>
     */
    private function flowBySlot(Carbon $start, Carbon $end, string $slotSql): array
    {
        $amounts = Transaction::query()
            ->without('user')
            ->where('status', TrxStatus::COMPLETED)
            ->whereIn('trx_type', [TrxType::DEPOSIT->value, TrxType::WITHDRAW->value])
            ->whereBetween('created_at', [$start, $end])
            ->reorder()
            ->selectRaw(
                'trx_type, '.$slotSql.' as slot, '.LedgerReporting::currency('amount').' as currency,'
                .' SUM('.LedgerReporting::amount('amount').') as amount'
            )
            ->groupByRaw('trx_type, '.$slotSql.', '.LedgerReporting::currency('amount'))
            ->get();

        // Untyped, so a sandbox charge's fee would land here: not earned.
        $fees = Transaction::query()
            ->without('user')
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->whereBetween('created_at', [$start, $end])
            ->reorder()
            ->selectRaw(
                $slotSql.' as slot, '.LedgerReporting::currency('fee').' as currency,'
                .' SUM('.LedgerReporting::amount('fee').') as amount'
            )
            ->groupByRaw($slotSql.', '.LedgerReporting::currency('fee'))
            ->get();

        $rates  = $this->currencies->ratesByCode($amounts->pluck('currency')->concat($fees->pluck('currency')));
        $totals = [];

        foreach ($amounts as $row) {
            $slot = $row->slot;

            $totals[$slot] ??= ['in' => 0.0, 'out' => 0.0, 'fee' => 0.0];

            $value = (float) $this->currencies->toBaseAmount($row->amount, $row->currency, $rates);

            if ($this->typeValue($row->trx_type) === TrxType::DEPOSIT->value) {
                $totals[$slot]['in'] += $value;
            } else {
                $totals[$slot]['out'] += $value;
            }
        }

        foreach ($fees as $row) {
            $slot = $row->slot;

            $totals[$slot] ??= ['in' => 0.0, 'out' => 0.0, 'fee' => 0.0];

            $totals[$slot]['fee'] += (float) $this->currencies->toBaseAmount($row->amount, $row->currency, $rates);
        }

        return $totals;
    }

    /**
     * The model casts trx_type, but a raw aggregate can hand back either the
     * enum or the string it was stored as.
     */
    private function typeValue(mixed $type): string
    {
        return $type instanceof TrxType ? $type->value : (string) $type;
    }

    /**
     * Rows per day in one query, keyed by date string.
     *
     * @param  Builder<*> $query
     * @return array<string, int>
     */
    private function countByDay(Builder $query, string $column, Carbon $from): array
    {
        return $query
            ->where($column, '>=', $from)
            ->reorder()
            ->selectRaw('DATE('.$column.') as slot, COUNT(*) as tally')
            ->groupByRaw('DATE('.$column.')')
            ->pluck('tally', 'slot')
            ->map(fn ($tally): int => (int) $tally)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function agentsByDay(Carbon $from): array
    {
        return AgentOperation::query()
            ->where('created_at', '>=', $from)
            ->reorder()
            ->selectRaw('DATE(created_at) as slot, COUNT(DISTINCT agent_id) as tally')
            ->groupByRaw('DATE(created_at)')
            ->pluck('tally', 'slot')
            ->map(fn ($tally): int => (int) $tally)
            ->all();
    }

    /**
     * Approved agents who moved money inside a window.
     */
    private function activeAgents(Carbon $from, Carbon $to): int
    {
        return AgentOperation::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('agent_id', Agent::query()->where('status', AgentStatus::APPROVED)->select('id'))
            ->distinct()
            ->count('agent_id');
    }

    /**
     * @param  list<string>       $dates
     * @param  array<string, int> $byDay
     * @return list<int>
     */
    private function seriesFor(array $dates, array $byDay): array
    {
        return array_map(static fn (string $date): int => $byDay[$date] ?? 0, $dates);
    }

    /**
     * A running total across the window, opening at what existed before it.
     *
     * @param  list<string>       $dates
     * @param  array<string, int> $byDay
     * @return list<int>
     */
    private function cumulativeFor(array $dates, array $byDay, int $opening): array
    {
        $running = $opening;
        $series  = [];

        foreach ($dates as $date) {
            $running += $byDay[$date] ?? 0;
            $series[] = $running;
        }

        return $series;
    }

    /**
     * One share per day: the numerator's running total over the denominator's.
     *
     * @param  list<string>       $dates
     * @param  array<string, int> $numeratorByDay
     * @param  array<string, int> $denominatorByDay
     * @return list<float>
     */
    private function shareSeries(array $dates, array $numeratorByDay, int $numeratorOpening, array $denominatorByDay, int $denominatorOpening): array
    {
        $top    = $numeratorOpening;
        $bottom = $denominatorOpening;
        $series = [];

        foreach ($dates as $date) {
            $top    += $numeratorByDay[$date]   ?? 0;
            $bottom += $denominatorByDay[$date] ?? 0;

            $series[] = $bottom > 0 ? round($top / $bottom * 100, 1) : 0.0;
        }

        return $series;
    }

    /**
     * @return array{text: string, direction: string}|null
     */
    private function percentChip(?float $percent): ?array
    {
        if ($percent === null) {
            return null;
        }

        /*
         * A change that rounds away is no change. Signing it anyway prints
         * "−0.0%", which reads as a fall and is not one.
         */
        $rounded = round($percent, 1);

        return [
            'text'      => ($rounded > 0 ? '+' : ($rounded < 0 ? '−' : '')).number_format(abs($rounded), 1).'%',
            'direction' => $rounded > 0 ? 'up' : ($rounded < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * @return array{text: string, direction: string}|null
     */
    private function pointChip(?float $points): ?array
    {
        if ($points === null) {
            return null;
        }

        // See percentChip(): a change that rounds away is no change.
        $rounded = round($points, 1);

        return [
            'text'      => ($rounded > 0 ? '+' : ($rounded < 0 ? '−' : '')).number_format(abs($rounded), 1).' '.__('pt'),
            'direction' => $rounded > 0 ? 'up' : ($rounded < 0 ? 'down' : 'flat'),
        ];
    }

    private function signed(int $value): string
    {
        return ($value >= 0 ? '+' : '−').number_format(abs($value));
    }

    private function baseCode(): string
    {
        return strtoupper((string) (siteCurrency('code') ?: config('app.default_currency', 'USD')));
    }

    /**
     * Percentage change, or null when there is nothing honest to report.
     *
     * A prior period of zero has no percentage: the answer is "new", not
     * "+100%" and certainly not division by zero.
     */
    public function deltaPercent(float $current, float $previous, bool $hasHistory): ?float
    {
        if (! $hasHistory || $previous == 0.0) {
            return null;
        }

        return ($current - $previous) / abs($previous) * 100;
    }

    /**
     * @return list<string>
     */
    private function recentDates(int $days): array
    {
        $dates = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $dates[] = now()->subDays($offset)->toDateString();
        }

        return $dates;
    }
}
