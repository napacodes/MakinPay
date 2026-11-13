<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Constants\CurrencyType;
use App\Enums\TrxStatus;
use App\Models\Currency;
use App\Models\DepositMethod;
use App\Models\PaymentGateway;
use App\Models\Plugin;
use App\Models\Transaction;
use App\Models\WithdrawMethod;
use App\Services\CurrencyService;
use App\Support\RateDivergence;
use App\Support\RateGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Live payment-gateway health, read out of the ledger.
 *
 * Deliberately derived from transactions rather than from new "last webhook"
 * columns on payment_gateways: that would mean editing every IPN and callback
 * controller in a production payments platform to power a dashboard panel,
 * and a settlement path is not somewhere to add a write for a status light.
 * What the ledger already records — completed and failed attempts per gateway,
 * with timestamps — answers the same question without touching that code.
 *
 * Cached for a minute. The panel has its own endpoint so it can fail and be
 * retried without the rest of the page waiting on it.
 */
class GatewayHealthService
{
    public const STATUS_OPERATIONAL = 'operational';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_DOWN = 'down';

    public const STATUS_UNKNOWN = 'unknown';

    private const CACHE_KEY = 'dashboard.gateway_health';

    private const CACHE_SECONDS = 60;

    /**
     * A gateway with no completed transaction for this long, and at least one
     * failure, is treated as down rather than quiet.
     */
    private const SILENCE_MINUTES = 30;

    private const WINDOW_MINUTES = 60;

    private const DEGRADED_FAILURE_RATE = 0.2;

    public function __construct(private readonly CurrencyService $currencies) {}

    /**
     * @return array{
     *     gateways: list<array<string, mixed>>,
     *     connected: int,
     *     healthy: int,
     *     degraded: int,
     *     down: int,
     *     generated_at: string
     * }
     */
    public function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $gateways = $this->buildGateways();

