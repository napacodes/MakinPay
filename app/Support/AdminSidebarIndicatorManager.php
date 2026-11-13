<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AgentStatus;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Agent;
use App\Models\Observability\ErrorGroup;
use App\Models\PaymentGateway;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Dashboard\DashboardQueueService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The counts the sidebar puts next to a menu.
 *
 * A badge here means one thing: someone is waiting on an admin. That is why a
 * count of zero renders nothing at all — a clean installation shows a sidebar
 * with no badges, which is the honest picture — and it is why every number is a
 * live aggregate rather than anything seeded or remembered.
 *
 * Which menu carries which badge is declared in config/admin_menus.php as
 * `'badge' => '<key>'`; this class only knows how to count. Keeping the tree in
 * the config means re-ordering the menu can never silently detach a badge from
 * the thing it counts.
 *
 * Where a badge counts something the dashboard also lists as a review queue,
 * the scope comes from DashboardQueueService rather than being restated here.
 * Eight of these were written out twice, once in each file, and agreed only
 * because nobody had yet changed one of them - a badge saying 7 above a panel
 * saying 4 is two plausible numbers and no way to tell which is right.
 *
 * The remaining badges have no queue behind them (suspended accounts, agent
 * applications, tickets already being worked, unconfigured gateways, open
 * error groups, the version string), so they keep their own definitions. The
 * permission, the tone and the 99+ cap stay here in every case: those are how
 * a badge is shown, not what it counts.
 *
 * The whole map is computed once a minute for the whole panel. It used to run
 * eight aggregates on every admin page load, for every admin, on every request.
 */
final class AdminSidebarIndicatorManager
{
    public const CACHE_KEY = 'admin.sidebar.badges';

    public const CACHE_TTL_SECONDS = 60;

    /** Above this the badge reads "99+" — the exact number has stopped mattering. */
    public const DISPLAY_CAP = 99;

    /** A decision is overdue. */
    public const TONE_DANGER = 'danger';

    /** A decision is waiting. */
    public const TONE_WARN = 'warn';

    /** Informational; nothing is blocked on it. */
    public const TONE_NEUTRAL = 'neutral';

    /**
     * Every badge the panel can show, keyed by the name the menu config uses.
     *
     * @return array<string, array{permission?: string, tone: string, count?: callable, text?: callable}>
     */
    public static function definitions(): array
    {
        return [
            'users_kyc_pending' => [
                'permission' => 'user-list',
                'tone'       => self::TONE_DANGER,
                /*
                 * The same scope the page behind this link uses, so the badge
                 * and the list it opens never disagree.
                 */
                'count' => static fn (): int => User::query()->kycUnverified()->count(),
            ],
            'users_suspended' => [
                'permission' => 'user-list',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => User::query()->where('status', UserStatus::INACTIVE)->count(),
            ],
            'kyc_pending' => [
                'permission' => 'kyc-list',
                'tone'       => self::TONE_DANGER,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('kyc')->count(),
            ],
            'merchants_pending' => [
                'permission' => 'merchant-list',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('merchant')->count(),
            ],
            'agents_pending' => [
                'permission' => 'agent-list',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => Agent::query()->where('status', AgentStatus::PENDING)->count(),
            ],
            'tickets_new' => [
                'permission' => 'support-ticket-list',
                'tone'       => self::TONE_DANGER,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('ticket')->count(),
            ],
            'tickets_in_progress' => [
                'permission' => 'support-ticket-list',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => Ticket::query()->where('status', TicketStatus::OPEN)->count(),
            ],
            'deposits_manual' => [
                'permission' => 'deposit-list',
                'tone'       => self::TONE_DANGER,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('deposit')->count(),
            ],
            'withdrawals_manual' => [
                'permission' => 'withdraw-list',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('withdraw')->count(),
            ],
            'p2p_disputes' => [
                'permission' => 'p2p-dispute-manage',
                'tone'       => self::TONE_DANGER,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('dispute')->count(),
            ],
            'cards_awaiting' => [
                'permission' => 'virtual-card-action',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('vc_request')->count(),
            ],
            'cardholders_pending' => [
                'permission' => 'virtual-card-action',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => DashboardQueueService::pendingScope('cardholder')->count(),
            ],
            /*
             * Bills a customer has already paid for, waiting to be paid at the
             * biller. Shown to the admins who can settle or refund them, and
             * guarded on the table for an upgrade that has not migrated yet.
             */
            'bill_payments_awaiting' => [
                'permission' => 'bill-payment-action',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => Schema::hasTable('bill_payments')
                    ? DashboardQueueService::pendingScope('bill_payment')->count()
                    : 0,
            ],
            /*
             * Mass payments held for review or stalled mid-payout, with money
             * still reserved. Shown to the admins who can resume or cancel
             * them, and guarded on the table for an unmigrated upgrade.
             */
            'mass_payments_awaiting' => [
                'permission' => 'mass-payment-action',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => Schema::hasTable('mass_payment_batches')
                    ? DashboardQueueService::pendingScope('mass_payment')->count()
                    : 0,
            ],
            /*
             * Enabled but never given real credentials. isConfigured() reads the
             * credential array rather than a column, so this is the one
             * definition that cannot be a SQL aggregate; the minute-long cache
             * is what keeps it off the request path.
             */
            'gateways_unconfigured' => [
                'permission' => 'payment-gateway-list',
                'tone'       => self::TONE_WARN,
                'count'      => static fn (): int => PaymentGateway::query()
                    ->where('status', true)
                    ->get()
                    ->filter(static fn (PaymentGateway $gateway): bool => ! $gateway->isConfigured())
                    ->count(),
            ],
            /*
             * Unresolved errors. The count is what makes the sidebar itself the
             * alarm — an admin who never opens the module still sees that
             * something is broken.
             *
             * Guarded on the table existing because this runs on every admin
             * page, including on an installation that has upgraded the code but
             * not yet run the migration.
             */
            'errors_open' => [
                'permission' => 'observability-list',
                'tone'       => self::TONE_DANGER,
                'count'      => static fn (): int => Schema::hasTable('error_groups')
                    ? ErrorGroup::query()->open()->count()
                    : 0,
            ],
            /*
             * Not a queue — the installed version, so an operator can read it
             * without opening the updater. Text rather than a count, and
             * neutral, because nothing is waiting on it.
             */
            'app_version' => [
                'permission' => 'project-updater-view',
                'tone'       => self::TONE_NEUTRAL,
                'text'       => static fn (): string => 'v'.config('app.version'),
            ],
        ];
    }

