<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\UserStatus;
use App\Models\Currency;
use App\Models\IPBlock;
use App\Models\KycDocumentHash;
use App\Models\LoginActivity;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\RateGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Things in the last day that want a person to look at them.
 *
 * Every signal here is derived from rows the platform already writes. Where a
 * pattern cannot be measured from the existing schema it is not reported at
 * all — an empty panel is honest, and a panel that invents a plausible fraud
 * alert is worse than no panel.
 *
 * Deliberately read-only and deliberately cheap: it runs on the dashboard, and
 * every query below is bounded by both a short time window and an explicit
 * cap, so an unusually busy day cannot turn the overview into a table scan.
 */
class RiskSignalService
{
    public const LEVEL_HIGH = 'high';

    public const LEVEL_MEDIUM = 'medium';

    public const LEVEL_LOW = 'low';

    /**
     * How far back a signal may look. The panel says "last 24 hours" and it
     * means it.
     */
    private const WINDOW_HOURS = 24;

    /**
     * An account younger than this is still in the window where a deposit
     * followed straight back out is worth a second look.
     */
    private const YOUNG_ACCOUNT_DAYS = 3;

    /**
     * Minutes between the deposit and the payout that follows it.
     */
    private const PASS_THROUGH_MINUTES = 30;

    /**
     * How much of the deposit has to leave again for it to read as a
     * pass-through rather than ordinary use of a funded wallet.
     */
    private const PASS_THROUGH_SHARE = 0.8;

    /**
     * Minutes inside which sign-ins from two countries are implausible.
     */
    private const TRAVEL_MINUTES = 20;

    /**
     * Refused sign-ins against one account before it is worth a look.
     *
     * The login throttle allows between three and ten tries per address before
     * locking the pair out for fifteen minutes, so reaching ten inside a day
     * means either several lockouts ridden out or an attempt spread across
     * addresses. Both are somebody working at the account rather than a
     * customer mistyping.
     */
    private const FAILED_LOGIN_THRESHOLD = 10;

    /**
     * Row caps. Each query is windowed to a day already; the cap is the second
     * guarantee, so a busy day still cannot pull an unbounded set into memory
     * to draw three rows.
     */
    private const SCAN_CAP = 500;

    private const LOGIN_SCAN_CAP = 2000;

    /**
     * A merchant refunding this many times its own thirty-day daily average in
     * one day is either broken or being abused.
     */
    private const REFUND_SPIKE_FACTOR = 5;

    /**
     * Below this, a "spike" is one or two refunds on a merchant that usually
     * has none, which is not news.
     */
    private const REFUND_SPIKE_FLOOR = 5;

    /**
     * Every signal worth a human look, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function signals(): array
    {
        $signals = array_merge(
            $this->passThroughPayouts(),
            $this->failedLogins(),
            $this->implausibleTravel(),
            $this->duplicateDocuments(),
            $this->rateDivergence(),
            $this->refundSpikes(),
        );

        usort($signals, static fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_values($signals);
    }

    /**
     * @param  list<array<string, mixed>>|null $signals
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit = 3, ?array $signals = null): array
    {
        return array_slice($signals ?? $this->signals(), 0, max(0, $limit));
    }

    /**
     * @param  list<array<string, mixed>>                          $signals
     * @return array{high: int, medium: int, low: int, total: int}
     */
    public function counts(array $signals): array
    {
        $counts = [self::LEVEL_HIGH => 0, self::LEVEL_MEDIUM => 0, self::LEVEL_LOW => 0];

        foreach ($signals as $signal) {
            $level          = (string) $signal['level'];
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }

        return [
            'high'   => $counts[self::LEVEL_HIGH],
            'medium' => $counts[self::LEVEL_MEDIUM],
            'low'    => $counts[self::LEVEL_LOW],
            'total'  => count($signals),
        ];
    }

    /**
     * What the platform shut out on its own today.
     *
     * @return array{ips: int, accounts: int}
     */
    public function autoBlockedToday(): array
    {
        $startOfDay = now()->startOfDay();

        return [
            'ips' => IPBlock::query()->where('blocked_at', '>=', $startOfDay)->count(),
            /*
             * A suspension flips the account's status rather than writing a
             * row of its own, so "today" is the day a disabled account was
             * last written to.
             */
            'accounts' => User::query()
                ->where('status', UserStatus::INACTIVE)
                ->where('updated_at', '>=', $startOfDay)
                ->count(),
        ];
    }

