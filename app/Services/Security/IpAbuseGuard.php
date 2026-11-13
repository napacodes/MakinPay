<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Http\Middleware\BlockIp;
use App\Models\IPBlock;
use App\Models\LoginActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts refused requests per address and blocks the ones that keep coming.
 *
 * The rule the platform needs is narrow: a visitor who is probing — wrong
 * credentials over and over, endpoints they have no right to, forged or
 * missing CSRF tokens — should stop reaching the application, and nobody
 * else should be affected by it. So the sanction is on the ADDRESS, never
 * on the account. That distinction is the whole point on a demo install,
 * where the sign-ins are published and shared: locking the account an
 * abuser happened to type would hand any visitor a way to break the demo
 * for everyone, while blocking the address they typed it from costs the
 * next visitor nothing.
 *
 * Refusing traffic is destructive when it is wrong, so four things hold it
 * back:
 *
 *   1. Only refusals count, and each one has a weight. A single 403 is a
 *      mistyped URL; twelve weighted refusals in ten minutes is a script.
 *   2. Private and reserved addresses are never blocked — behind a proxy
 *      that passes no trusted client address every visitor arrives as one
 *      of those, and one abuser would take the site down.
 *   3. An address several different accounts have recently signed in from
 *      is treated as shared (carrier NAT, an office, a reverse proxy) and
 *      is never blocked automatically. It is HELD BACK instead: the strikes
 *      stay on the watchlist so the deny-list screen can show an
 *      administrator the decision the guard refused to make for them.
 *   4. An automatic block expires on its own. Only an administrator's own
 *      deny-list entry is permanent, and the guard never rewrites one.
 */
class IpAbuseGuard
{
    /** The key {@see BlockIp} caches the deny list under. */
    public const CACHE_KEY = 'blocked_ips_map';

    /** Cache prefix for the rolling per-address strike total. */
    private const STRIKE_PREFIX = 'ip_abuse_strikes:';

    /** Cache prefix for the per-address breakdown by signal. */
    private const SIGNAL_PREFIX = 'ip_abuse_signals:';

    /**
     * The addresses currently collecting strikes.
     *
     * A cache cannot be enumerated, so the screen's "under watch" figure and
     * its held-back rows would have nothing to read without this. One entry
     * per address, written on the same tick as the counter and pruned when
     * it lapses; capped so a flood cannot grow it without bound.
     */
    private const WATCHLIST_KEY = 'ip_abuse_watchlist';

    /** How many addresses the watchlist keeps before dropping the quietest. */
    private const WATCHLIST_CAP = 200;

    /**
     * What each kind of refusal is worth.
     *
     * A lockout is the heaviest because the rate limiter has already
     * absorbed a run of wrong passwords before it fires. A stale CSRF token
     * is the lightest because an expired session produces one honestly.
     *
     * @var array<string, int>
     */
    public const SIGNAL_WEIGHTS = [
        'login_lockout'   => 5,
        'throttled'       => 3,
        'forbidden'       => 3,
        'unauthenticated' => 2,
        'csrf'            => 1,
    ];