            return [
                'gateways'     => $gateways->all(),
                'connected'    => $gateways->where('kind', 'gateway')->count(),
                'healthy'      => $gateways->where('kind', 'gateway')->where('status', self::STATUS_OPERATIONAL)->count(),
                'degraded'     => $gateways->where('kind', 'gateway')->where('status', self::STATUS_DEGRADED)->count(),
                'down'         => $gateways->where('kind', 'gateway')->where('status', self::STATUS_DOWN)->count(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildGateways(): Collection
    {
        /*
         * Only gateways an operator has actually configured. Two are inserted
         * already enabled by migrations, with placeholder credentials; listing
         * those as operational would tell a brand-new site its payments are
         * healthy when it cannot accept a deposit.
         */
        $gateways = PaymentGateway::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->filter(fn (PaymentGateway $gateway): bool => $gateway->isConfigured())
            ->values();

        // The rate checks are platform plumbing, not a third party, so they
        // report whether or not a gateway has been configured yet.
        $rateRows = collect([
            $this->blockedCurrencyRow(),
            $this->rateProviderRow(),
            $this->methodRateRow(),
        ])->filter()->values();

        if ($gateways->isEmpty()) {
            return $rateRows;
        }

        $codes    = $gateways->pluck('code')->filter()->values();
        $window   = now()->subMinutes(self::WINDOW_MINUTES);
        $attempts = $this->attemptsByGateway($codes, $window);
        $volumes  = $this->volumeByGateway($codes);

        return $gateways
            ->map(function (PaymentGateway $gateway) use ($attempts, $volumes): array {
                $code   = (string) $gateway->code;
                $stats  = $attempts[$code] ?? ['completed' => 0, 'failed' => 0, 'last_success' => null];
                $status = $this->classify($stats);

                return [
                    'kind'         => 'gateway',
                    'code'         => $code,
                    'name'         => $gateway->name,
                    'status'       => $status,
                    'note'         => $this->note($status, $stats),
                    'volume_base'  => (float) ($volumes[$code] ?? 0.0),
                    'last_success' => $stats['last_success']?->toIso8601String(),
                ];
            })
            ->values()
            ->concat($rateRows);
    }

    /**
     * @param array{completed: int, failed: int, last_success: Carbon|null} $stats
     */
    private function classify(array $stats): string
    {
        $total = $stats['completed'] + $stats['failed'];

        if ($stats['failed'] === 0) {
            return self::STATUS_OPERATIONAL;
        }

        $silentSince = $stats['last_success'];

        if ($silentSince === null || $silentSince->lt(now()->subMinutes(self::SILENCE_MINUTES))) {
            return self::STATUS_DOWN;
        }

        return $stats['failed'] / max(1, $total) > self::DEGRADED_FAILURE_RATE
            ? self::STATUS_DEGRADED
            : self::STATUS_OPERATIONAL;
    }

    /**
     * @param array{completed: int, failed: int, last_success: Carbon|null} $stats
     */
    private function note(string $status, array $stats): string
    {
        $total = $stats['completed'] + $stats['failed'];

        if ($total === 0) {
            return __('No traffic in the last hour');
        }

        return match ($status) {
            self::STATUS_DOWN => trans_choice(
                '{1}:count failed attempt, none completed since :since|[2,*]:count failed attempts, none completed since :since',
                $stats['failed'],
                ['count' => $stats['failed'], 'since' => $stats['last_success']?->diffForHumans() ?? __('the last hour')]
            ),
            self::STATUS_DEGRADED => __(':failed of :total attempts failed in the last hour', [
                'failed' => $stats['failed'],
                'total'  => $total,
            ]),
            default => trans_choice(
                '{1}:count completed attempt in the last hour|[2,*]:count completed attempts in the last hour',
                $stats['completed'],
                ['count' => $stats['completed']]
            ),
        };
    }

    /**
     * @param  Collection<int, string>                                                      $codes
     * @return array<string, array{completed: int, failed: int, last_success: Carbon|null}>
     */
    private function attemptsByGateway(Collection $codes, Carbon $window): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        $rows = Transaction::query()
            ->whereIn('gateway_code', $codes)
            ->whereIn('status', [TrxStatus::COMPLETED, TrxStatus::FAILED])
            ->where('created_at', '>=', $window)
            ->selectRaw('gateway_code, status, COUNT(*) as attempts, MAX(created_at) as last_at')
            ->groupBy('gateway_code', 'status')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $code = (string) $row->gateway_code;
            $stats[$code] ??= ['completed' => 0, 'failed' => 0, 'last_success' => null];

            if ($row->status === TrxStatus::COMPLETED) {
                $stats[$code]['completed']    = (int) $row->attempts;
                $stats[$code]['last_success'] = $row->last_at === null ? null : Carbon::parse($row->last_at);

                continue;
            }

            $stats[$code]['failed'] = (int) $row->attempts;
        }

        return $stats;
    }

    /**
     * 24-hour completed volume per gateway, restated into the base currency.
     *
     * @param  Collection<int, string> $codes
     * @return array<string, float>
     */
    private function volumeByGateway(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        $rows = Transaction::query()
            ->whereIn('gateway_code', $codes)
            ->where('status', TrxStatus::COMPLETED)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('gateway_code, currency, SUM(amount) as amount')
            ->groupBy('gateway_code', 'currency')
            ->get();

        $rates = $this->currencies->ratesByCode($rows->pluck('currency'));

        return $rows
            ->groupBy('gateway_code')
            ->map(fn (Collection $group): float => (float) $this->currencies->sumInBaseCurrency($group, 'amount', $rates))
            ->all();
    }