    /**
     * A payout that leaves almost as fast as the deposit that funded it, on an
     * account only days old.
     *
     * Sized against the deposit rather than an absolute figure: the platform
     * is multi-currency, and "over 5,000" is a different sentence in each of
     * them, while "nearly all of what just arrived" is the same sentence
     * everywhere.
     *
     * @return list<array<string, mixed>>
     */
    private function passThroughPayouts(): array
    {
        $youngAccounts = User::query()
            ->where('created_at', '>=', now()->subDays(self::YOUNG_ACCOUNT_DAYS))
            ->pluck('username', 'id');

        if ($youngAccounts->isEmpty()) {
            return [];
        }

        $payouts = Transaction::query()
            ->without('user')
            ->whereIn('user_id', $youngAccounts->keys())
            ->where('trx_type', TrxType::WITHDRAW)
            ->whereIn('status', [TrxStatus::PENDING, TrxStatus::COMPLETED])
            ->where('created_at', '>=', $this->windowOpen())
            ->orderByDesc('created_at')
            ->limit(self::SCAN_CAP)
            ->get(['id', 'user_id', 'trx_id', 'amount', 'currency', 'created_at']);

        $signals = [];

        foreach ($payouts as $payout) {
            $deposit = Transaction::query()
                ->without('user')
                ->where('user_id', $payout->user_id)
                ->where('trx_type', TrxType::DEPOSIT)
                ->where('status', TrxStatus::COMPLETED)
                ->where('currency', $payout->currency)
                ->whereBetween('created_at', [
                    $payout->created_at->copy()->subMinutes(self::PASS_THROUGH_MINUTES),
                    $payout->created_at,
                ])
                ->orderByDesc('created_at')
                ->first(['id', 'amount', 'created_at']);

            if ($deposit === null || (float) $deposit->amount <= 0.0) {
                continue;
            }

            if ((float) $payout->amount < (float) $deposit->amount * self::PASS_THROUGH_SHARE) {
                continue;
            }

            $signals[] = [
                'key'    => 'pass_through_payout',
                'level'  => self::LEVEL_HIGH,
                'title'  => __('Large withdrawal after balance top-up'),
                'detail' => __(':payout payout requested :gap after a :deposit deposit on an account :age old.', [
                    'payout'  => Money::format($payout->amount, $payout->currency),
                    'gap'     => $deposit->created_at->diffForHumans($payout->created_at, CarbonInterface::DIFF_ABSOLUTE, true),
                    'deposit' => Money::format($deposit->amount, $payout->currency),
                    'age'     => $this->accountAge((int) $payout->user_id),
                ]),
                'subject' => __('user #:id', ['id' => $payout->user_id]),
                'at'      => $payout->created_at,
                'action'  => __('Hold payout'),
                'link'    => route('admin.transaction', ['search' => $payout->trx_id]),
            ];
        }

        return $signals;
    }

    /**
     * An account being guessed at.
     *
     * Widened from the handoff's twenty-minute burst to the panel's own
     * twenty-four hours on purpose: the throttle already stops a burst after a
     * handful of tries, so an attempt that is still running a day later is one
     * that has been paced to survive it. That is the one worth showing.
     *
     * An account already suspended is not reported — the answer the row offers
     * is "lock this account", and it is locked.
     *
     * @return list<array<string, mixed>>
     */
    private function failedLogins(): array
    {
        $offenders = LoginActivity::query()
            ->failed()
            ->whereNotNull('user_id')
            ->where('login_at', '>=', $this->windowOpen())
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= ?', [self::FAILED_LOGIN_THRESHOLD])
            ->orderByRaw('MAX(login_at) DESC')
            ->limit(self::SCAN_CAP)
            ->selectRaw('user_id, COUNT(*) as attempts, COUNT(DISTINCT ip_address) as addresses, MAX(login_at) as last_attempt')
            ->get();

        if ($offenders->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $offenders->pluck('user_id')->all())
            ->get(['id', 'username', 'status'])
            ->keyBy('id');

        $signals = [];

        foreach ($offenders as $offender) {
            $user = $users->get((int) $offender->user_id);

            if ($user === null || $user->status === UserStatus::INACTIVE) {
                continue;
            }

            $addresses = (int) $offender->addresses;

            $signals[] = [
                'key'    => 'failed_logins',
                'level'  => self::LEVEL_HIGH,
                'title'  => __('Repeated failed sign-ins'),
                'detail' => $addresses > 1
                    ? __(':count refused sign-ins from :addresses addresses in the last 24 hours; the account is not locked.', [
                        'count'     => (int) $offender->attempts,
                        'addresses' => $addresses,
                    ])
                    : __(':count refused sign-ins in the last 24 hours; the account is not locked.', [
                        'count' => (int) $offender->attempts,
                    ]),
                'subject' => __('user #:id', ['id' => (int) $offender->user_id]),
                'at'      => Carbon::parse($offender->last_attempt),
                'action'  => __('Lock account'),
                'link'    => route('admin.user.manage', ['username' => $user->username]),
            ];
        }

        return $signals;
    }

