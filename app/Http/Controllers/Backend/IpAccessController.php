<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Http\Requests\Backend\Activity\StoreIpBlockRequest;
use App\Models\IPBlock;
use App\Models\LoginActivity;
use App\Services\Security\IpAbuseGuard;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Admin → Security → IP deny list.
 *
 * The addresses `App\Http\Middleware\BlockIp` refuses, and the addresses
 * `IpAbuseGuard` is still counting strikes against. Two sources, one table:
 * a row an administrator wrote, a row the guard wrote, and — the reason the
 * screen earns its place — a row the guard deliberately did NOT write,
 * because several accounts sign in from that address. A safeguard nobody
 * can see is indistinguishable from one that is not working.
 *
 * Every write here busts the middleware's cache, or the change would not
 * take effect until the entry lapsed on its own.
 */
class IpAccessController extends BaseController
{
    /** The key BlockIp caches the list under. Must match, or writes do nothing. */
    private const CACHE_KEY = IpAbuseGuard::CACHE_KEY;

    /** @var list<string> */
    public const FILTERS = ['all', 'active', 'auto', 'lapsed'];

    public function __construct(private readonly IpAbuseGuard $guard) {}

    /**
     * @return array<string, string>
     */
    public static function permissions(): array
    {
        return [
            'index|show'    => 'user-activity-log',
            'store|destroy' => 'user-manage',
        ];
    }

    public function index(Request $request): View
    {
        $filter = in_array((string) $request->query('filter'), self::FILTERS, true)
            ? (string) $request->query('filter')
            : 'all';

        $search = trim((string) $request->query('q', ''));

        $blocks = $this->scoped($filter, $search)
            ->orderByDesc('blocked_at')
            ->paginate(20)
            ->withQueryString();

        /*
         * Held-back addresses live in the cache, not the table, so they
         * cannot be paginated with it. They are few by definition — an
         * address has to pass the threshold to become one — and they are the
         * rows that ask for a decision, so they lead the list on the first
         * page rather than trailing the last.
         */
        $heldBack = in_array($filter, ['all', 'active'], true) && $blocks->onFirstPage()
            ? $this->heldBackRows($search)
            : [];

        return view('backend.security.ip-access.index', [
            'blocks'       => $blocks,
            'heldBack'     => $heldBack,
            'filter'       => $filter,
            'search'       => $search,
            'filterCounts' => $this->filterCounts($search),
            'stats'        => $this->stats(),
            'weights'      => IpAbuseGuard::SIGNAL_WEIGHTS,
            'threshold'    => $this->guard->threshold(),
            'window'       => $this->guard->windowMinutes(),
            'demoMode'     => (bool) config('app.demo'),
        ]);
    }

    /**
     * The side panel for one address.
     *
     * Addressed by IP rather than by row id, because a held-back address has
     * no row: it is the block that was not written.
     */
    public function show(Request $request): View
    {
        $ip = trim((string) $request->query('ip', ''));

        abort_if($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false, 404);

        $block   = IPBlock::query()->where('ip_address', $ip)->first();
        $watched = $this->guard->watchedEntry($ip);

        abort_if($block === null && $watched === null, 404);

        /*
         * For an address the guard held back, the count it decided on is the
         * one to show. Recomputing here would let the panel contradict the
         * decision it is explaining — "safe to block" above a row that says
         * the guard refused to block it — whenever the sign-in history has
         * been pruned since.
         */
        $accounts = ($watched['held_back'] ?? false)
            ? (int) $watched['accounts']
            : $this->guard->accountsSeenAt($ip);

        return view('backend.security.ip-access.partials._drawer', [
            'ip'          => $ip,
            'block'       => $block,
            'watched'     => $watched,
            'signals'     => $block?->signals ?? ($watched['signals'] ?? []),
            'strikes'     => $block?->strikes ?? (int) ($watched['total'] ?? 0),
            'accounts'    => $accounts,
            'accountList' => $this->accountsReachedFor($ip),
            'location'    => $this->locationOf($ip),
            'shared'      => $this->guard->isShared($accounts),
            'weights'     => IpAbuseGuard::SIGNAL_WEIGHTS,
            'threshold'   => $this->guard->threshold(),
            'window'      => $this->guard->windowMinutes(),
        ]);
    }

