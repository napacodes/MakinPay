<?php

namespace App\Http\Controllers\Backend;

use App\Enums\KycStatus;
use App\Enums\MerchantStatus;
use App\Enums\MethodType;
use App\Enums\TicketStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\UserRole;
use App\Enums\VirtualCard\CardholderStatus;
use App\Enums\VirtualCard\VirtualCardRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\UpdateDashboardSlaRequest;
use App\Models\Cardholders;
use App\Models\Currency;
use App\Models\KycSubmission;
use App\Models\Merchant;
use App\Models\Observability\ErrorGroup;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCardRequest;
use App\Models\Wallet;
use App\Services\BackgroundHealthService;
use App\Services\CurrencyService;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DashboardQueueService;
use App\Services\Dashboard\DashboardRange;
use App\Services\Dashboard\DashboardSla;
use App\Services\Dashboard\GatewayHealthService;
use App\Services\Dashboard\RiskSignalService;
use App\Services\Dashboard\SetupChecklistService;
use App\Support\LedgerReporting;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardController extends Controller
{
    /**
     * How many of the newest transactions the activity panel lists.
     */
    private const LATEST_TRANSACTIONS = 6;

    /**
     * How many risk signals the panel shows before deferring to its own list.
     */
    private const RISK_ROWS = 3;

    public function index(Request $request)
    {
        $range = DashboardRange::fromRequest($request);

        /*
         * Built only for the callers that read it. It used to run before this
         * branch, so a fee_chart or wallet_chart request paid for a full
         * transaction scan it threw away, and so did every administrator whose
         * role cannot see the chart at all.
         */
        $legacyChart = fn (): array => $this->generateChartData($range->start, $range->end);

        /*
         * The original ajax contract is kept exactly as it was — buyer
         * integrations and the existing chart scripts call these keys — and
         * `kpis` is added beside them so a range change can refresh the hero
         * band and the chart in one request instead of two.
         */
        if (($request->ajax() || $request->wantsJson()) && ($request->has('trx_chart') || $request->has('fee_chart') || $request->has('wallet_chart') || $request->has('kpis') || $request->has('end_date'))) {
            return match (true) {
                $request->has('trx_chart')    => response()->json($legacyChart()),
                $request->has('fee_chart')    => response()->json($this->generateFeeChartData($range->start, $range->end)),
                $request->has('wallet_chart') => response()->json($this->generateWalletChartData($range->start, $range->end)),
                $request->has('kpis')         => response()->json($this->rangePayload($request, $range)),
                default                       => response()->json(['error' => 'Invalid chart type'], 400),
            };
        }

        return view('backend.dashboard.index', ['dashboard' => $this->pagePayload($request, $range)]);
    }

    /**
     * Queue counts and health chips for the 60-second poll.
     *
     * Deliberately small: it is fetched once a minute by every open admin tab,
     * so it carries what changes and nothing else.
     */
    public function live(Request $request): JsonResponse
    {
        $queues     = app(DashboardQueueService::class)->queues();
        $admin      = $request->user('admin');
        $showHealth = (bool) $admin?->can('system-health');

        $health = $showHealth ? $this->healthRows(
            app(BackgroundHealthService::class)->summary(),
            app(GatewayHealthService::class)->summary(),
            $this->errorsLastDay(),
        ) : [];

        $attention = count(array_filter($health, static fn (array $row): bool => $row['state'] !== 'ok'));
        $risk      = app(RiskSignalService::class);
        $riskCount = $admin?->can('risk-watch') ? $risk->counts($risk->signals())['total'] : null;

        return response()->json([
            'queues' => $queues->map(fn (array $queue): array => [
                'key'         => $queue['key'],
                'count'       => $queue['count'],
                'state'       => $queue['state'],
                'sla_percent' => $queue['sla_percent'],
                'over_count'  => $queue['over_count'],
                'oldest'      => $queue['oldest_at']?->diffForHumans(null, true, true),
            ])->values(),
            'pending_total' => $queues->sum('count'),
            'active_total'  => $queues->where('count', '>', 0)->count(),
            'queue_total'   => $queues->count(),
            'breaches'      => $queues->where('state', DashboardSla::STATE_OVER)->where('count', '>', 0)->count(),
            'health'        => $health,
            /*
             * The strings come back already translated. The poll runs in the
             * administrator's locale, and having the browser assemble
             * "3 of 7 checks need a look" from parts would put one English
             * sentence back on a page that has none.
             */
            'queue_summary_text' => $this->queueSummaryText($queues),
            'queue_summary_html' => $this->queueSummaryHtml($queues),
            'health_summary'     => [
                'attention' => $attention,
                'total'     => count($health),
                'text'      => $attention === 0
                    ? __('Everything is running normally')
                    : __(':count of :total checks need a look', ['count' => $attention, 'total' => count($health)]),
                'pill' => $attention === 0 ? __('All good') : __('Attention'),
            ],
            /*
             * A count, not the signals themselves. The rows carry customer
             * detail and their own permission; the poll only needs to know
             * whether the pill has changed.
             */
            'risk_count'   => $riskCount,
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The gateway panel on its own, so it can fail and be retried without the
     * rest of the page waiting on a provider status call.
     */
    public function gatewayHealth(Request $request): JsonResponse
    {
        if ($request->boolean('refresh')) {
            app(GatewayHealthService::class)->forget();
        }

        $summary = app(GatewayHealthService::class)->summary();

        return response()->json([
            'gateways' => array_map(function (array $gateway): array {
                $gateway['volume_display'] = $gateway['volume_base'] === null
                    ? ($gateway['volume_label'] ?? null)
                    : Money::compact($gateway['volume_base'], $this->defaultCurrencyCode()).' · '.__('24h');

                return $gateway;
            }, $summary['gateways']),
            'summary'      => Arr::except($summary, ['gateways']),
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Save the SLA targets from the dashboard modal.
     *
     * Written through the settings layer rather than a dashboard-owned table:
     * these are admin-configured site-wide values, which is exactly what the
     * settings layer is for, and the settings screen renders the same keys.
     */
    public function updateSla(UpdateDashboardSlaRequest $request): JsonResponse
    {
        foreach ($request->validated() as $key => $value) {
            // The alert switch is a boolean; everything else is a whole number
            // of hours or a percentage.
            $key === DashboardSla::ALERTS_SETTING
                ? Setting::add($key, (bool) $value, 'bool')
                : Setting::add($key, (int) $value, 'int');
        }

        return response()->json([
            'message' => __('SLA targets saved.'),
            'targets' => app(DashboardSla::class)->all(),
            'warning' => app(DashboardSla::class)->warningThreshold(),
            'alerts'  => app(DashboardSla::class)->alertsEnabled(),
        ]);
    }

    /**
     * Everything the dashboard view renders, in one DTO, gated panel by panel.
     *
     * A panel the administrator cannot see is not computed at all: the @can in
     * the view hides markup, this stops the queries behind it from running.
     *
     * @return array<string, mixed>
     */
    private function pagePayload(Request $request, DashboardRange $range): array
    {
        $admin        = $request->user('admin');
        $sla          = app(DashboardSla::class);
        $checklist    = app(SetupChecklistService::class);
        $queueService = app(DashboardQueueService::class);
        $metrics      = app(DashboardMetricsService::class);

        $can = [
            'queue'    => (bool) $admin?->can('dashboard-queue'),
            'stats'    => (bool) $admin?->can('dashboard-stats'),
            'system'   => (bool) $admin?->can('system-health'),
            'treasury' => (bool) $admin?->can('wallet-balance'),
            'chart'    => (bool) $admin?->can('transactions-chart'),
            'trx'      => (bool) $admin?->can('wallet-latest-transactions'),
            'users'    => (bool) $admin?->can('wallet-latest-users'),
            'risk'     => (bool) $admin?->can('risk-watch'),
            'sla'      => (bool) $admin?->can('dashboard-sla-manage'),
        ];

        /*
         * Each measurement is taken once and handed to whatever needs it. The
         * health rows used to re-run the scheduler probe, the gateway summary
         * and the error count that the payload beside them had already taken.
         */
        $backgroundHealth = $can['system'] ? app(BackgroundHealthService::class)->summary() : null;
        $gatewayHealth    = $can['system'] ? app(GatewayHealthService::class)->summary() : null;
        $errorsLastDay    = $can['system'] ? $this->errorsLastDay() : null;

        $queues       = $can['queue'] ? $queueService->queues() : collect();
        $freshInstall = $can['queue'] && $checklist->isFreshInstall();
        $breaches     = $queues->where('state', DashboardSla::STATE_OVER)->where('count', '>', 0)->count();

        $health = $can['system']
            ? $this->healthRows($backgroundHealth, $gatewayHealth, $errorsLastDay)
            : [];

        $risk = $can['risk'] ? $this->riskPayload() : null;

        return [
            'range'         => $range,
            'base'          => $this->defaultCurrencyCode(),
            'timezone'      => $this->siteTimezone(),
            'refreshed_at'  => now()->toIso8601String(),
            'can'           => $can,
            'counters'      => $can['stats'] && $can['users'] ? $this->cachedCounters() : null,
            'queues'        => $queueService->sort($queues, $this->queueSort($request)),
            'queue_sort'    => $this->queueSort($request),
            'queue_summary' => [
                'text'     => $this->queueSummaryText($queues),
                'html'     => $this->queueSummaryHtml($queues),
                'pending'  => (int) $queues->sum('count'),
                'active'   => $queues->where('count', '>', 0)->count(),
                'total'    => $queues->count(),
                'breaches' => $breaches,
            ],
            'sla' => [
                'targets' => $sla->all(),
                'warning' => $sla->warningThreshold(),
                'alerts'  => $sla->alertsEnabled(),
            ],
            'fresh_install' => $freshInstall,
            'setup'         => $freshInstall ? $checklist->steps() : null,
            'money'         => $can['stats'] ? $metrics->moneyKpis($range) : null,
            'chart'         => $can['chart'] ? $this->cachedChart($range) : null,
            /*
             * Always the latest six, whatever the chart is showing. The range
             * control belongs to "Money in and out"; a list of what just
             * happened that silently emptied when somebody picked Today would
             * be reporting on a different question than the one it asks.
             */
            'transactions' => $can['trx'] ? Transaction::latest()->limit(self::LATEST_TRANSACTIONS)->get() : collect(),
            'health'       => [
                'rows'      => $health,
                'attention' => count(array_filter($health, static fn (array $row): bool => $row['state'] !== 'ok')),
                'total'     => count($health),
            ],
            'treasury' => $can['treasury'] ? $metrics->treasury() : null,
            'risk'     => $risk,
        ];
    }

    /**
     * The risk panel, with its signals taken once and reused for the count,
     * the severity strip and the three rows.
     *
     * @return array<string, mixed>
     */
    private function riskPayload(): array
    {
        $service = app(RiskSignalService::class);
        $signals = $service->signals();

        return [
            'signals' => $service->latest(self::RISK_ROWS, $signals),
            'counts'  => $service->counts($signals),
            'blocked' => $service->autoBlockedToday(),
        ];
    }

    /**
     * The subset a range change needs: the money band and the chart.
     *
     * @return array<string, mixed>
     */
    private function rangePayload(Request $request, DashboardRange $range): array
    {
        $admin = $request->user('admin');

        return [
            'range' => [
                'preset' => $range->preset,
                'label'  => $range->label(),
                'start'  => $range->start->toDateString(),
                'end'    => $range->end->toDateString(),
            ],
            'kpis'  => $admin?->can('dashboard-stats') ? app(DashboardMetricsService::class)->moneyKpis($range) : null,
            'chart' => $admin?->can('transactions-chart') ? $this->cachedChart($range) : null,
        ];
    }

    /**
     * The line under "Needs your action", assembled once so the page and the
     * poll can never word it differently.
     *
     * @param Collection<int, array<string, mixed>> $queues
     */
    private function queueSummaryText($queues): string
    {
        $pending = (int) $queues->sum('count');

        return __('Right now').' · '
            .trans_choice('{0}nothing waiting|{1}:count item waiting|[2,*]:count items waiting', $pending, ['count' => number_format($pending)])
            .' '.__('across :active of :total queues', [
                'active' => $queues->where('count', '>', 0)->count(),
                'total'  => $queues->count(),
            ]);
    }

    /**
     * The same line, marked up.
     *
     * The redesigned panel emphasises the two figures an operator scans for —
     * how much is waiting and how much is held — so the sentence can no longer
     * be a bare string. It is still assembled here rather than in the browser:
     * the poll runs in the administrator's locale and every word, plural form
     * and money format comes back already rendered, which is what keeps one
     * English sentence off a page that has none.
     *
     * Every interpolated value is escaped before it is placed, and the only
     * tags introduced are the emphasis the design asks for.
     *
     * @param Collection<int, array<string, mixed>> $queues
     */
    private function queueSummaryHtml($queues): string
    {
        $pending = (int) $queues->sum('count');
        $held    = (float) $queues->sum('value_base');
        $base    = $this->defaultCurrencyCode();

        $waiting = trans_choice(
            '{0}No requests|{1}:count request|[2,*]:count requests',
            $pending,
            ['count' => number_format($pending)],
        );

        return __(':pending waiting in :active of :total queues · :held on hold', [
            'pending' => '<strong>'.e($waiting).'</strong>',
            'active'  => e(number_format($queues->where('count', '>', 0)->count())),
            'total'   => e(number_format($queues->count())),
            /* Compact on the line, exact on hover — as on the rows themselves. */
            'held' => '<strong title="'.e(Money::format($held, $base)).'" dir="ltr">'.e(Money::compact($held, $base)).'</strong>',
        ]);
    }

    private function queueSort(Request $request): string
    {
        $sort = (string) $request->query('queue_sort', 'sla');

        return in_array($sort, ['sla', 'count', 'value'], true) ? $sort : 'sla';
    }

    /**
     * The platform-health rows: scheduler, worker, rates, every configured
     * gateway, and unresolved errors.
     *
     * Each row names the screen that can fix it and carries the permission
     * that screen requires — a row is only a link for an administrator who
     * would be let in, so the status stays visible without offering a click
     * that 403s.
     *
     * @param  array<string, mixed>|null  $background
     * @param  array<string, mixed>|null  $gateways
     * @return list<array<string, mixed>>
     */
    private function healthRows(?array $background, ?array $gateways, ?int $errors): array
    {
        if ($background === null) {
            return [];
        }

        $scheduler = $background['scheduler'];
        $queue     = $background['queue'];

        $rows = [
            [
                'key'  => 'scheduler',
                'name' => __('Scheduler (cron)'),
                'note' => $scheduler['last_run_at'] === null
                    ? __('Open Background jobs for the cron entry to add')
                    : __('Checked every minute'),
                'value' => $scheduler['last_run_at'] === null
                    ? __('set up cron')
                    : __('Ran :ago', ['ago' => $scheduler['last_run_at']->diffForHumans(null, true)]),
                'state' => match ($scheduler['status']) {
                    'ok'      => 'ok',
                    'stalled' => 'down',
                    default   => 'warn',
                },
                'mono' => false,
                'href' => route('admin.background-tasks.scheduler'),
                'can'  => 'background-task-list',
            ],
            [
                'key'  => 'queue',
                'name' => __('Queue worker'),
                // Every job the queue has given up on, not a 24-hour slice:
                // the count behind this line has no time bound, and a label
                // promising one read as "all clear" beside a Background Tasks
                // screen listing hundreds. A null means the table could not be
                // read, which is not the same as nothing having failed.
                'note' => $queue['failed'] === null
                    ? __('Failed job count unavailable')
                    : trans_choice(
                        '{0}No failed jobs|{1}:count failed job|[2,*]:count failed jobs',
                        (int) $queue['failed'],
                        ['count' => (int) $queue['failed']]
                    ),
                'value' => $queue['pending'] === null
                    ? __('unknown')
                    : trans_choice('{0}Idle|{1}:count queued|[2,*]:count queued', (int) $queue['pending'], ['count' => (int) $queue['pending']]),
                'state' => match ($queue['status']) {
                    'ok'      => 'ok',
                    'stalled' => 'down',
                    default   => 'warn',
                },
                'mono' => false,
                'href' => route('admin.background-tasks.index'),
                'can'  => 'background-task-list',
            ],
        ];

        $configured = $this->orderedGatewayRows($gateways);

        foreach ($configured['rows'] as $gateway) {
            [$href, $can] = $this->healthRowDestination($gateway);

            $rows[] = [
                'key'   => $gateway['code'],
                'name'  => $gateway['name'],
                'note'  => $gateway['note'],
                'value' => $gateway['volume_base'] === null
                    ? (string) ($gateway['volume_label'] ?? '—')
                    : Money::compact($gateway['volume_base'], $this->defaultCurrencyCode()).' · '.__('24h'),
                'state' => match ($gateway['status']) {
                    GatewayHealthService::STATUS_OPERATIONAL => 'ok',
                    GatewayHealthService::STATUS_DOWN        => 'down',
                    default                                  => 'warn',
                },
                'mono' => $gateway['volume_base'] !== null,
                'href' => $href,
                'can'  => $can,
            ];
        }

        if ($configured['idle'] > 0) {
            $rows[] = [
                'key'   => 'gateways-idle',
                'name'  => __('Other payment gateways'),
                'note'  => __('Configured · no traffic in the last hour'),
                'value' => trans_choice('{1}:count idle|[2,*]:count idle', $configured['idle'], ['count' => $configured['idle']]),
                'state' => 'ok',
                'mono'  => false,
                'href'  => route('admin.payment.gateway.index'),
                'can'   => 'payment-gateway-list',
            ];
        }

        $rows[] = [
            'key'   => 'errors',
            'name'  => __('Open error issues'),
            'note'  => __('Unresolved exceptions · last 24h'),
            'value' => trans_choice('{0}None open|{1}:count open|[2,*]:count open', (int) ($errors ?? 0), ['count' => (int) ($errors ?? 0)]),
            'state' => match (true) {
                $errors === null, $errors === 0 => 'ok',
                $errors < 10 => 'warn',
                default      => 'down',
            },
            'mono' => false,
            'href' => route('admin.observability.issues'),
            'can'  => 'observability-list',
        ];

        return $rows;
    }

    /**
     * Where a health row's click should land, and the permission that screen
     * requires.
     *
     * A rate row names its own destination, because the fix depends on what is
     * wrong: a missing provider is fixed in the plugin settings, an unusable
     * rate on the currency screen, and a method priced away from its currency
     * on whichever method table holds it. Sending all three to the currency
     * list would land two of them on a screen with nothing to change.
     *
     * @param  array<string, mixed>        $row
     * @return array{0: string, 1: string}
     */
    private function healthRowDestination(array $row): array
    {
        return match ($row['link'] ?? null) {
            'plugin'          => [route('admin.settings.plugin_type', 'exchange_rate'), 'plugins-manage'],
            'currency'        => [route('admin.currency.index'), 'currency-list'],
            'deposit_method'  => [route('admin.deposit.method.index'), 'deposit-method-list'],
            'withdraw_method' => [route('admin.withdraw.method.index'), 'withdraw-method-list'],
            default           => $row['kind'] === 'gateway'
                ? [route('admin.payment.gateway.index'), 'payment-gateway-list']
                : [route('admin.currency.index'), 'currency-list'],
        };
    }

    /**
     * Rates first, then the payment gateways that have something to report.
     *
     * A site can have twenty gateways configured and take money through two.
     * The other eighteen each said "No traffic in the last hour · $0.00 · 24h",
     * which is eighteen rows of the same non-event pushing the checks that do
     * mean something off the bottom of the panel. An idle gateway is not a
     * health signal, so it is counted rather than listed — and one that is
     * degraded or down is listed whether it moved money or not.
     *
     * GatewayHealthService appends the rate provider after the gateways; the
     * panel reads top-down as platform plumbing before third parties.
     *
     * @param  array<string, mixed>|null                          $gateways
     * @return array{rows: list<array<string, mixed>>, idle: int}
     */
    private function orderedGatewayRows(?array $gateways): array
    {
        $rows = $gateways['gateways'] ?? [];
        $idle = 0;

        $rows = array_values(array_filter($rows, static function (array $row) use (&$idle): bool {
            if ($row['kind'] !== 'gateway' || $row['status'] !== GatewayHealthService::STATUS_OPERATIONAL) {
                return true;
            }

            if ((float) ($row['volume_base'] ?? 0) > 0 || $row['last_success'] !== null) {
                return true;
            }

            $idle++;

            return false;
        }));

        // Rank rather than a boolean: there are two rate rows now, and both are
        // platform plumbing that reads before any third party.
        $rank = static fn (array $row): int => match ($row['kind']) {
            'blocked_rates' => 0,
            'rates'         => 1,
            'method_rates'  => 2,
            default         => 3,
        };

        usort($rows, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return ['rows' => $rows, 'idle' => $idle];
    }

    /**
     * New issues seen in the last day. Null when the error-tracking tables are
     * not present, which is the case on an installation that has not migrated
     * into the module yet.
     */
    private function errorsLastDay(): ?int
    {
        try {
            if (! Schema::hasTable('error_groups')) {
                return null;
            }

            return ErrorGroup::query()
                ->where('first_seen', '>=', now()->subDay())
                ->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cachedCounters(): array
    {
        return Cache::remember('dashboard.counters', 60, fn (): array => app(DashboardMetricsService::class)->counters());
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedChart(DashboardRange $range): array
    {
        $key = 'dashboard.chart.'.$range->preset.'.'.$range->start->toDateString().'.'.$range->end->toDateString();

        return Cache::remember($key, 60, fn (): array => app(DashboardMetricsService::class)->chart($range));
    }

    private function siteTimezone(): string
    {
        /*
         * AppConfigService already applies `site_timezone` to app.timezone, so
         * config() is the right fallback — but the key is site_timezone, not
         * timezone. Reading a key that has never existed only looked correct
         * because the fallback happened to be the same value.
         */
        return (string) (setting('site_timezone', config('app.timezone')) ?: config('app.timezone'));
    }

    protected function getAdminOverviewStats(): Collection
    {
        $totalUsers            = User::query()->count();
        $activeUsers           = User::active()->count();
        $verifiedUsers         = User::query()->whereNotNull('email_verified_at')->count();
        $newUsersLastSevenDays = User::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $unverifiedUsers = User::query()->whereNull('email_verified_at')->count();
        $merchantUsers   = User::query()->where('role', UserRole::MERCHANT)->count();
        $storefronts     = Merchant::active()->count();
        $merchantQueue   = Merchant::query()->where('status', MerchantStatus::PENDING)->count();
        $kycQueue        = KycSubmission::query()->where('status', KycStatus::PENDING)->count();
        $ticketQueue     = Ticket::query()->where('status', TicketStatus::PENDING)->count();
        $depositQueue    = Transaction::query()
            ->where('trx_type', TrxType::DEPOSIT)
            ->where('status', TrxStatus::PENDING)
            ->where('processing_type', MethodType::MANUAL)
            ->count();
        $withdrawQueue = Transaction::query()
            ->where('trx_type', TrxType::WITHDRAW)
            ->where('status', TrxStatus::PENDING)
            ->where('processing_type', MethodType::MANUAL)
            ->count();
        $cardholderQueue  = Cardholders::query()->where('status', CardholderStatus::PENDING)->count();
        $cardRequestQueue = VirtualCardRequest::query()->where('status', VirtualCardRequestStatus::Pending)->count();
        $pendingActions   = $unverifiedUsers
            + $merchantQueue
            + $kycQueue
            + $ticketQueue
            + $depositQueue
            + $withdrawQueue
            + $cardholderQueue
            + $cardRequestQueue;

        return collect([
            [
                'key'         => 'total_users',
                'group'       => 'audience',
                'title'       => __('Total Users'),
                'value'       => $totalUsers,
                'meta'        => __('All accounts'),
                'icon'        => 'kpi-users',
                'color_class' => 'total',
                'link'        => route('admin.user.index'),
            ],
            [
                'key'         => 'active_users',
                'group'       => 'audience',
                'title'       => __('Active Users'),
                'value'       => $activeUsers,
                'meta'        => __('Can transact'),
                'icon'        => 'kpi-user-active',
                'color_class' => 'active-svg',
                'link'        => route('admin.user.active'),
            ],
            [
                'key'         => 'verified_users',
                'group'       => 'audience',
                'title'       => __('Verified Users'),
                'value'       => $verifiedUsers,
                'meta'        => __('Email verified'),
                'icon'        => 'kpi-shield-check',
                'color_class' => 'success-svg',
                'link'        => route('admin.user.index'),
            ],
            [
                'key'         => 'new_users',
                'group'       => 'audience',
                'title'       => __('New Users'),
                'value'       => $newUsersLastSevenDays,
                'meta'        => __('Last 7 days'),
                'icon'        => 'kpi-user-plus',
                'color_class' => 'success-svg',
                'link'        => route('admin.user.index'),
            ],
            [
                'key'         => 'pending_actions',
                'group'       => 'review',
                'title'       => __('Pending Actions'),
                'value'       => $pendingActions,
                'meta'        => __('Across review queues'),
                'icon'        => 'kpi-review-stack',
                'color_class' => 'warning-svg',
            ],
            [
                'key'         => 'unverified_users',
                'group'       => 'review',
                'title'       => __('Unverified'),
                'value'       => $unverifiedUsers,
                'meta'        => __('Email pending'),
                'icon'        => 'kpi-user-alert',
                'color_class' => 'danger-svg',
                'link'        => route('admin.user.unverified'),
            ],
            [
                'key'         => 'kyc_queue',
                'group'       => 'review',
                'title'       => __('KYC Queue'),
                'value'       => $kycQueue,
                'meta'        => __('Identity reviews'),
                'icon'        => 'kpi-kyc-review',
                'color_class' => 'info-svg',
                'link'        => route('admin.kyc.pending'),
            ],
            [
                'key'         => 'ticket_queue',
                'group'       => 'review',
                'title'       => __('Ticket Queue'),
                'value'       => $ticketQueue,
                'meta'        => __('Support backlog'),
                'icon'        => 'kpi-ticket',
                'color_class' => 'danger-svg',
                'link'        => route('admin.support-ticket.new'),
            ],
            [
                'key'         => 'merchants',
                'group'       => 'commerce',
                'title'       => __('Merchants'),
                'value'       => $merchantUsers,
                'meta'        => __('Merchant accounts'),
                'icon'        => 'kpi-merchant',
                'color_class' => 'info-svg',
            ],
            [
                'key'         => 'storefronts',
                'group'       => 'commerce',
                'title'       => __('Storefronts'),
                'value'       => $storefronts,
                'meta'        => __('Approved shops'),
                'icon'        => 'kpi-storefront',
                'color_class' => 'merchant',
                'link'        => route('admin.merchant.index', ['status' => MerchantStatus::APPROVED]),
            ],
            [
                'key'         => 'merchant_queue',
                'group'       => 'commerce',
                'title'       => __('Merchant Queue'),
                'value'       => $merchantQueue,
                'meta'        => __('Applications'),
                'icon'        => 'kpi-store-queue',
                'color_class' => 'info-svg',
                'link'        => route('admin.merchant.pending'),
            ],
            [
                'key'         => 'cardholder_queue',
                'group'       => 'commerce',
                'title'       => __('Cardholder Queue'),
                'value'       => $cardholderQueue,
                'meta'        => __('Profile reviews'),
                'icon'        => 'kpi-cardholder',
                'color_class' => 'merchant',
                'link'        => route('admin.virtual-card.cardholders.index', ['status' => CardholderStatus::PENDING->value]),
            ],
            [
                'key'         => 'deposit_queue',
                'group'       => 'money',
                'title'       => __('Deposit Queue'),
                'value'       => $depositQueue,
                'meta'        => __('Manual deposits'),
                'icon'        => 'kpi-deposit',
                'color_class' => 'deposit',
                'link'        => route('admin.deposit.manual-request'),
            ],
            [
                'key'         => 'withdraw_queue',
                'group'       => 'money',
                'title'       => __('Withdraw Queue'),
                'value'       => $withdrawQueue,
                'meta'        => __('Manual payouts'),
                'icon'        => 'kpi-withdraw',
                'color_class' => 'withdraw',
                'link'        => route('admin.withdraw.manual-request'),
            ],
            [
                'key'         => 'card_request_queue',
                'group'       => 'money',
                'title'       => __('Card Requests'),
                'value'       => $cardRequestQueue,
                'meta'        => __('Issuance queue'),
                'icon'        => 'kpi-card-request',
                'color_class' => 'payment',
                'link'        => route('admin.virtual-card.requests.awaiting'),
            ],
            $this->getTotalP2pFeeStat(),
        ])->values();
    }

    protected function generateChartData(Carbon $start, Carbon $end): array
    {
        $trxTypes = [
            'deposit'  => [TrxType::DEPOSIT],
            'withdraw' => [TrxType::WITHDRAW],
            'payment'  => [TrxType::PAYMENT],
            'reward'   => [TrxType::REWARD, TrxType::REFERRAL_REWARD, TrxType::CASHBACK],
        ];

        // Sandbox payments complete without moving money; the payment series
        // would otherwise chart a merchant's tests as takings.
        $transactions = Transaction::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->selectRaw('trx_type, created_at, '.LedgerReporting::selectAs('amount', 'amount'))
            ->get();

        $currencyRates = $this->currencyRatesFor($transactions);

        $grouped = [];

        foreach ($trxTypes as $key => $types) {
            $typeValues = array_map(fn ($t) => $t->value, $types);

            $grouped[$key] = collect($transactions)
                ->filter(fn ($trx) => in_array($trx->trx_type->value, $typeValues))
                ->groupBy(fn ($trx) => Carbon::parse($trx->created_at)->format('Y-m-d'))
                ->map(fn ($dailyTrxs) => $this->sumRowsInDefaultCurrency($dailyTrxs, 'amount', $currencyRates));
        }

        $dates = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => $date->format('Y-m-d'))
            ->toArray();

        $formatted = collect($trxTypes)->mapWithKeys(function ($types, $key) use ($grouped, $dates) {
            $seriesData = collect($dates)->map(fn ($d) => round($grouped[$key][$d] ?? 0, 2));

            return [$key => [
                'name'  => ucfirst($key),
                'total' => $seriesData->sum(),
                'data'  => $seriesData,
            ]];
        });

        return [
            'dates'  => $dates,
            'series' => $formatted->values()->toArray(),
        ];
    }

    protected function generateFeeChartData(Carbon $start, Carbon $end): array
    {
        $data = Transaction::selectRaw(
            'DATE(created_at) as date, '.LedgerReporting::currency('fee').' as currency, SUM('.LedgerReporting::amount('fee').') as total_fee'
        )
            ->whereBetween('created_at', [$start, $end])
            ->where('status', TrxStatus::COMPLETED)
            // A sandbox charge's fee was never earned.
            ->withoutSandbox()
            // Grouped by the expression rather than its alias: under
            // ONLY_FULL_GROUP_BY, MySQL validates the columns the expression
            // actually reads, and `base_fee` is not one of them via an alias.
            ->groupByRaw('DATE(created_at), '.LedgerReporting::currency('fee'))
            ->orderBy('date')
            ->get();

        $currencyRates = $this->currencyRatesFor($data);
        $feesByDate    = $data
            ->groupBy('date')
            ->map(fn (Collection $dailyFees): float => round(
                $this->sumRowsInDefaultCurrency($dailyFees, 'total_fee', $currencyRates),
                2
            ));

        $dates   = [];
        $amounts = [];

        foreach ($start->daysUntil($end) as $day) {
            $dateStr   = $day->toDateString();
            $dates[]   = $dateStr;
            $amounts[] = $feesByDate->get($dateStr, 0);
        }

        return [
            'series' => [
                [
                    'name' => __('Fee'),
                    'data' => $amounts,
                ],
            ],
            'categories' => $dates,
        ];
    }

    protected function generateWalletChartData(Carbon $start, Carbon $end): array
    {
        $wallets = Wallet::selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', true)
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $dates  = [];
        $values = [];

        foreach ($start->daysUntil($end) as $day) {
            $dateStr  = $day->toDateString();
            $dates[]  = $dateStr;
            $values[] = $wallets[$dateStr]->total ?? 0;
        }

        return [
            'series' => [[
                'name' => __('New Wallets'),
                'data' => $values,
            ]],
            'categories' => $dates,
        ];
    }

    /**
     * P2P fee stats derived purely from transactions table.
     */
    protected function getTotalP2pFeeStat(): array
    {
        // Maker fee recorded at escrow time (minus refunds on cancel/expire)
        $makerFeeEscrows = Transaction::query()
            ->where('provider', 'p2p')
            ->where('trx_type', TrxType::P2P_ESCROW)
            ->where('status', TrxStatus::COMPLETED)
            ->selectRaw('currency, SUM(fee) as total_fee')
            ->groupBy('currency')
            ->get();

        $makerFeeRefunds = Transaction::query()
            ->where('provider', 'p2p')
            ->where('trx_type', TrxType::P2P_RELEASE)
            ->where('status', TrxStatus::COMPLETED)
            ->where(function ($q) {
                $q->where('trx_data->refund', true)
                    ->orWhere('trx_data->expired', true);
            })
            ->selectRaw("currency, SUM(JSON_EXTRACT(trx_data, '$.maker_fee_refund')) as total_fee")
            ->groupBy('currency')
            ->get();

        $takerFeeRefunds = Transaction::query()
            ->where('provider', 'p2p')
            ->where('trx_type', TrxType::P2P_RELEASE)
            ->where('status', TrxStatus::COMPLETED)
            ->where(function ($q) {
                $q->where('trx_data->refund', true)
                    ->orWhere('trx_data->expired', true);
            })
            ->selectRaw("currency, SUM(JSON_EXTRACT(trx_data, '$.taker_fee_refund')) as total_fee")
            ->groupBy('currency')
            ->get();

        // Taker fee recorded at release time (buyer credit)
        $takerFees = Transaction::query()
            ->where('provider', 'p2p')
            ->where('trx_type', TrxType::P2P_RELEASE)
            ->where('status', TrxStatus::COMPLETED)
            ->where('fee', '>', 0)
            ->selectRaw('currency, SUM(fee) as total_fee')
            ->groupBy('currency')
            ->get();

        $feeRows       = $makerFeeEscrows->merge($makerFeeRefunds)->merge($takerFeeRefunds)->merge($takerFees);
        $currencyRates = $this->currencyRatesFor($feeRows);

        $makerFeeEarned = round(
            $this->sumRowsInDefaultCurrency($makerFeeEscrows, 'total_fee', $currencyRates)
            - $this->sumRowsInDefaultCurrency($makerFeeRefunds, 'total_fee', $currencyRates),
            8
        );
        $takerFeeEarned = round(
            $this->sumRowsInDefaultCurrency($takerFees, 'total_fee', $currencyRates)
            - $this->sumRowsInDefaultCurrency($takerFeeRefunds, 'total_fee', $currencyRates),
            8
        );
        $totalFees = round($makerFeeEarned + $takerFeeEarned, 8);

        return [
            'key'         => 'p2p_fees',
            'group'       => 'money',
            'title'       => __('P2P Fees'),
            'value'       => $this->formatDefaultCurrencyAmount($totalFees),
            'meta'        => __('Default currency'),
            'icon'        => 'kpi-fees',
            'color_class' => 'info-svg',
        ];
    }

    /**
     * These three delegate to CurrencyService, which owns the base-currency
     * arithmetic. The bodies used to live here *and*, byte for byte, in the
     * frontend dashboard — two copies of a conversion that has to agree with
     * the ledger, and with each other.
     */
    protected function currencyRatesFor(Collection $rows, string $currencyColumn = 'currency'): Collection
    {
        return app(CurrencyService::class)->ratesByCode($rows->pluck($currencyColumn));
    }

    protected function sumRowsInDefaultCurrency(Collection $rows, string $amountColumn, Collection $currencyRates): float
    {
        return (float) app(CurrencyService::class)->sumInBaseCurrency($rows, $amountColumn, $currencyRates);
    }

    protected function convertToDefaultCurrency(float $amount, ?string $currency, string $defaultCurrency, Collection $currencyRates): float
    {
        return (float) app(CurrencyService::class)->toBaseAmount($amount, $currency, $currencyRates);
    }

    /**
     * These totals are already converted into the base currency, so they are
     * formatted at the base currency's own precision.
     */
    protected function formatDefaultCurrencyAmount(float $amount): string
    {
        return Money::format($amount, $this->defaultCurrencyCode());
    }

    protected function defaultCurrencyCode(): string
    {
        return strtoupper((string) (siteCurrency('code') ?: config('app.default_currency', 'USD')));
    }

    protected function defaultCurrencySymbol(): string
    {
        return (string) (siteCurrency('symbol') ?: config('app.default_currency_symbol', '$'));
    }
}