    /**
     * One identity document submitted under more than one account.
     *
     * Compared by digest rather than by file name, so the two uploads only
     * match when they are byte for byte the same document. That is a narrow
     * test — a rephotographed passport will not trip it — and narrow is the
     * right side to err on for a signal that accuses somebody of holding two
     * accounts on one identity.
     *
     * Historic submissions are invisible here until `kyc:backfill-document-hashes`
     * has read them once.
     *
     * @return list<array<string, mixed>>
     */
    private function duplicateDocuments(): array
    {
        $shared = KycDocumentHash::query()
            ->groupBy('hash')
            ->havingRaw('COUNT(DISTINCT user_id) > 1')
            ->havingRaw('MAX(created_at) >= ?', [$this->windowOpen()])
            ->orderByRaw('MAX(created_at) DESC')
            ->limit(self::SCAN_CAP)
            ->selectRaw('hash, COUNT(DISTINCT user_id) as accounts, MAX(created_at) as last_seen')
            ->get();

        if ($shared->isEmpty()) {
            return [];
        }

        /*
         * One pass for the rows behind every matching digest, folded in PHP.
         * The alternative is a query per digest to name the accounts, on a
         * panel that draws three rows.
         */
        $rows = KycDocumentHash::query()
            ->whereIn('hash', $shared->pluck('hash')->all())
            ->orderByDesc('created_at')
            ->get(['hash', 'user_id', 'field_label', 'created_at'])
            ->groupBy('hash');

        $signals = [];

        foreach ($shared as $match) {
            $group  = $rows->get((string) $match->hash);
            $newest = $group?->first();

            if ($newest === null) {
                continue;
            }

            $username = User::query()->whereKey($newest->user_id)->value('username');

            $signals[] = [
                'key'    => 'duplicate_document',
                'level'  => self::LEVEL_MEDIUM,
                'title'  => __('Same ID document on several accounts'),
                'detail' => __('":document" was submitted under :count accounts; the file is identical each time.', [
                    'document' => $newest->field_label,
                    'count'    => (int) $match->accounts,
                ]),
                'subject' => __('user #:id', ['id' => (int) $newest->user_id]),
                'at'      => Carbon::parse($match->last_seen),
                'action'  => __('Review KYC'),
                'link'    => $username === null
                    ? route('admin.kyc.pending')
                    : route('admin.kyc.index', ['search' => $username]),
            ];
        }

        return $signals;
    }

    /**
     * The same account signing in from two countries inside twenty minutes.
     *
     * Distinct from the refused-sign-in signal above: these all succeeded.
     * Someone holding working credentials and using them from two places at
     * once is a different problem from someone still guessing at them.
     *
     * @return list<array<string, mixed>>
     */
    private function implausibleTravel(): array
    {
        $rows = LoginActivity::query()
            ->whereNotNull('user_id')
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->where('login_at', '>=', $this->windowOpen())
            ->orderByDesc('login_at')
            ->limit(self::LOGIN_SCAN_CAP)
            ->get(['user_id', 'country', 'login_at']);

        $signals = [];

        foreach ($rows->groupBy('user_id') as $userId => $logins) {
            $ordered = $logins->sortBy('login_at')->values();

            foreach ($ordered as $index => $login) {
                $window = $ordered
                    ->slice($index)
                    ->takeWhile(fn (LoginActivity $row): bool => $login->login_at->diffInMinutes($row->login_at, true) <= self::TRAVEL_MINUTES);

                $countries = $window->pluck('country')->unique()->values();

                if ($countries->count() < 2) {
                    continue;
                }

                $user = User::query()->find((int) $userId, ['id', 'username', 'status']);

                if ($user === null || $user->status === UserStatus::INACTIVE) {
                    break;
                }

                $signals[] = [
                    'key'    => 'implausible_travel',
                    'level'  => self::LEVEL_HIGH,
                    'title'  => __('Sign-ins from several countries at once'),
                    'detail' => __(':count sign-ins from :countries within :minutes minutes; the account is not locked.', [
                        'count'     => $window->count(),
                        'countries' => $countries->implode(', '),
                        'minutes'   => self::TRAVEL_MINUTES,
                    ]),
                    'subject' => __('user #:id', ['id' => $userId]),
                    'at'      => $window->last()->login_at,
                    'action'  => __('Lock account'),
                    'link'    => route('admin.user.manage', ['username' => $user->username]),
                    /*
                     * On a demo installation the published credentials are one
                     * account anybody may sign into, so evaluators around the
                     * world land on it within minutes of each other. This
                     * signal then fires on the site's normal working state, and
                     * an operator reading a red HIGH row has no way to tell
                     * that from a real account takeover.
                     *
                     * The row keeps its place and its level rather than being
                     * hidden or downgraded: the detection is still correct, and
                     * silently dropping a fraud signal because a setting is on
                     * is a worse habit than explaining it.
                     */
                    'note' => config('app.demo')
                        ? __('Demo mode is on: the published demo account is shared, so sign-ins from several countries are expected here.')
                        : null,
                ];

                break;
            }
        }

        return $signals;
    }