    public function store(StoreIpBlockRequest $request): RedirectResponse
    {
        /*
         * An administrator's block has no expiry and is marked as theirs, so
         * IpAbuseGuard leaves it alone: an address a human decided to refuse
         * is never quietly downgraded to a one-hour automatic cool-off.
         */
        $block = IPBlock::query()->updateOrCreate(
            ['ip_address' => $request->validated('ip_address')],
            [
                'reason'     => $request->validated('reason'),
                'source'     => IPBlock::SOURCE_MANUAL,
                'blocked_at' => now(),
                'expires_at' => null,
            ],
        );

        /*
         * It may have been on the watchlist — held back as shared, or simply
         * still counting. The decision has been made now, so the strikes go.
         */
        $this->guard->clear((string) $block->ip_address);

        $this->flush();

        Log::info('ip_block.added', [
            'ip_block_id' => $block->id,
            'admin_id'    => auth('admin')->id(),
        ]);

        notifyEvs('success', __(':ip is now blocked.', ['ip' => $block->ip_address]));

        return redirect()->route('admin.ip-access.index');
    }

    public function destroy(IPBlock $ipAccess): RedirectResponse
    {
        $address = $ipAccess->ip_address;

        $ipAccess->delete();

        $this->guard->clear((string) $address);

        $this->flush();

        Log::info('ip_block.removed', [
            'ip_address' => $address,
            'admin_id'   => auth('admin')->id(),
        ]);

        notifyEvs('success', __(':ip can sign in again.', ['ip' => $address]));

        return redirect()->route('admin.ip-access.index');
    }

    /**
     * @return Builder<IPBlock>
     */
    private function scoped(string $filter, string $search): Builder
    {
        $query = IPBlock::query();

        match ($filter) {
            'active' => $query->active(),
            'auto'   => $query->where('source', IPBlock::SOURCE_AUTO),
            'lapsed' => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
            default  => null,
        };

        if ($search !== '') {
            $query->where(function (Builder $match) use ($search): void {
                $match->where('ip_address', 'like', '%'.$search.'%')
                    ->orWhere('reason', 'like', '%'.$search.'%');
            });
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function filterCounts(string $search): array
    {
        $counts = [];

        foreach (self::FILTERS as $filter) {
            $counts[$filter] = $this->scoped($filter, $search)->count();
        }

        return $counts;
    }

    /**
     * The four figures in the header strip.
     *
     * @return array{active: int, permanent: int, lapsing: int, auto_day: int, watching: int, highest: int, next_ip: string|null, next_minutes: int|null}
     */
    private function stats(): array
    {
        $active    = IPBlock::query()->active()->count();
        $permanent = IPBlock::query()->active()->whereNull('expires_at')->count();

        $next = IPBlock::query()
            ->active()
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->first();

        $watchlist = $this->guard->watchlist();

        return [
            'active'    => $active,
            'permanent' => $permanent,
            'lapsing'   => max(0, $active - $permanent),
            'auto_day'  => IPBlock::query()
                ->where('source', IPBlock::SOURCE_AUTO)
                ->where('blocked_at', '>=', now()->subDay())
                ->count(),
            'watching'     => count($watchlist),
            'highest'      => (int) ($watchlist[0]['total'] ?? 0),
            'next_ip'      => $next?->ip_address,
            'next_minutes' => $next?->minutesRemaining(),
        ];
    }

    /**
     * Held-back addresses, filtered by the same search box as the table.
     *
     * @return list<array{ip: string, total: int, signals: array<string, int>, held_back: bool, accounts: int, expires: int}>
     */
    private function heldBackRows(string $search): array
    {
        $rows = $this->guard->heldBack();

        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_contains($row['ip'], $search),
        ));
    }

    /**
     * Where the platform last saw this address, in words.
     *
     * Read from the sign-in history rather than looked up live: the panel
     * must open instantly, and an address on the deny list has by
     * definition been here before.
     *
     * @return list<string>
     */
    private function locationOf(string $ip): array
    {
        $seen = LoginActivity::query()
            ->where('ip_address', $ip)
            ->orderByDesc('login_at')
            ->first(['city', 'country', 'isp']);

        if ($seen === null) {
            return [];
        }

        $place = trim(implode(', ', array_filter([$seen->city, $seen->country])));

        return array_values(array_filter([$place, (string) $seen->isp]));
    }

    /**
     * The accounts this address reached for, and what happened to them.
     *
     * Nothing, is the answer — which is the point of showing them. The
     * sanction is on the address, so every one of these sign-ins still
     * works from anywhere else.
     *
     * @return list<string>
     */
    private function accountsReachedFor(string $ip): array
    {
        return LoginActivity::query()
            ->where('ip_address', $ip)
            ->whereNotNull('user_id')
            ->where('login_at', '>=', now()->subDay())
            ->with('user:id,email')
            ->orderByDesc('login_at')
            ->limit(30)
            ->get(['id', 'user_id', 'login_at'])
            ->pluck('user.email')
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * The middleware caches the list; a write it cannot see is a change that
     * silently does not happen.
     */
    private function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
