<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AmountFlow;
use App\Enums\KycStatus;
use App\Enums\TicketStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\LoginActivity;
use App\Models\Referral;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
use App\Models\Wallet;
use App\Support\DecimalMoney;
use App\Support\Money;
use App\View\Components\PageHeader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The figures the Admin → Users → Manage page draws around one account:
 * the header stats strip, the overview chart, the wallets rail, the
 * security rail and the session count. Every amount is restated in the
 * site's base currency through {@see CurrencyService}, the one place that
 * knows how, and every sum is bcmath through {@see DecimalMoney}.
 *
 * Presentation only. Nothing here reaches the ledger.
 */
final class UserAccountSummaryService
{
    /** @var array<string, int> range key → number of days back */
    public const RANGES = ['7d' => 7, '30d' => 30, '12m' => 365];

    public function __construct(private readonly CurrencyService $currencies) {}

    /**
     * The four cells of the header stats strip.
     *
     * @return list<array{label: string, value: string, delta: string|null, tone: string, note: string, spark: list<float>}>
     */
    public function headerStats(User $user): array
    {
        $now       = CarbonImmutable::now();
        $base      = siteCurrency() ?? null;
        $completed = $this->completedRows($user, $now->subDays(6)->startOfDay());
        $wallets   = $user->wallets()->with('currency')->get();
        $rates     = $this->currencies->ratesByCode(
            $completed->pluck('currency')->merge($wallets->map(fn ($wallet) => $wallet->currency?->code))
        );

        $balance = $wallets->reduce(
            fn (string $carry, $wallet): string => DecimalMoney::add(
                $carry,
                $this->currencies->toBaseAmount($wallet->balance, $wallet->currency?->code, $rates),
                DecimalMoney::CALCULATION_SCALE
            ),
            DecimalMoney::normalize(0)
        );

        $today    = $completed->filter(fn ($row): bool => $row->created_at->greaterThanOrEqualTo($now->startOfDay()));
        $netToday = DecimalMoney::subtract(
            $this->sumBase($today->where('amount_flow', AmountFlow::PLUS), $rates),
            $this->sumBase($today->where('amount_flow', AmountFlow::MINUS), $rates),
            DecimalMoney::CALCULATION_SCALE
        );

        /*
         * Lifetime figures come from one grouped query per kind rather than
         * loading the ledger: a busy account has thousands of rows, and only
         * the per-currency sums need restating in the base currency.
         */
        $lifetime = $this->lifetimeVolume($user);
        $bonuses  = $this->sumGrouped(
            Transaction::query()
                ->where('user_id', $user->id)
                ->where('status', TrxStatus::COMPLETED)
                ->where('trx_type', TrxType::REFERRAL_REWARD)
        );

        $direct   = $user->referrals()->count();
        $indirect = Referral::query()->whereIn('parent_referral_id', $user->referrals()->select('id'))->count();

        $openTicketQuery = fn () => $user->tickets()->where('status', '!=', TicketStatus::CLOSED->value);
        $openTickets     = $openTicketQuery()->count();
        $newTickets      = $openTicketQuery()->where('created_at', '>=', $now->subDays(7))->count();
        $awaitingReply   = $user->tickets()->whereIn('status', [TicketStatus::OPEN->value, TicketStatus::REPLIED->value])->exists();
        $netSign         = DecimalMoney::compare($netToday, '0');

        return [
            [
                'label' => __('Total balance'),
                'value' => money($balance, $base),
                'delta' => $netSign === 0 ? null : ($netSign > 0 ? '+' : '−').money(ltrim($netToday, '-'), $base),
                'tone'  => $netSign > 0 ? 'success' : ($netSign < 0 ? 'warning' : 'neutral'),
                'note'  => __(':count wallets', ['count' => $wallets->count()]),
                'spark' => $this->dailySums($completed, $rates, $now, null),
            ],
            [
                'label' => __('Lifetime volume'),
                'value' => Money::compact($lifetime['total'], $base),
                'delta' => __(':count tx', ['count' => PageHeader::formatNumber($lifetime['count'])]),
                'tone'  => 'brand',
                'note'  => __('avg. :amount / month', ['amount' => Money::compact($lifetime['per_month'], $base)]),
                'icon'  => 'money',
            ],
            [
                'label' => __('Referrals'),
                'value' => $direct + $indirect,
                'delta' => __(':count direct', ['count' => $direct]),
                'tone'  => 'brand',
                'note'  => __('earned :amount in bonuses', ['amount' => Money::compact($bonuses, $base)]),
                'icon'  => 'users-1',
            ],
            [
                'label' => __('Open tickets'),
                'value' => (string) $openTickets,
                'delta' => $newTickets  > 0 ? __(':count new', ['count' => $newTickets]) : null,
                'tone'  => $openTickets > 0 ? 'warning' : 'neutral',
                'note'  => $awaitingReply ? __('awaiting reply') : __('this week'),
                'icon'  => 'support',
            ],
        ];
    }