    /**
     * Exchange-rate freshness sits in the same panel: a stale rate silently
     * misprices every cross-currency figure above it on the page.
     *
     * @return array<string, mixed>
     */
    private function rateProviderRow(): array
    {
        /*
         * A provider that does not exist outranks how fresh the feed is: there
         * is nothing to refresh from, so no reading this row could show would
         * mean anything. Staleness of an existing feed is measured below.
         */
        if ($this->rateProviderMissing()) {
            return [
                'kind'         => 'rates',
                'code'         => 'exchange_rates',
                'name'         => __('Exchange rates'),
                'status'       => self::STATUS_DOWN,
                'note'         => __('Crypto is enabled with no rate provider'),
                'volume_base'  => null,
                'volume_label' => __('no provider'),
                'last_success' => null,
                'link'         => 'plugin',
            ];
        }

        // Payment methods go on live rates the same way currencies do, and
        // `currency:refresh-rates` refreshes both — its own "nothing to do"
        // test counts both. Counting only currencies here let an install whose
        // live pricing came entirely from deposit or withdraw methods read
        // "Manual rates — no live provider" in green, while the scheduler was
        // fetching those rates every fifteen minutes and a dead feed had
        // nowhere to show up.
        $live = Currency::query()->where('rate_live', true)->count()
            + DepositMethod::query()->where('conversion_rate_live', true)->count()
            + WithdrawMethod::query()->where('conversion_rate_live', true)->count();

        if ($live === 0) {
            return [
                'kind'         => 'rates',
                'code'         => 'exchange_rates',
                'name'         => __('Exchange rates'),
                'status'       => self::STATUS_OPERATIONAL,
                'note'         => __('Manual rates — no live provider'),
                'volume_base'  => null,
                'volume_label' => trans_choice('{1}:count currency|[2,*]:count currencies', $live, ['count' => $live]),
                'last_success' => null,
            ];
        }

        // The newest stamp across everything on live rates. `max` rather than
        // `min` on purpose: this row answers "is the feed running", and one
        // currency the provider cannot price should not report the whole feed
        // dead. A method that has never been refreshed carries NULL and is
        // simply absent from the comparison.
        $updatedAt = $this->newestRateStamp(array_filter([
            Currency::query()->where('rate_live', true)->max('rate_updated_at'),
            DepositMethod::query()->where('conversion_rate_live', true)->max('conversion_rate_updated_at'),
            WithdrawMethod::query()->where('conversion_rate_live', true)->max('conversion_rate_updated_at'),
        ]));

        $stale = $updatedAt === null || $updatedAt->lt(now()->subDay());

        return [
            'kind'   => 'rates',
            'code'   => 'exchange_rates',
            'name'   => __('Exchange rates'),
            'status' => $stale ? self::STATUS_DEGRADED : self::STATUS_OPERATIONAL,
            'note'   => $updatedAt === null
                ? __('Never refreshed')
                : __('Refreshed :ago', ['ago' => $updatedAt->diffForHumans()]),
            'volume_base'  => null,
            'volume_label' => trans_choice('{1}:count live pair|[2,*]:count live pairs', $live, ['count' => $live]),
            'last_success' => $updatedAt?->toIso8601String(),
        ];
    }

    /**
     * Currencies the rate guard will not convert right now.
     *
     * Deliberately a row of its own rather than a verdict folded into the
     * exchange-rate row above. That row answers "is the feed running", and
     * answers it with the newest reading across every source, so one currency
     * lagging must not report the whole feed dead. This asks a different
     * question — "can this currency be converted at all" — and a single stale
     * currency does block every deposit, withdrawal and exchange in it while
     * the feed keeps running perfectly for the others. Two questions, two
     * answers, two rows.
     *
     * @return array<string, mixed>|null
     */
    private function blockedCurrencyRow(): ?array
    {
        $blocked = $this->unpriceableCurrencies();

        if ($blocked === 0) {
            return null;
        }

        return [
            'kind'   => 'blocked_rates',
            'code'   => 'blocked_rates',
            'name'   => __('Currency pricing'),
            'status' => self::STATUS_DOWN,
            'note'   => trans_choice(
                '{1}:count currency cannot be converted|[2,*]:count currencies cannot be converted',
                $blocked,
                ['count' => $blocked]
            ),
            'volume_base'  => null,
            'volume_label' => trans_choice('{1}:count blocked|[2,*]:count blocked', $blocked, ['count' => $blocked]),
            'last_success' => null,
            'link'         => 'currency',
        ];
    }