    /**
     * A currency the rate guard will not convert right now.
     *
     * @return list<array<string, mixed>>
     */
    private function rateDivergence(): array
    {
        try {
            $guard = app(RateGuard::class);
        } catch (Throwable) {
            return [];
        }

        $signals = [];

        foreach (Currency::query()->where('status', true)->orderBy('code')->get() as $currency) {
            $reason = $guard->reject($currency);

            if ($reason === null) {
                continue;
            }

            $signals[] = [
                'key'     => 'rate_divergence',
                'level'   => self::LEVEL_MEDIUM,
                'title'   => __('Exchange rate divergence'),
                'detail'  => $guard->message($currency, $reason),
                'subject' => strtoupper((string) $currency->code),
                'at'      => $currency->updated_at ?? now(),
                'action'  => __('Check rates'),
                'link'    => route('admin.currency.index'),
            ];
        }

        return $signals;
    }

    /**
     * A merchant refunding far more today than it normally does.
     *
     * The merchant is carried in trx_data, so the fold happens in PHP over a
     * capped thirty-day window rather than through a JSON path expression only
     * one database engine understands.
     *
     * @return list<array<string, mixed>>
     */
    private function refundSpikes(): array
    {
        // A sandbox refund returns no money: a merchant testing its refund
        // flow is not a spike.
        $refunds = Transaction::query()
            ->without('user')
            ->where('trx_type', TrxType::REFUND)
            ->where('provider', 'merchant_api')
            ->withoutSandbox()
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->orderByDesc('created_at')
            ->limit(self::SCAN_CAP)
            ->get(['id', 'trx_data', 'created_at']);

        if ($refunds->isEmpty()) {
            return [];
        }

        $dayOpen = $this->windowOpen();
        $signals = [];

        foreach ($refunds->groupBy(fn (Transaction $row): string => (string) ($row->trx_data['merchant_id'] ?? '')) as $merchantId => $group) {
            if ((string) $merchantId === '') {
                continue;
            }

            $today = $group->where('created_at', '>=', $dayOpen);
            $count = $today->count();

            if ($count < self::REFUND_SPIKE_FLOOR) {
                continue;
            }

            $dailyAverage = ($group->count() - $count) / 30;

            if ($dailyAverage > 0 && $count < $dailyAverage * self::REFUND_SPIKE_FACTOR) {
                continue;
            }

            $name = (string) ($group->first()->trx_data['merchant_name'] ?? '');

            $signals[] = [
                'key'    => 'refund_spike',
                'level'  => self::LEVEL_LOW,
                'title'  => __('Merchant refund spike'),
                'detail' => __(':count refunds in 24h against a usual :average a day; may be a checkout bug or abuse.', [
                    'count'   => $count,
                    'average' => number_format($dailyAverage, 1),
                ]),
                'subject' => __('merchant #:id', ['id' => $merchantId]),
                'at'      => $today->max('created_at') ?? now(),
                'action'  => __('Open merchant'),
                'link'    => $name === ''
                    ? route('admin.merchant.index')
                    : route('admin.merchant.index', ['search' => $name]),
            ];
        }

        return $signals;
    }

    private function accountAge(int $userId): string
    {
        $created = User::query()->whereKey($userId)->value('created_at');

        return $created === null
            ? __('new')
            : Carbon::parse($created)->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE, true);
    }

    private function windowOpen(): Carbon
    {
        return now()->subHours(self::WINDOW_HOURS);
    }
}