    /**
     * Every completed transaction the account ever made, in the base
     * currency, with the count and the monthly average since the first one.
     *
     * @return array{total: string, count: int, per_month: string}
     */
    private function lifetimeVolume(User $user): array
    {
        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox();

        $total = $this->sumGrouped(clone $query);
        $count = (clone $query)->count();
        $first = (clone $query)->min('created_at');

        $months = $first === null ? 1 : max(1, (int) CarbonImmutable::parse($first)->diffInMonths(CarbonImmutable::now()) + 1);

        return [
            'total'     => $total,
            'count'     => $count,
            'per_month' => DecimalMoney::divide($total, (string) $months),
        ];
    }

    /**
     * SUM(amount) per currency, restated in the base currency and added up.
     *
     * @param Builder<Transaction> $query
     */
    private function sumGrouped($query): string
    {
        $rows  = $query->selectRaw('currency, SUM(amount) as amount')->groupBy('currency')->get();
        $rates = $this->currencies->ratesByCode($rows->pluck('currency'));

        return $this->currencies->sumInBaseCurrency($rows, 'amount', $rates);
    }

    /**
     * The transaction summary card: money in, money out and the grouped
     * bars for one of {@see RANGES}.
     *
     * @return array{range: string, money_in: string, money_out: string, buckets: list<array{label: string, in: int, out: int}>}
     */
    public function overview(User $user, string $range): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : '30d';
        $now   = CarbonImmutable::now();
        $base  = siteCurrency() ?? null;

        /* 7d → one bucket a day; 30d → three-day buckets labelled by start date; 12m → months. */
        [$buckets, $bucketOf] = match ($range) {
            '7d'    => [$this->dayBuckets($now, 7), fn (CarbonImmutable $at): int => 6 - (int) $at->startOfDay()->diffInDays($now->startOfDay())],
            '12m'   => [$this->monthBuckets($now, 12), fn (CarbonImmutable $at): int => 11 - (($now->year - $at->year) * 12 + ($now->month - $at->month))],
            default => [$this->dayBuckets($now, 30, 3), fn (CarbonImmutable $at): int => intdiv(29 - (int) $at->startOfDay()->diffInDays($now->startOfDay()), 3)],
        };

        $from  = $range === '12m' ? $now->subMonths(11)->startOfMonth() : $now->subDays(self::RANGES[$range] - 1)->startOfDay();
        $rows  = $this->completedRows($user, $from);
        $rates = $this->currencies->ratesByCode($rows->pluck('currency'));

        $sums = array_fill(0, count($buckets), ['in' => DecimalMoney::normalize(0), 'out' => DecimalMoney::normalize(0)]);

        foreach ($rows as $row) {
            $index = $bucketOf(CarbonImmutable::instance($row->created_at));

            if (! isset($sums[$index])) {
                continue;
            }

            $side                = $row->amount_flow === AmountFlow::MINUS ? 'out' : 'in';
            $sums[$index][$side] = DecimalMoney::add(
                $sums[$index][$side],
                $this->currencies->toBaseAmount($row->amount, $row->currency, $rates),
                DecimalMoney::CALCULATION_SCALE
            );
        }

        $peak = 0.0;

        foreach ($sums as $sum) {
            $peak = max($peak, (float) $sum['in'], (float) $sum['out']);
        }

        /*
         * The axis: a "nice" step of 1, 2, 2.5 or 5 × 10ⁿ from a quarter of
         * the peak, then as many ticks as it takes to clear the peak. Bars are
         * drawn against the top tick, so the tallest bar never touches the
         * card edge and every gridline carries a round label.
         */
        $step      = $peak      > 0 ? self::niceStep($peak / 4) : 1.0;
        $tickCount = $peak      > 0 ? (int) ceil($peak / $step) : 0;
        $topTick   = $tickCount > 0 ? $tickCount * $step : 1.0;
        $ticks     = [];