    /**
     * A payment method priced away from the currency it settles in charges a
     * rate nobody set on purpose: the same deposit costs a different amount
     * depending on which button the customer presses.
     *
     * Absent rather than green when there is nothing to report. A rail that
     * lists every check which passed buries the ones that did not, which is
     * the same reason an idle gateway is counted here instead of listed.
     *
     * @return array<string, mixed>|null
     */
    private function methodRateRow(): ?array
    {
        try {
            $divergent = app(RateDivergence::class)->beyondTolerance();
        } catch (Throwable) {
            // A method table that cannot be read is not a divergence. The
            // panel drops one row rather than the dashboard returning a 500.
            return null;
        }

        if ($divergent->isEmpty()) {
            return null;
        }

        $worst = $divergent->first();
        $count = $divergent->count();

        return [
            'kind'   => 'method_rates',
            'code'   => 'method_rates',
            'name'   => __('Method rates'),
            'status' => self::STATUS_DEGRADED,
            'note'   => trans_choice(
                '{1}:code priced away from its currency|[2,*]:count methods priced away from their currency',
                $count,
                ['count' => $count, 'code' => $worst['name']]
            ),
            'volume_base' => null,
            /*
             * A bare number, not a translated phrase. This chip renders
             * `dir="ltr"`, and an Arabic word beside the figure strands the
             * percent sign on the far side of it: "فارق :percent%" laid out
             * as "6.85 فارق %", measured per glyph rather than assumed. A pure
             * LTR run has no neighbour to be reordered around, and the row's
             * name and note already carry the words.
             *
             * Trailing zeros carry no information on a chip this narrow.
             */
            'volume_label' => rtrim(rtrim(number_format((float) $worst['percent'], 2, '.', ''), '0'), '.').'%',
            'last_success' => null,
            'link'         => $worst['table'] === 'withdraw_methods' ? 'withdraw_method' : 'deposit_method',
        ];
    }

    /**
     * Currencies the rate guard will not convert right now — missing, zero,
     * still at the placeholder, or stale on an install that asked for live
     * rates. Every one of them blocks deposits, withdrawals and exchanges.
     */
    private function unpriceableCurrencies(): int
    {
        try {
            $guard = app(RateGuard::class);
        } catch (Throwable) {
            return 0;
        }

        return Currency::query()
            ->where('status', true)
            ->get()
            ->filter(fn (Currency $currency): bool => $guard->reject($currency) !== null)
            ->count();
    }

    /**
     * A non-pegged crypto currency can never be priced correctly without a
     * provider, however right its individual rate happens to look today.
     */
    private function rateProviderMissing(): bool
    {
        $hasCrypto = Currency::query()
            ->where('status', true)
            ->where('type', CurrencyType::CRYPTO)
            ->whereNotIn('code', RateGuard::PEGGED)
            ->exists();

        if (! $hasCrypto) {
            return false;
        }

        return ! Plugin::query()
            ->where('type', 'exchange_rate')
            ->where('status', true)
            ->exists();
    }

    /**
     * The most recent of the timestamps handed in, or null when none of them
     * has ever been priced.
     *
     * @param list<mixed> $stamps
     */
    private function newestRateStamp(array $stamps): ?Carbon
    {
        $parsed = [];

        foreach ($stamps as $stamp) {
            try {
                $parsed[] = Carbon::parse($stamp);
            } catch (Throwable) {
                // An unparseable stamp is not a date. Skipping it degrades to
                // "we have no reading from this source", which the caller
                // already handles, rather than taking the dashboard down.
                continue;
            }
        }

        return $parsed === [] ? null : max($parsed);
    }
}