    /**
     * Count one refusal against the address that caused it.
     *
     * Best effort by construction: this runs on the way out of a request
     * that has already failed, and a guard that turns a 403 into a 500 is
     * worse than one that misses a strike.
     */
    public function record(Request $request, string $signal): void
    {
        try {
            $this->apply($request, $signal);
        } catch (Throwable $exception) {
            Log::warning('ip_abuse_guard.failed', [
                'signal' => $signal,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Weighted strikes recorded against an address inside the live window.
     */
    public function strikes(string $ip): int
    {
        return (int) Cache::get(self::STRIKE_PREFIX.$ip, 0);
    }

    /**
     * How those strikes were earned: signal key => how many times it fired.
     *
     * @return array<string, int>
     */
    public function tally(string $ip): array
    {
        $tally = Cache::get(self::SIGNAL_PREFIX.$ip, []);

        return is_array($tally) ? $tally : [];
    }

    /**
     * Forget an address's strikes — what a successful sign-in means.
     */
    public function clear(string $ip): void
    {
        Cache::forget(self::STRIKE_PREFIX.$ip);
        Cache::forget(self::SIGNAL_PREFIX.$ip);
        $this->forgetWatched($ip);
    }

    /**
     * Every address still collecting strikes, heaviest first.
     *
     * @return list<array{ip: string, total: int, signals: array<string, int>, held_back: bool, accounts: int, expires: int}>
     */
    public function watchlist(): array
    {
        $rows = array_values($this->watched());

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    /**
     * The addresses that passed the threshold and were deliberately not
     * blocked, because several accounts sign in from them.
     *
     * These are the only rows on the deny-list screen that ask an
     * administrator for a decision, which is why they are surfaced at all:
     * a safeguard nobody can see is indistinguishable from one that is not
     * working.
     *
     * @return list<array{ip: string, total: int, signals: array<string, int>, held_back: bool, accounts: int, expires: int}>
     */
    public function heldBack(): array
    {
        return array_values(array_filter(
            $this->watchlist(),
            static fn (array $row): bool => $row['held_back'],
        ));
    }

    /**
     * One watchlist entry, or null when the address is not being watched.
     *
     * @return array{ip: string, total: int, signals: array<string, int>, held_back: bool, accounts: int, expires: int}|null
     */
    public function watchedEntry(string $ip): ?array
    {
        return $this->watched()[$ip] ?? null;
    }

    /**
     * Whether this address may be blocked automatically at all.
     */
    public function isExempt(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        /*
         * Reserved and private ranges. The application deliberately does not
         * trust the forwarded-client-address header (see bootstrap/app.php),
         * so behind a proxy that passes no trusted client address EVERY
         * visitor shares the proxy's address. Blocking it would be an
         * outage, not a defence.
         */
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return in_array($ip, $this->allowlist(), true);
    }

    /**
     * Addresses an operator has declared off limits.
     *
     * @return list<string>
     */
    public function allowlist(): array
    {
        $raw = (string) config('security.ip_auto_block_allowlist', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function threshold(): int
    {
        return max(2, (int) config('security.ip_auto_block_threshold', 12));
    }

    public function windowMinutes(): int
    {
        return max(1, (int) config('security.ip_auto_block_window_minutes', 10));
    }

    /**
     * Off while demo mode is on. A demo is many evaluators on shared
     * credentials, stale tabs and refused writes — exactly the pattern this
     * counts — so it would lock out the people the demo is for.
     */
    public function isEnabled(): bool
    {
        if ((bool) config('app.demo')) {
            return false;
        }

        return (bool) config('security.ip_auto_block_enabled', true);
    }

    /**
     * How many different accounts signed in from this address in a day.
     *
     * Public because the deny-list drawer states it as the reason a block
     * was safe — or the reason one was held back.
     */
    public function accountsSeenAt(string $ip): int
    {
        return LoginActivity::query()
            ->succeeded()
            ->where('ip_address', $ip)
            ->whereNotNull('user_id')
            ->where('login_at', '>=', now()->subDay())
            ->distinct()
            ->count('user_id');
    }

    /**
     * Is that a carrier NAT, an office, a campus or a proxy?
     *
     * If it is, one abuser behind it must not take the others down with
     * them, and the address is left for an administrator to judge.
     */
    public function isShared(int $accounts): bool
    {
        $limit = (int) config('security.ip_auto_block_shared_accounts', 3);

        return $limit >= 1 && $accounts >= $limit;
    }

    private function apply(Request $request, string $signal): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $ip = (string) $request->ip();

        if ($this->isExempt($ip)) {
            return;
        }

        /*
         * A signed-in administrator is the person who would have to undo the
         * block, and admin screens legitimately refuse a permission they do
         * not hold. Counting those would let the panel lock its own operator
         * out of the site.
         */
        if ($request->hasSession() && $request->user('admin') !== null) {
            return;
        }

        $weight = self::SIGNAL_WEIGHTS[$signal] ?? 1;
        $total  = $this->hit($ip, $weight, $signal);

        if ($total < $this->threshold()) {
            return;
        }

        $this->block($ip, $total, $signal);
    }

    /**
     * Add to the rolling counter and return the new total.
     */
    private function hit(string $ip, int $weight, string $signal): int
    {
        $key    = self::STRIKE_PREFIX.$ip;
        $window = now()->addMinutes($this->windowMinutes());

        if (Cache::add($key, $weight, $window)) {
            $total = $weight;
        } else {
            $total = Cache::increment($key, $weight);

            /*
             * Not every store returns the new value, and a key that lapsed
             * between the add and the increment returns false, so fall back
             * to writing the total we know about rather than losing the
             * strike.
             */
            if (! is_int($total)) {
                $total = $this->strikes($ip) + $weight;
                Cache::put($key, $total, $window);
            }
        }

        $tally          = $this->tally($ip);
        $tally[$signal] = ((int) ($tally[$signal] ?? 0)) + 1;

        Cache::put(self::SIGNAL_PREFIX.$ip, $tally, $window);

        $this->remember($ip, $total, $tally, $window);

        return $total;
    }

    /**
     * Write — or extend — the deny-list row for an address.
     */
    private function block(string $ip, int $strikes, string $signal): void
    {
        $watched = $this->watched();

        /*
         * Already judged shared inside this window. Saying so once is
         * information; saying it again on every further refusal is noise in
         * the log and a query per request.
         */
        if (($watched[$ip]['held_back'] ?? false) === true) {
            return;
        }

        $existing = IPBlock::query()->where('ip_address', $ip)->first();

        /* An administrator's decision is never rewritten by the guard. */
        if ($existing !== null && ! $existing->isAutomatic()) {
            return;
        }

        $accounts = $this->accountsSeenAt($ip);

        if ($this->isShared($accounts)) {
            Log::info('ip_abuse_guard.held_back_shared_address', [
                'ip_address' => $ip,
                'strikes'    => $strikes,
                'accounts'   => $accounts,
                'signal'     => $signal,
            ]);

            $this->markHeldBack($ip, $accounts);

            return;
        }

        $minutes = $this->nextDuration($existing);

        $block = IPBlock::query()->updateOrCreate(
            ['ip_address' => $ip],
            [
                'reason'     => $this->reasonFor($signal, $strikes),
                'source'     => IPBlock::SOURCE_AUTO,
                'strikes'    => $strikes,
                'signals'    => $this->tally($ip),
                'blocked_at' => now(),
                'expires_at' => now()->addMinutes($minutes),
            ],
        );

        $this->clear($ip);
        Cache::forget(self::CACHE_KEY);

        Log::warning('ip_block.auto_added', [
            'ip_block_id' => $block->id,
            'ip_address'  => $ip,
            'strikes'     => $strikes,
            'signal'      => $signal,
            'minutes'     => $minutes,
        ]);
    }

    /**
     * How long this block should last.
     *
     * A first offence is a cool-off. An address that comes back and earns
     * another one gets double the last block, up to the configured ceiling,
     * so a persistent script is turned away for longer each time without
     * ever becoming permanent on its own.
     */
    private function nextDuration(?IPBlock $existing): int
    {
        $base = max(1, (int) config('security.ip_auto_block_minutes', 60));
        $max  = max($base, (int) config('security.ip_auto_block_max_minutes', 1440));

        if ($existing === null) {
            return $base;
        }

        $previous = $existing->windowMinutes() ?? $base;

        return (int) min($max, max($base, $previous * 2));
    }

    /**
     * @return array<string, array{ip: string, total: int, signals: array<string, int>, held_back: bool, accounts: int, expires: int}>
     */
    private function watched(): array
    {
        $rows = Cache::get(self::WATCHLIST_KEY, []);

        if (! is_array($rows)) {
            return [];
        }

        $now = time();

        return array_filter(
            $rows,
            static fn ($row): bool => is_array($row) && (int) ($row['expires'] ?? 0) > $now,
        );
    }

    /**
     * @param array<string, int> $tally
     */
    private function remember(string $ip, int $total, array $tally, Carbon $window): void
    {
        $rows = $this->watched();

        $rows[$ip] = [
            'ip'        => $ip,
            'total'     => $total,
            'signals'   => $tally,
            'held_back' => (bool) ($rows[$ip]['held_back'] ?? false),
            'accounts'  => (int) ($rows[$ip]['accounts'] ?? 0),
            'expires'   => $window->getTimestamp(),
        ];

        $this->putWatched($rows);
    }

    private function markHeldBack(string $ip, int $accounts): void
    {
        $rows = $this->watched();

        if (! isset($rows[$ip])) {
            return;
        }

        $rows[$ip]['held_back'] = true;
        $rows[$ip]['accounts']  = $accounts;

        $this->putWatched($rows);
    }

    private function forgetWatched(string $ip): void
    {
        $rows = $this->watched();

        unset($rows[$ip]);

        $this->putWatched($rows);
    }

    /**
     * @param array<string, array{ip: string, total: int, signals: array<string, int>, held_back: bool, accounts: int, expires: int}> $rows
     */
    private function putWatched(array $rows): void
    {
        /*
         * The quietest addresses go first when the list is full: a flood of
         * one-strike arrivals must not push out the one that is about to be
         * blocked.
         */
        if (count($rows) > self::WATCHLIST_CAP) {
            uasort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
            $rows = array_slice($rows, 0, self::WATCHLIST_CAP, true);
        }

        Cache::put(self::WATCHLIST_KEY, $rows, now()->addMinutes($this->windowMinutes() * 2));
    }

    private function reasonFor(string $signal, int $strikes): string
    {
        return match ($signal) {
            'login_lockout'   => __('Automatic: repeated failed sign-ins (:count strikes).', ['count' => $strikes]),
            'throttled'       => __('Automatic: sustained rate-limited requests (:count strikes).', ['count' => $strikes]),
            'forbidden'       => __('Automatic: repeated requests for resources this visitor may not have (:count strikes).', ['count' => $strikes]),
            'unauthenticated' => __('Automatic: repeated unauthenticated requests to protected endpoints (:count strikes).', ['count' => $strikes]),
            'csrf'            => __('Automatic: repeated requests with a missing or forged CSRF token (:count strikes).', ['count' => $strikes]),
            default           => __('Automatic: repeated refused requests (:count strikes).', ['count' => $strikes]),
        };
    }
}