    /**
     * The badges this admin may see, keyed by badge name.
     *
     * @return array<string, array{count?: int, text?: string, display: string, tone: string}>
     */
    public static function build(array $adminPermissions = []): array
    {
        return self::buildFromDefinitions(self::definitions(), $adminPermissions, self::values());
    }

    /**
     * @param  array<string, array<string, mixed>>                                             $definitions
     * @param  array<string, int|string>|null                                                  $values      pre-computed; resolved from the definitions when omitted
     * @return array<string, array{count?: int, text?: string, display: string, tone: string}>
     */
    public static function buildFromDefinitions(array $definitions, array $adminPermissions = [], ?array $values = null): array
    {
        $values ??= self::resolve($definitions);
        $badges = [];

        foreach ($definitions as $key => $definition) {
            if (! self::passesPermission($definition['permission'] ?? null, $adminPermissions)) {
                continue;
            }

            $value = $values[$key] ?? null;
            $tone  = self::normaliseTone((string) ($definition['tone'] ?? self::TONE_NEUTRAL));

            if (isset($definition['text'])) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $badges[$key] = ['text' => $value, 'display' => $value, 'tone' => $tone];

                continue;
            }

            $count = (int) $value;

            if ($count < 1) {
                continue;
            }

            $badges[$key] = self::countPayload($count, $tone);
        }

        return $badges;
    }

    /**
     * Add up the badges a group's children carry, for the row that collapses
     * them. A parent declaring its own badge keeps it; this only fills in for a
     * parent that declares none.
     *
     * @param  array<string, array<string, mixed>>                   $badges
     * @param  list<string>                                          $childKeys
     * @return array{count: int, display: string, tone: string}|null
     */
    public static function rollUp(array $badges, array $childKeys): ?array
    {
        $total = 0;
        $tone  = self::TONE_NEUTRAL;

        foreach ($childKeys as $key) {
            $badge = $badges[$key] ?? null;

            if ($badge === null || ! isset($badge['count'])) {
                continue;
            }

            $total += (int) $badge['count'];

            /* One overdue child makes the whole group overdue. */
            if ($badge['tone'] === self::TONE_DANGER || $tone === self::TONE_NEUTRAL) {
                $tone = (string) $badge['tone'];
            }
        }

        return $total < 1 ? null : self::countPayload($total, $tone);
    }

    /**
     * Every badge value, for the whole panel, computed at most once a minute.
     *
     * Permission is deliberately not part of the key: the numbers are the same
     * for every admin and only whether one is shown differs, so a single entry
     * serves the whole staff rather than one per role.
     *
     * @return array<string, int|string>
     */
    public static function values(): array
    {
        try {
            return Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                static fn (): array => self::resolve(self::definitions())
            );
        } catch (Throwable) {
            /*
             * A cache store that is down must not take the whole admin panel
             * with it — this runs on every page.
             */
            return self::resolve(self::definitions());
        }
    }

    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Nothing to forget if the store is unreachable.
        }
    }

    /**
     * @param  array<string, array<string, mixed>> $definitions
     * @return array<string, int|string>
     */
    private static function resolve(array $definitions): array
    {
        $values = [];

        foreach ($definitions as $key => $definition) {
            $resolver = $definition['count'] ?? $definition['text'] ?? null;

            if (! is_callable($resolver)) {
                continue;
            }

            try {
                $values[$key] = isset($definition['text']) ? (string) $resolver() : (int) $resolver();
            } catch (Throwable) {
                /*
                 * One missing table or renamed column must not blank the whole
                 * sidebar; that badge simply does not appear.
                 */
                continue;
            }
        }

        return $values;
    }

    private static function passesPermission(mixed $permission, array $adminPermissions): bool
    {
        if (! is_string($permission) || $permission === '') {
            return true;
        }

        return in_array($permission, $adminPermissions, true);
    }

    /**
     * @return array{count: int, display: string, tone: string}
     */
    private static function countPayload(int $count, string $tone): array
    {
        return [
            'count'   => $count,
            'display' => $count > self::DISPLAY_CAP ? self::DISPLAY_CAP.'+' : (string) $count,
            'tone'    => self::normaliseTone($tone),
        ];
    }

    private static function normaliseTone(string $tone): string
    {
        return in_array($tone, [self::TONE_DANGER, self::TONE_WARN, self::TONE_NEUTRAL], true)
            ? $tone
            : self::TONE_NEUTRAL;
    }
}