        for ($i = $tickCount; $i >= 0; $i--) {
            $ticks[] = self::tickLabel($i * $step, $base);
        }

        $percent = static fn (string $value): int => (float) $value > 0
            ? max(2, (int) round((float) $value / $topTick * 100))
            : 0;

        $inTotal  = $this->sumBase($rows->where('amount_flow', AmountFlow::PLUS), $rates);
        $outTotal = $this->sumBase($rows->where('amount_flow', AmountFlow::MINUS), $rates);
        $net      = DecimalMoney::subtract($inTotal, $outTotal, DecimalMoney::CALCULATION_SCALE);
        $lastIdx  = count($buckets) - 1;

        return [
            'range'     => $range,
            'labels'    => $buckets,
            'in'        => array_map(static fn (array $sum): float => round((float) $sum['in'], 2), $sums),
            'out'       => array_map(static fn (array $sum): float => round((float) $sum['out'], 2), $sums),
            'in_total'  => '+'.money($inTotal, $base),
            'out_total' => '−'.money($outTotal, $base),
            'net'       => (DecimalMoney::compare($net, '0') < 0 ? '−' : '+').money(ltrim($net, '-'), $base),
            'in_note'   => match ($range) {
                '7d'    => __('across :count days', ['count' => count($buckets)]),
                '12m'   => __('deposits, transfers in, exchanges'),
                default => __('across :count periods', ['count' => count($buckets)]),
            },
            'out_note' => __('withdrawals, payments, transfers out'),
            'net_note' => match ($range) {
                '7d'    => __('this week'),
                '12m'   => __('last 12 months'),
                default => __('last 30 days'),
            },
            'ticks' => $ticks,
            'bars'  => array_map(
                fn (int $i, string $label, array $sum): array => [
                    'label'   => $label,
                    'in'      => $percent($sum['in']),
                    'out'     => $percent($sum['out']),
                    'tip'     => $label.' · '.__('in :amount', ['amount' => money($sum['in'], $base)]).' · '.__('out :amount', ['amount' => money($sum['out'], $base)]),
                    'current' => $i === $lastIdx,
                ],
                array_keys($buckets),
                $buckets,
                $sums
            ),
        ];
    }

    /**
     * "$500 · $1k · $1.5k · $2.5k · $15k": thousands compact, smaller ticks
     * whole — a tick never carries cents.
     */
    private static function tickLabel(float $value, ?string $currency): string
    {
        if ($value >= 1000) {
            return Money::compact($value, $currency);
        }

        return (string) preg_replace('/[.,]0+(?=\D|$)/', '', money($value, $currency));
    }

    /**
     * The chart's tick step: 1, 2, 2.5 or 5 times a power of ten, whichever
     * first covers the raw quarter-of-peak value.
     */
    public static function niceStep(float $raw): float
    {
        $power    = 10 ** floor(log10($raw));
        $mantissa = $raw / $power;

        $nice = match (true) {
            $mantissa <= 1   => 1,
            $mantissa <= 2   => 2,
            $mantissa <= 2.5 => 2.5,
            $mantissa <= 5   => 5,
            default          => 10,
        };

        return $nice * $power;
    }

    /**
     * @return EloquentCollection<int, Transaction>
     */
    public function recentTransactions(User $user, int $limit = 5): EloquentCollection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The wallets rail: every active wallet with its last movement, plus the
     * base-currency total the header line states.
     *
     * @return array{total: string, rows: list<array{wallet: Wallet, recent: string|null, recent_tone: string}>}
     */
    public function wallets(User $user): array
    {
        $wallets = $user->activeWallets()->load('currency');
        $rates   = $this->currencies->ratesByCode($wallets->map(fn ($wallet) => $wallet->currency?->code));

        $total = $wallets->reduce(
            fn (string $carry, $wallet): string => DecimalMoney::add(
                $carry,
                $this->currencies->toBaseAmount($wallet->balance, $wallet->currency?->code, $rates),
                DecimalMoney::CALCULATION_SCALE
            ),
            DecimalMoney::normalize(0)
        );

        $rows = $wallets->map(function ($wallet): array {
            $latest = $wallet->latestTransaction;

            if ($latest === null) {
                return ['wallet' => $wallet, 'recent' => null, 'recent_tone' => 'neutral'];
            }

            $sign = $latest->amount_flow->sign($latest->status);

            return [
                'wallet'      => $wallet,
                'recent'      => $sign.money($latest->amount, $wallet->currency?->code).' '.Str::lower($latest->trx_type->label()).' · '.$latest->created_at->diffForHumans(),
                'recent_tone' => $latest->status !== TrxStatus::COMPLETED ? 'neutral' : ($latest->amount_flow === AmountFlow::MINUS ? 'out' : 'in'),
            ];
        })->values()->all();

        return ['total' => money($total, siteCurrency() ?? null), 'rows' => $rows];
    }

    /**
     * The customer's savings vaults for the Vaults tab, open ones first.
     *
     * One query with the wallet and its currency; every figure is worked out
     * in memory, never by asking each vault for more.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function vaultRows(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $user->vaults()
            ->with('wallet.currency')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (Vault $vault): array => $this->vaultRow($vault));
    }

    /**
     * One vault as the tab shows it. The figures are formatted here; the
     * sentences they sit in are the view's, so each figure can be isolated
     * from the language around it.
     *
     * @return array{name: string, code: string|null, balance: string, target: string|null, target_date: string|null, progress: int|null, status: string, status_label: string, tone: string, auto: array{frequency: string, amount: string, day: string}|null, round_up: string|null, next: string|null, opened: string}
     */
    private function vaultRow(Vault $vault): array
    {
        $code   = $vault->wallet?->currency?->code ?: null;
        $amount = fn (mixed $value): string => $code === null ? DecimalMoney::normalize((string) $value) : money((string) $value, $code);

        // Rounded the way VaultService::describe() rounds it for the customer,
        // so the admin and the customer read the same percentage.
        $target   = $vault->target_amount !== null && DecimalMoney::compare((string) $vault->target_amount, '0') > 0 ? (string) $vault->target_amount : null;
        $progress = $target === null
            ? null
            : min(100, (int) DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide((string) $vault->balance, $target), '100'), 0));

        [$label, $tone] = match ($vault->status) {
            'active' => [__('Active'), 'success'],
            'paused' => [__('Paused'), 'warning'],
            'closed' => [__('Closed'), 'neutral'],
            default  => [Str::headline((string) $vault->status), 'neutral'],
        };

        $auto = null;
        if ($vault->auto_amount !== null && $vault->auto_frequency === 'weekly') {
            // auto_day is the ISO weekday, 1 (Monday) to 7 (Sunday), as
            // VaultService::nextAutoAt() reads it. The week is anchored on
            // Monday explicitly: left to the locale, an Arabic week starts on
            // Saturday and every rule would name the wrong day.
            $day  = CarbonImmutable::now()->startOfWeek(CarbonInterface::MONDAY)->addDays(max(1, min(7, (int) $vault->auto_day)) - 1)->translatedFormat('l');
            $auto = ['frequency' => 'weekly', 'amount' => $amount($vault->auto_amount), 'day' => $day];
        } elseif ($vault->auto_amount !== null && $vault->auto_frequency === 'monthly') {
            $auto = ['frequency' => 'monthly', 'amount' => $amount($vault->auto_amount), 'day' => (string) max(1, min(28, (int) $vault->auto_day))];
        }

        return [
            'name'         => (string) $vault->name,
            'code'         => $code,
            'balance'      => $amount($vault->balance),
            'target'       => $target === null ? null : $amount($target),
            'target_date'  => $vault->target_date?->format('d M Y'),
            'progress'     => $progress,
            'status'       => (string) $vault->status,
            'status_label' => $label,
            'tone'         => $tone,
            'auto'         => $auto,
            'round_up'     => $vault->round_ups && $vault->round_up_to !== null ? $amount($vault->round_up_to) : null,
            'next'         => $vault->status === 'active' ? $vault->next_auto_at?->format('d M Y') : null,
            'opened'       => $vault->created_at?->format('d M Y') ?? '',
        ];
    }

    /**
     * The security rail: KYC, 2FA, last sign-in and failed attempts, with an
     * overall reading of Good / Fair / At risk.
     *
     * @return array{overall: array{label: string, tone: string}, rows: list<array{icon: string, label: string, sub: string, value: string, tone: string}>}
     */
    public function security(User $user): array
    {
        $submission = $user->kycSubmission;
        $kycTone    = match ($submission?->status) {
            KycStatus::APPROVED => 'success',
            KycStatus::REJECTED => 'danger',
            KycStatus::PENDING  => 'warning',
            default             => 'neutral',
        };
        $kycSub = $submission === null
            ? __('No documents submitted')
            : ($submission->kycTemplate?->title ?? __('Documents')).' · '.$submission->created_at->format('d M');
        $kycValue = match ($submission?->status) {
            KycStatus::APPROVED => __('Verified'),
            KycStatus::PENDING  => __('KYC pending'),
            KycStatus::REJECTED => __('Rejected'),
            default             => __('Not started'),
        };

        /* The devices seen signing in over the last 30 days, as "Chrome · Windows". */
        $devices = LoginActivity::query()
            ->where('user_id', $user->id)
            ->where('succeeded', true)
            ->where('login_at', '>=', now()->subDays(30))
            ->get(['browser', 'platform'])
            ->map(fn (LoginActivity $row): string => implode(' · ', array_filter(
                [$row->browser, $row->platform],
                static fn (?string $part): bool => $part !== null && $part !== '' && strcasecmp($part, 'unknown') !== 0
            )))
            ->filter()
            ->unique()
            ->values();

        $failed = LoginActivity::query()
            ->where('user_id', $user->id)
            ->where('succeeded', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $twoFactor = (bool) $user->two_factor_enabled;
        $score     = ($submission?->status === KycStatus::APPROVED ? 1 : 0) + ($twoFactor ? 1 : 0) + ($failed === 0 ? 1 : 0);

        return [
            'overall' => match (true) {
                $score >= 3  => ['label' => __('Good'), 'tone' => 'success'],
                $score === 2 => ['label' => __('Fair'), 'tone' => 'warning'],
                default      => ['label' => __('At risk'), 'tone' => 'danger'],
            },
            'rows' => [
                [
                    'icon'  => 'kyc',
                    'label' => __('Identity'),
                    'sub'   => $kycSub,
                    'value' => $kycValue,
                    'tone'  => $kycTone,
                ],
                [
                    'icon'  => 'shield',
                    'label' => __('Two-factor'),
                    'sub'   => $twoFactor ? __('Authenticator app') : __('Not set up'),
                    'value' => $twoFactor ? __('Enabled') : __('Off'),
                    'tone'  => $twoFactor ? 'success' : 'warning',
                ],
                [
                    'icon'  => 'login',
                    'label' => __('Sessions'),
                    'sub'   => $devices->isEmpty()
                        ? __('No sign-ins in the last 30 days')
                        : $devices->take(3)->implode(' · '),
                    'value' => __(':count devices', ['count' => $devices->count()]),
                    'tone'  => 'neutral',
                ],
                [
                    'icon'  => 'lock',
                    'label' => __('Failed logins'),
                    'sub'   => __('Lockout after :count tries', ['count' => max(3, min(10, (int) config('security.login_attempt_limit', 5)))]),
                    'value' => __(':count in 30 days', ['count' => $failed]),
                    'tone'  => $failed === 0 ? 'neutral' : ($failed >= 5 ? 'danger' : 'warning'),
                ],
            ],
        ];
    }

    /**
     * Tab counters: transactions, referrals and tickets.
     *
     * @return array{transactions: int, referrals: int, tickets: int, open_tickets: int}
     */
    public function counts(User $user): array
    {
        return [
            'transactions' => $user->transactions()->count(),
            'referrals'    => $user->referrals()->count(),
            'tickets'      => $user->tickets()->count(),
            'open_tickets' => $user->tickets()->where('status', '!=', TicketStatus::CLOSED->value)->count(),
        ];
    }

    /**
     * Live web sessions plus API tokens. Web sessions are only countable on
     * the database driver; elsewhere the tokens are all there is to count.
     */
    public function sessionCount(User $user): int
    {
        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->count();

        if (config('session.driver') !== 'database') {
            return $tokens;
        }

        $lifetimeSeconds = (int) config('session.lifetime', 120) * 60;

        try {
            $sessions = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('last_activity', '>=', now()->getTimestamp() - $lifetimeSeconds)
                ->count();
        } catch (Throwable) {
            $sessions = 0;
        }

        return $sessions + $tokens;
    }

    /**
     * End every session and API token this user holds and rotate the
     * remember token so a closed browser cannot walk back in.
     *
     * @return int sessions and tokens revoked
     */
    public function revokeSessions(User $user): int
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $revoked = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->delete();

        try {
            $revoked += DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        } catch (Throwable) {
            /* No sessions table on this driver — the token rotation is the revoke. */
        }

        return $revoked;
    }

    /**
     * End the one session a single sign-in opened.
     *
     * The Activity history drawer offers this for a sign-in it considers
     * routine, where signing the account out of every device would punish
     * the account holder for the operator's curiosity. Only the session row
     * recorded against that arrival is deleted; tokens and the remember
     * token are left alone for the same reason.
     *
     * @return int sessions revoked — 0 when this driver keeps no session rows
     */
    public function revokeSession(LoginActivity $activity): int
    {
        if ($activity->session_id === null || config('session.driver') !== 'database') {
            return 0;
        }

        try {
            return DB::table(config('session.table', 'sessions'))
                ->where('id', $activity->session_id)
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * End every customer session on the platform.
     *
     * Administrator sessions are deliberately spared: the operator running
     * this is in one, and a "sign everybody out" that also signs out the
     * person who pressed it — and every colleague handling the same incident
     * — turns a containment action into an outage.
     *
     * @return int sessions and tokens revoked
     */
    public function revokeAllUserSessions(): int
    {
        $revoked = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->delete();

        try {
            $revoked += DB::table(config('session.table', 'sessions'))
                ->whereNotNull('user_id')
                ->delete();
        } catch (Throwable) {
            /* No sessions table on this driver — the tokens are the revoke. */
        }

        return $revoked;
    }

    /**
     * Withdrawals still waiting on approval, for the suspension note.
     *
     * @return array{count: int, total: string}
     */
    public function pendingWithdrawals(User $user): array
    {
        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::WITHDRAW)
            ->where('status', TrxStatus::PENDING)
            ->get(['amount', 'currency']);

        return [
            'count' => $rows->count(),
            'total' => money($this->currencies->sumInBaseCurrency($rows, 'amount'), siteCurrency() ?? null),
        ];
    }

    /**
     * Completed rows whose money moved: a sandbox payment's never did. The
     * scope has to be on the query - remarks and trx_data are not loaded.
     *
     * @return Collection<int, Transaction>
     */
    private function completedRows(User $user, CarbonImmutable $from): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'amount', 'currency', 'amount_flow', 'trx_type'])
            ->collect();
    }

    /**
     * @param Collection<int, Transaction> $rows
     */
    private function sumBase(Collection $rows, Collection $rates): string
    {
        return $this->currencies->sumInBaseCurrency($rows->values(), 'amount', $rates);
    }

    /**
     * Seven daily totals ending today, as the shape the sparkline draws.
     *
     * @param  Collection<int, Transaction> $rows
     * @return list<float>
     */
    private function dailySums(Collection $rows, Collection $rates, CarbonImmutable $now, ?TrxType $type): array
    {
        $days = array_fill(0, 7, DecimalMoney::normalize(0));

        foreach ($rows as $row) {
            if ($type !== null && $row->trx_type !== $type) {
                continue;
            }

            $index = 6 - (int) CarbonImmutable::instance($row->created_at)->startOfDay()->diffInDays($now->startOfDay());

            if ($index < 0 || $index > 6) {
                continue;
            }

            $days[$index] = DecimalMoney::add($days[$index], $this->currencies->toBaseAmount($row->amount, $row->currency, $rates), DecimalMoney::CALCULATION_SCALE);
        }

        return array_map(static fn (string $value): float => (float) $value, $days);
    }

    /**
     * @param  Collection<int, mixed> $timestamps
     * @return list<float>
     */
    private function dailyCounts(Collection $timestamps, CarbonImmutable $now): array
    {
        $days = array_fill(0, 7, 0.0);

        foreach ($timestamps as $timestamp) {
            $index = 6 - (int) CarbonImmutable::parse($timestamp)->startOfDay()->diffInDays($now->startOfDay());

            if ($index >= 0 && $index <= 6) {
                $days[$index]++;
            }
        }

        return $days;
    }

    /**
     * @return list<string>
     */
    private function dayBuckets(CarbonImmutable $now, int $days, int $size = 1): array
    {
        $labels = [];

        for ($start = $days - 1; $start >= 0; $start -= $size) {
            $labels[] = $now->subDays($start)->format($size === 1 ? 'D' : 'M j');
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function monthBuckets(CarbonImmutable $now, int $months): array
    {
        $labels = [];

        for ($back = $months - 1; $back >= 0; $back--) {
            $labels[] = $now->subMonths($back)->format('M');
        }

        return $labels;
    }
}
