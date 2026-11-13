<?php

namespace App\Http\Controllers\Backend;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Enums\Observability\ErrorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Observability\AssignIssueRequest;
use App\Http\Requests\Observability\BulkIssueActionRequest;
use App\Http\Requests\Observability\ConnectChannelRequest;
use App\Jobs\Observability\ApplyBulkIssueAction;
use App\Models\Observability\ErrorDeliveryLog;
use App\Models\Observability\ErrorEvent;
use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;
use App\Models\Observability\ErrorStatBucket;
use App\Services\Observability\AlertRouter;
use App\Services\Observability\Channels\ChannelAdapter;
use App\Services\Observability\Channels\ChannelResult;
use App\Services\Observability\CodeHighlighter;
use App\Services\Observability\DebugReportBuilder;
use App\Services\Observability\ErrorQueryParser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin surface of the Error Management & Observability module.
 *
 * Every read on these screens goes through the counter columns on error_groups
 * or the pre-aggregated rows in error_stat_buckets. Nothing here counts over
 * error_events, which is the table that grows without bound.
 */
class ObservabilityController extends Controller
{
    /**
     * Above this many groups a bulk action is queued instead of run inline.
     *
     * Below it, running inline is the safer choice: a CodeCanyon installation
     * with no queue worker would otherwise show a success message for work
     * that never happens.
     */
    private const INLINE_BULK_LIMIT = 50;

    /**
     * Application frames printed before the rest fold away.
     *
     * A real trace on this platform runs to eighty-odd frames. Past the first
     * dozen they are the same middleware on every error, and they push the
     * source excerpt — the one thing a reader came for — off the screen.
     */
    private const VISIBLE_APP_FRAMES = 12;

    /**
     * Vendor frames are already secondary; this is the point past which they
     * stop being context and start being a wall.
     */
    private const VISIBLE_VENDOR_FRAMES = 30;

    /**
     * Rows on the supporting tab.
     *
     * Fifty rows of three tables is a slow page for material nobody reads
     * line by line. The counts stay in the header either way.
     */
    private const SUPPORTING_ROWS = 25;

    /**
     * Lines of the debug report kept in the document.
     *
     * The preview exists to show what the copy button will hand over, not to
     * be read end to end. Printing the whole report inline put tens of
     * thousands of characters into the DOM of a screen that is opened when
     * something is already going wrong; the copy and the download still carry
     * every line.
     */
    private const PREVIEW_LINES = 60;

    /**
     * The working list. One row is one bug, not one occurrence.
     *
     * The parser never throws: a token it cannot place comes back marked
     * invalid, is left out of the query, and renders as an invalid pill. The
     * reader therefore sees that a condition was ignored instead of reading an
     * empty list as good news.
     */
    public function issues(Request $request, ErrorQueryParser $parser): View
    {
        $query  = (string) $request->query('q', '');
        $tokens = $parser->parse($query);

        $builder = ErrorGroup::query()->with('assignee');

        /*
         * With no query at all the list defaults to unresolved, because the
         * question this screen answers is "what still needs attention".
         */
        if ($tokens === []) {
            $builder->open();
        }

        $parser->apply($builder, $tokens, auth('admin')->id());

        $groups = $builder
            ->orderByDesc('last_seen')
            ->paginate(25)
            ->withQueryString();

        return view('backend.observability.issues', [
            'groups'      => $groups,
            'headerStats' => $this->headerStats(),
            'savedViews'  => $this->savedViews($parser),
            'sparklines'  => $this->sparklines($groups->getCollection()->pluck('id')->all()),
            'tokens'      => $tokens,
            'activeView'  => $this->activeView($query),
            'query'       => $query,
        ]);
    }

    /**
     * Twenty-four hours of five-minute buckets per group, in one query.
     *
     * Read from error_stat_buckets rather than error_events: a sparkline per
     * row drawn from the event table would be twenty-five scans over the
     * largest table in the module on every page load.
     *
     * @param  list<int>             $groupIds
     * @return array<int, list<int>>
     */
    private function sparklines(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $since = Carbon::now()->subDay();

        return ErrorStatBucket::query()
            ->whereIn('error_group_id', $groupIds)
            ->where('bucket_at', '>=', $since)
            ->orderBy('bucket_at')
            ->get(['error_group_id', 'bucket_at', 'count'])
            ->groupBy('error_group_id')
            ->map(fn ($buckets): array => $buckets->pluck('count')->map(fn ($count): int => (int) $count)->all())
            ->all();
    }

    /**
     * Which saved-view tab the current query corresponds to, matched on the
     * stored query string so a hand-typed query that happens to equal a view
     * still highlights it.
     */
    private function activeView(string $query): ?string
    {
        $normalised = trim($query);

        if ($normalised === '') {
            return 'unresolved';
        }

        foreach ($this->savedViews(app(ErrorQueryParser::class)) as $view) {
            if ($view['query'] === $normalised) {
                return $view['slug'];
            }
        }

        return null;
    }

    /**
     * One group, everything about it.
     *
     * Tabs load on request rather than on mount: the supporting payloads are
     * large, and building them for a reader who came to look at the failing
     * line would make the screen slowest exactly when somebody is in a hurry.
     * Only the requested tab's data is queried.
     */
    public function show(
        Request $request,
        ErrorGroup $group,
        DebugReportBuilder $reports,
        CodeHighlighter $highlighter
    ): View {
        $group->load('assignee');

        $latest = $group->events()->latest('created_at')->first();
        $tabs   = $this->detailTabs();
        $tab    = $this->resolveTab((string) $request->query('tab', 'error'), $tabs);

        return view('backend.observability.show', array_merge([
            'group'       => $group,
            'latest'      => $latest,
            'tabs'        => $tabs,
            'activeTab'   => $tab,
            'detailStats' => $this->detailStats($group),
            'releases'    => $this->releases($group),
        ], $this->tabData($tab, $group, $latest, $reports, $highlighter)));
    }

    /**
     * The three questions this screen answers.
     *
     * It used to ask eight, one tab each, and the two that matter — what broke
     * and what to hand somebody fixing it — were the first and the seventh.
     * Everything the retired tabs showed is still here, gathered behind
     * Details; nothing about the error was dropped, only the shelf it sat on.
     *
     * @return array<string, string>
     */
    private function detailTabs(): array
    {
        return [
            'error'   => __('Error'),
            'debug'   => __('Debug report'),
            'details' => __('Details'),
        ];
    }

    /**
     * Where a tab name lands, including the five that no longer exist.
     *
     * Links to a specific tab are pasted into tickets and chat threads, so a
     * retired name has to arrive somewhere sensible rather than silently
     * bouncing to the default. An unknown name still falls through to Error,
     * which is the screen anybody following such a link wanted.
     *
     * @param array<string, string> $tabs
     */
    private function resolveTab(string $tab, array $tabs): string
    {
        if (array_key_exists($tab, $tabs)) {
            return $tab;
        }

        return match ($tab) {
            'summary', 'trace' => 'error',
            'request', 'breadcrumbs', 'occurrences', 'related', 'activity' => 'details',
            default => 'error',
        };
    }

    /**
     * Only what the requested tab needs.
     *
     * @return array<string, mixed>
     */
    private function tabData(
        string $tab,
        ErrorGroup $group,
        ?ErrorEvent $latest,
        DebugReportBuilder $reports,
        CodeHighlighter $highlighter
    ): array {
        return match ($tab) {
            'error'   => $this->errorPanel($latest, $highlighter),
            'debug'   => $this->exportPanel($group, $latest, $reports, $highlighter),
            'details' => $this->supportingPanel($group, $highlighter),
            default   => [],
        };
    }

    /**
     * The default tab: the failing line, the frames around it, the facts a fix
     * has to account for.
     *
     * Every list here is cut to a size that fits on a screen. The full trace
     * and the full context travel in the debug report, which is where they are
     * of use; painting them all is how a detail screen for a busy error ends
     * up taking a second to lay out.
     *
     * @return array<string, mixed>
     */
    private function errorPanel(?ErrorEvent $latest, CodeHighlighter $highlighter): array
    {
        $excerpt = data_get($latest?->context, 'code_excerpt');
        $frames  = (array) data_get($latest?->context, 'stack', []);

        $appFrames = array_values(array_filter(
            $frames,
            static fn ($frame): bool => ($frame['is_app'] ?? false) === true
        ));

        $vendorFrames = array_values(array_filter(
            $frames,
            static fn ($frame): bool => ($frame['is_app'] ?? false) !== true
        ));

        return [
            'excerpt' => $excerpt === null ? null : $highlighter->excerpt(
                (array) data_get($excerpt, 'lines', []),
                (string) data_get($excerpt, 'file', '')
            ) + ['file' => (string) data_get($excerpt, 'file', '')],
            'appFrames'    => array_slice($appFrames, 0, self::VISIBLE_APP_FRAMES),
            'appOmitted'   => max(0, count($appFrames) - self::VISIBLE_APP_FRAMES),
            'vendorFrames' => array_slice($vendorFrames, 0, self::VISIBLE_VENDOR_FRAMES),
            'vendorTotal'  => count($vendorFrames),
            'moneyAtRisk'  => $this->moneyAtRisk($latest),
            'environment'  => $this->environmentRows($latest),
        ];
    }

    /**
     * The supporting tab: everything that used to have a tab of its own.
     *
     * Queried only when it is opened, which is the reason the detail screen
     * was built around tabs in the first place.
     *
     * @return array<string, mixed>
     */
    private function supportingPanel(ErrorGroup $group, CodeHighlighter $highlighter): array
    {
        $latest = $group->events()->latest('created_at')->first();

        return [
            'occurrences'     => $group->events()->latest('created_at')->limit(self::SUPPORTING_ROWS)->get(),
            'retentionNotice' => $this->retentionNotice($group),
            'related'         => $this->related($group),
            'activity'        => $group->deliveries()->latest('created_at')->limit(self::SUPPORTING_ROWS)->get(),
            'breadcrumbs'     => array_slice((array) data_get($latest?->state, 'breadcrumbs', []), 0, self::SUPPORTING_ROWS),
            'queries'         => array_slice((array) data_get($latest?->state, 'queries', []), 0, self::SUPPORTING_ROWS),
            'requestBody'     => $highlighter->plain(
                (string) json_encode(
                    (array) data_get($latest?->request, 'body', []),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                self::PREVIEW_LINES
            ),
        ];
    }

    /**
     * The fintech-specific panel.
     *
     * state.domain_refs is kept whole by the collector because an order id and
     * a wallet id are internal identifiers, not personal data. That is what
     * makes it possible to say how much money is sitting behind this error
     * rather than only that an exception happened.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function moneyAtRisk(?ErrorEvent $latest): array
    {
        $refs = (array) data_get($latest?->state, 'domain_refs', []);

        return [
            ['label' => __('Uncredited'), 'value' => (string) ($refs['uncredited_amount'] ?? '—')],
            ['label' => __('Orders stuck'), 'value' => (string) ($refs['orders_stuck'] ?? '—')],
            ['label' => __('Wallets affected'), 'value' => (string) ($refs['wallets_affected'] ?? '—')],
            ['label' => __('Oldest unpaid'), 'value' => (string) ($refs['oldest_unpaid'] ?? '—')],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function environmentRows(?ErrorEvent $latest): array
    {
        return [
            __('Release')     => data_get($latest?->context, 'release'),
            __('Commit')      => Str::limit((string) data_get($latest?->context, 'commit_sha'), 7, ''),
            __('Branch')      => data_get($latest?->context, 'branch'),
            __('Environment') => data_get($latest?->context, 'environment'),
            __('PHP')         => data_get($latest?->context, 'php'),
            __('Laravel')     => data_get($latest?->context, 'laravel'),
            __('Host')        => data_get($latest?->context, 'host'),
        ];
    }

    /**
     * @return array<int, array{tag: string, affected: bool}>
     */
    private function releases(ErrorGroup $group): array
    {
        $affected = array_values(array_unique(array_filter([$group->first_release, $group->last_release])));

        return array_map(
            static fn (string $tag): array => ['tag' => $tag, 'affected' => true],
            $affected
        );
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function detailStats(ErrorGroup $group): array
    {
        return [
            ['label' => __('Events'), 'value' => number_format($group->event_count)],
            ['label' => __('Users'), 'value' => number_format($group->affected_user_count)],
            ['label' => __('Status'), 'value' => $group->status->label()],
            ['label' => __('First seen'), 'value' => (string) $group->first_seen?->diffForHumans()],
        ];
    }

    /**
     * Errors from the same source that share an exception class.
     *
     * @return Collection<int, ErrorGroup>
     */
    private function related(ErrorGroup $group)
    {
        return ErrorGroup::query()
            ->where('id', '!=', $group->id)
            ->where('source', $group->source)
            ->where('exception_class', $group->exception_class)
            ->orderByDesc('last_seen')
            ->limit(10)
            ->get();
    }

    /**
     * Said in place of a missing occurrence, so a reader knows the row was
     * deleted on purpose rather than lost.
     */
    private function retentionNotice(ErrorGroup $group): ?string
    {
        if ($group->events()->count() >= $group->event_count) {
            return null;
        }

        return __('Older events were removed by your :days-day retention setting.', [
            'days' => (int) setting('observability_retention_days', 30),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportPanel(
        ErrorGroup $group,
        ?ErrorEvent $latest,
        DebugReportBuilder $reports,
        CodeHighlighter $highlighter
    ): array {
        $blocked = $reports->isBlocked($latest);
        $report  = $blocked ? '' : $reports->toMarkdown($group, $latest);

        /*
         * The preview is a look at the first screen of the report, not the
         * report. What the buttons hand over is the whole of it either way, so
         * the line the reader is told about is the honest one to state.
         */
        $preview   = $highlighter->plain($report, self::PREVIEW_LINES);
        $lineCount = $report === '' ? 0 : substr_count($report, "\n") + 1;

        return [
            'exportBlocked'   => $blocked,
            'reportPreview'   => $preview,
            'reportLines'     => $lineCount,
            'reportSize'      => strlen($report),
            'redactionFields' => (array) data_get($latest?->redaction_report, 'fields', []),
        ];
    }

    /**
     * The DK-DOMAIN-NNNN registry, reachable from every code chip.
     */
    public function codes(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('backend.observability.codes', [
            'search' => $search,
            'codes'  => ErrorGroup::query()
                ->whereNotNull('error_code')
                ->when($search !== '', fn ($query) => $query->where('error_code', 'like', '%'.$search.'%'))
                ->orderBy('error_code')
                ->get(['error_code', 'title', 'message', 'level', 'source', 'event_count'])
                ->unique('error_code')
                ->values(),
        ]);
    }

    /**
     * The delivery-log filters, each naming the statuses it covers.
     *
     * "Held" gathers every attempt that was deliberately not sent, because to
     * the reader they share one meaning: the router chose silence, the channel
     * did not fail.
     *
     * @var array<string, array<int, string>>
     */
    private const LOG_FILTERS = [
        'sent'   => [ErrorDeliveryLog::STATUS_SENT],
        'failed' => [ErrorDeliveryLog::STATUS_FAILED, ErrorDeliveryLog::STATUS_RETRYING],
        'held'   => [
            ErrorDeliveryLog::STATUS_THROTTLED,
            ErrorDeliveryLog::STATUS_QUIET_HOURS,
            ErrorDeliveryLog::STATUS_DEDUPED,
            ErrorDeliveryLog::STATUS_SAMPLED_OUT,
        ],
    ];

    /**
     * Where errors go, and how often they are allowed to repeat.
     */
    public function channels(Request $request, AlertRouter $router): View
    {
        $configured = ErrorNotificationChannel::query()->get()->keyBy('key');
        $since      = now()->subDay();

        $sentToday = ErrorDeliveryLog::query()
            ->where('status', ErrorDeliveryLog::STATUS_SENT)
            ->where('created_at', '>=', $since)
            ->selectRaw('channel_key, count(*) as total')
            ->groupBy('channel_key')
            ->pluck('total', 'channel_key');

        $cards = collect($router->adapters())
            ->map(function ($adapter, string $key) use ($configured, $sentToday): array {
                $channel   = $configured->get($key);
                $connected = (bool) $channel?->is_enabled;
                $status    = $channel?->status ?? ErrorNotificationChannel::STATUS_NOT_CONNECTED;

                return [
                    'key'         => $key,
                    'label'       => $adapter->label(),
                    'channel'     => $channel,
                    'connected'   => $connected,
                    'status'      => $status,
                    'lastSeen'    => $channel?->last_delivery_at,
                    'lastMs'      => $channel?->last_delivery_ms,
                    'failures'    => (int) ($channel?->consecutive_failures ?? 0),
                    'sentToday'   => (int) ($sentToday[$key] ?? 0),
                    'lastFailure' => $connected && in_array($status, [ErrorNotificationChannel::STATUS_FAILING, ErrorNotificationChannel::STATUS_RETRYING], true)
                        ? $this->lastFailure($key)
                        : null,
                    'fields' => $adapter->credentialFields(),
                ];
            })
            ->values()
            ->all();

        $logFilter = array_key_exists((string) $request->query('log'), self::LOG_FILTERS)
            ? (string) $request->query('log')
            : 'all';

        $previewGroup = $this->sampleGroupForTest();

        return view('backend.observability.channels', [
            'cards'       => $cards,
            'connected'   => collect($cards)->where('connected', true)->count(),
            'alerting'    => $this->alertingState($cards),
            'headerStats' => $this->channelStats($cards, $since),
            'routing'     => $this->routingRows($router),
            'preview'     => $router->body($previewGroup),
            'previewMeta' => $this->previewMeta($previewGroup),
            'quiet'       => $this->quietWindow(),
            'logFilter'   => $logFilter,
            'logCounts'   => $this->logCounts(),
            'log'         => ErrorDeliveryLog::query()
                ->with('group:id,level')
                ->when($logFilter !== 'all', fn ($query) => $query->whereIn('status', self::LOG_FILTERS[$logFilter]))
                ->latest('created_at')
                ->limit(25)
                ->get(),
        ]);
    }

    /**
     * The most recent rejection a channel received, for the card to quote.
     */
    private function lastFailure(string $key): ?ErrorDeliveryLog
    {
        return ErrorDeliveryLog::query()
            ->where('channel_key', $key)
            ->whereIn('status', self::LOG_FILTERS['failed'])
            ->latest('created_at')
            ->first(['id', 'channel_key', 'status', 'response_code', 'response_summary', 'created_at']);
    }

    /**
     * The one answer the screen exists for: if something breaks now, does
     * anybody find out?
     *
     * "Down" is every connected channel failing and none delivering, which is
     * the same silence as having nothing connected, only less obvious — the
     * old strip counted a failing channel as "receiving alerts".
     *
     * @param  array<int, array<string, mixed>>                                                             $cards
     * @return array{state: string, attention: array<int, array{key: string, label: string}>, healthy: int}
     */
    private function alertingState(array $cards): array
    {
        $connected = collect($cards)->where('connected', true);
        $healthy   = $connected->where('status', ErrorNotificationChannel::STATUS_CONNECTED);
        $attention = $connected
            ->reject(fn (array $card): bool => $card['status'] === ErrorNotificationChannel::STATUS_CONNECTED)
            ->map(fn (array $card): array => ['key' => $card['key'], 'label' => $card['label']])
            ->values();

        $state = match (true) {
            $connected->isEmpty()                                                                           => 'silent',
            $healthy->isEmpty() && $connected->contains('status', ErrorNotificationChannel::STATUS_FAILING) => 'down',
            $attention->isNotEmpty()                                                                        => 'degraded',
            default                                                                                         => 'healthy',
        };

        return ['state' => $state, 'attention' => $attention->all(), 'healthy' => $healthy->count()];
    }

    /**
     * The header strip: reach, and what the last day of attempts did.
     *
     * @param  array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private function channelStats(array $cards, Carbon $since): array
    {
        $today = ErrorDeliveryLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $countOf = fn (string $filter): int => (int) collect(self::LOG_FILTERS[$filter])
            ->sum(fn (string $status): int => (int) ($today[$status] ?? 0));

        $failed      = $countOf('failed');
        $connected   = collect($cards)->where('connected', true)->count();
        $lastSuccess = ErrorDeliveryLog::query()
            ->where('status', ErrorDeliveryLog::STATUS_SENT)
            ->latest('created_at')
            ->first(['id', 'created_at'])
            ?->created_at;

        return [
            [
                'label' => __('Channels connected'),
                'value' => $connected.' / '.count($cards),
                'note'  => $connected === 0
                    ? __('Connect one below')
                    : collect($cards)->where('connected', true)->pluck('label')->join(', '),
            ],
            [
                'label' => __('Delivered · 24h'),
                'value' => $countOf('sent'),
                'note'  => $failed > 0 ? trans_choice('{1}:count rejected|[2,*]:count rejected', $failed, ['count' => $failed]) : null,
                'tone'  => $failed > 0 ? 'danger' : 'neutral',
                'dot'   => $failed > 0,
            ],
            [
                'label' => __('Held or throttled · 24h'),
                'value' => $countOf('held'),
                'note'  => __('Within repeat limits and quiet hours'),
            ],
            [
                'label' => __('Last successful delivery'),
                'value' => $lastSuccess?->diffForHumans() ?? __('Never'),
                'note'  => $lastSuccess?->format('M j, H:i'),
            ],
        ];
    }

    /**
     * The parts of an alert each channel lays out differently.
     *
     * The body is the same string everywhere; what changes per dialect is the
     * title line, the colour bar, the fields and the buttons around it, which
     * the adapters build from these same values.
     *
     * @return array{badge: string, title: string, events: int, users: int, source: string, url: ?string}
     */
    private function previewMeta(ErrorGroup $group): array
    {
        return [
            'badge'  => $group->level->badge(),
            'title'  => $group->level->badgeLabel().' · '.$group->title,
            'events' => (int) $group->event_count,
            'users'  => (int) $group->affected_user_count,
            'source' => $group->source->chipLabel(),
            'url'    => $group->exists ? route('admin.observability.issues.show', $group) : null,
        ];
    }

    /**
     * How many attempts each delivery-log filter holds.
     *
     * @return array<string, int>
     */
    private function logCounts(): array
    {
        $byStatus = ErrorDeliveryLog::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = ['all' => (int) $byStatus->sum()];

        foreach (self::LOG_FILTERS as $filter => $statuses) {
            $counts[$filter] = (int) collect($statuses)->sum(fn (string $status): int => (int) ($byStatus[$status] ?? 0));
        }

        return $counts;
    }

    /**
     * The severity matrix, read from the router rather than restated here.
     *
     * The previous version of this method described the routing in prose that
     * nothing enforced, and it had already drifted: it named channels the
     * router never contacted. Reading the real constant means the table cannot
     * promise behaviour the code does not have.
     *
     * `frequency` is a 0–4 rank for the meter beside each row: the shortest
     * repeat window fills all four segments, each longer window one fewer,
     * and a severity that never alerts fills none. It is derived from the same
     * throttles, so the meter cannot disagree with the sentence next to it.
     *
     * @return array<int, array{badge: string, level: ErrorLevel, label: string, throttle: string, quiet: string, quietIgnored: bool, frequency: int}>
     */
    private function routingRows(AlertRouter $router): array
    {
        $rules = $router->routing();

        $windows = collect($rules)
            ->where('alerts', true)
            ->pluck('throttle')
            ->unique()
            ->sort()
            ->values();

        return array_map(static function (array $rule) use ($windows): array {
            $rank = $rule['alerts'] ? $windows->search($rule['throttle']) : false;

            return [
                'badge'    => $rule['badge'],
                'level'    => ErrorLevel::fromName(ErrorLevel::namesForBadge($rule['badge'])[0] ?? $rule['badge']),
                'label'    => mb_strtoupper($rule['badge']),
                'throttle' => $rule['alerts']
                    ? self::humanInterval($rule['throttle'])
                    : __('Never sent'),
                'quiet' => $rule['alerts']
                    ? ($rule['quiet_hours'] ? __('Held until quiet hours end') : __('Quiet hours ignored'))
                    : __('Dashboard only'),
                'quietIgnored' => $rule['alerts'] && ! $rule['quiet_hours'],
                'frequency'    => $rank === false ? 0 : max(1, 4 - $rank),
            ];
        }, $rules);
    }

    /**
     * A throttle window in the unit a person would say it in.
     *
     * The router stores minutes because that is what it compares against;
     * "at most once every 240 minutes" is arithmetic the reader should not
     * have to do.
     */
    private static function humanInterval(int $minutes): string
    {
        if ($minutes >= 60 && $minutes % 60 === 0) {
            return trans_choice(
                '{1}At most once an hour|[2,*]At most once every :count hours',
                intdiv($minutes, 60),
                ['count' => intdiv($minutes, 60)]
            );
        }

        return trans_choice(
            '{1}At most once a minute|[2,*]At most once every :count minutes',
            $minutes,
            ['count' => $minutes]
        );
    }

    /**
     * The configured quiet window, or null when alerts run around the clock.
     */
    private function quietWindow(): ?string
    {
        $from = (string) setting('observability_quiet_hours_from', '');
        $to   = (string) setting('observability_quiet_hours_to', '');

        return $from !== '' && $to !== '' ? $from.' – '.$to : null;
    }

    /**
     * Save the credentials for one channel and switch it on.
     *
     * Connecting does not claim the channel works — only a delivery can do
     * that. The status stays "not connected" until a test or a real alert
     * succeeds, so a mistyped token reads as untested rather than as ready.
     */
    public function connectChannel(ConnectChannelRequest $request, AlertRouter $router, string $key): RedirectResponse
    {
        $adapter = $router->adapter($key);

        if ($adapter === null) {
            abort(404);
        }

        ErrorNotificationChannel::query()->updateOrCreate(
            ['key' => $key],
            [
                'driver'               => $key,
                'label'                => $adapter->label(),
                'is_enabled'           => true,
                'credentials'          => $request->credentials(),
                'status'               => ErrorNotificationChannel::STATUS_NOT_CONNECTED,
                'consecutive_failures' => 0,
                'paused_until'         => null,
            ]
        );

        notifyEvs('success', __('Saved. Send a test to confirm it works.'));

        return back();
    }

    /**
     * Send one real message through the channel.
     *
     * It goes through the same adapter, template and payload a genuine alert
     * uses, so a passing test means the next incident will actually arrive
     * rather than only that the credentials parse.
     */
    public function testChannel(AlertRouter $router, string $key): RedirectResponse
    {
        $adapter = $router->adapter($key);
        $channel = ErrorNotificationChannel::query()->where('key', $key)->first();

        if ($adapter === null || $channel === null) {
            abort(404);
        }

        $group  = $this->sampleGroupForTest();
        $result = $this->deliverTest($adapter, $channel, $group, $router->body($group));

        if ($result->delivered) {
            notifyEvs('success', __('Test message delivered in :ms ms.', ['ms' => $result->durationMs]));

            return back();
        }

        notifyEvs('error', __(':channel did not accept the message (:status).', [
            'channel' => $adapter->label(),
            'status'  => $result->statusCode ?? __('no response'),
        ]));

        return back();
    }

    /**
     * Send the same test through every connected channel at once.
     *
     * The header's answer to "would anybody hear about it right now?". Each
     * channel is tested and recorded exactly as its own Send test button
     * would, so one failing webhook cannot hide behind another that passed.
     */
    public function testAllChannels(AlertRouter $router): RedirectResponse
    {
        $channels = ErrorNotificationChannel::query()
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (ErrorNotificationChannel $channel): bool => $router->adapter($channel->key) !== null);

        if ($channels->isEmpty()) {
            notifyEvs('error', __('No channel is connected yet. Connect one before sending a test.'));

            return back();
        }

        $group  = $this->sampleGroupForTest();
        $body   = $router->body($group);
        $failed = [];

        foreach ($channels as $channel) {
            $adapter = $router->adapter($channel->key);

            if (! $this->deliverTest($adapter, $channel, $group, $body)->delivered) {
                $failed[] = $adapter->label();
            }
        }

        if ($failed === []) {
            notifyEvs('success', trans_choice(
                '{1}Test delivered to the connected channel.|[2,*]Test delivered to all :count connected channels.',
                $channels->count(),
                ['count' => $channels->count()]
            ));

            return back();
        }

        notifyEvs('error', __('Test delivered to :ok of :total channels. Not accepted by: :channels.', [
            'ok'       => $channels->count() - count($failed),
            'total'    => $channels->count(),
            'channels' => implode(', ', $failed),
        ]));

        return back();
    }

    /**
     * One test delivery: send it, log it, and move the channel's health to
     * whatever the provider answered.
     */
    private function deliverTest(ChannelAdapter $adapter, ErrorNotificationChannel $channel, ErrorGroup $group, string $body): ChannelResult
    {
        $result = $adapter->send($group, $body, $channel);
        $key    = $channel->key;

        ErrorDeliveryLog::query()->create([
            'error_group_id'   => $group->exists ? $group->id : null,
            'channel_key'      => $key,
            'status'           => $result->delivered ? ErrorDeliveryLog::STATUS_SENT : ErrorDeliveryLog::STATUS_FAILED,
            'attempt'          => 1,
            'response_code'    => $result->statusCode,
            'response_summary' => $result->summary,
            'duration_ms'      => $result->durationMs,
            'created_at'       => now(),
        ]);

        $channel->update([
            'status' => $result->delivered
                ? ErrorNotificationChannel::STATUS_CONNECTED
                : ErrorNotificationChannel::STATUS_FAILING,
            'consecutive_failures' => $result->delivered ? 0 : $channel->consecutive_failures + 1,
            'last_delivery_at'     => now(),
            'last_delivery_status' => $result->delivered ? ErrorDeliveryLog::STATUS_SENT : ErrorDeliveryLog::STATUS_FAILED,
            'last_delivery_ms'     => $result->durationMs,
        ]);

        return $result;
    }

    /**
     * Switch a channel off and forget its credentials.
     *
     * The delivery log is kept: it is the record of what was sent while the
     * channel was live, and an incident review needs it after somebody has
     * disconnected the channel in a hurry.
     */
    public function disconnectChannel(string $key): RedirectResponse
    {
        $channel = ErrorNotificationChannel::query()->where('key', $key)->first();

        if ($channel === null) {
            abort(404);
        }

        $channel->update([
            'is_enabled'           => false,
            'credentials'          => null,
            'status'               => ErrorNotificationChannel::STATUS_NOT_CONNECTED,
            'consecutive_failures' => 0,
            'paused_until'         => null,
        ]);

        notifyEvs('success', __('Disconnected. This channel will not be alerted any more.'));

        return back();
    }

    /**
     * The error a test message describes.
     *
     * A real group is preferred so the test looks exactly like the thing an
     * administrator will receive at 3am. Only when the installation has never
     * captured anything does it fall back to an unsaved stand-in.
     */
    private function sampleGroupForTest(): ErrorGroup
    {
        $group = ErrorGroup::query()->latest('last_seen')->first();

        if ($group !== null) {
            return $group;
        }

        return new ErrorGroup([
            'group_id'            => 'test',
            'title'               => __('Test alert'),
            'message'             => __('This is a test message from your error tracking setup.'),
            'level'               => ErrorLevel::Error,
            'source'              => ErrorSource::Manual,
            'status'              => ErrorStatus::New,
            'event_count'         => 1,
            'affected_user_count' => 0,
        ]);
    }

    /**
     * Mark the error fixed.
     *
     * Resolving does not delete anything. If the same fingerprint fires again
     * the collector reopens the group as regressed, which is a louder state
     * than new precisely because somebody already believed it was handled.
     */
    public function resolve(ErrorGroup $group): RedirectResponse
    {
        $group->update([
            'status'           => ErrorStatus::Resolved,
            'resolved_at'      => now(),
            'resolved_release' => $group->last_release,
        ]);

        notifyEvs('success', __('Resolved. It will reopen as regressed if it happens again.'));

        return back();
    }

    /**
     * Stop alerting for this error.
     *
     * Ignore is not delete: capture continues and the counters keep moving,
     * so an ignored error that turns into a storm is still visible on the
     * list. Only the notifications stop.
     */
    public function ignore(ErrorGroup $group): RedirectResponse
    {
        $group->update(['status' => ErrorStatus::Ignored]);

        notifyEvs('success', __('Ignored. This error is still captured, but nobody will be alerted about it.'));

        return back();
    }

    public function assign(AssignIssueRequest $request, ErrorGroup $group): RedirectResponse
    {
        $group->update([
            'assignee_id' => $request->validated('assignee_id'),
            'status'      => $group->status === ErrorStatus::New ? ErrorStatus::Triaged : $group->status,
        ]);

        notifyEvs('success', __('Assigned.'));

        return back();
    }

    /**
     * Resolve, ignore or assign several groups at once.
     *
     * Under fifty this runs inline, because a queue worker that is not running
     * would otherwise turn a visible action into silence. Above fifty it is
     * dispatched, because a synchronous update of thousands of rows would hold
     * the request open long enough to look broken.
     */
    public function bulk(BulkIssueActionRequest $request): RedirectResponse
    {
        $groupIds = $request->validated('groups');
        $action   = $request->validated('action');

        if (count($groupIds) > self::INLINE_BULK_LIMIT) {
            ApplyBulkIssueAction::dispatch($groupIds, $action, auth('admin')->id());

            notifyEvs('success', __('Working on :count errors in the background.', ['count' => count($groupIds)]));

            return back();
        }

        (new ApplyBulkIssueAction($groupIds, $action, auth('admin')->id()))->handle();

        notifyEvs('success', __('Updated :count errors.', ['count' => count($groupIds)]));

        return back();
    }

    /**
     * The debug report, built at export time so a redaction-rule change
     * applies retroactively to every future export.
     *
     * Strict mode refuses rather than guesses: an error carrying a field the
     * pipeline could not classify is blocked with an explanation, because a
     * bundle nobody can vouch for is worse than no bundle.
     */
    public function export(Request $request, ErrorGroup $group, DebugReportBuilder $reports): Response
    {
        $latest = $group->events()->latest('created_at')->first();

        if ($reports->isBlocked($latest)) {
            return response(
                __('Export blocked. This error contains a field your redaction rules cannot classify. Review it, then export again.'),
                422
            )->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $json = $request->query('format') === 'json';

        $body = $json
            ? (string) json_encode($reports->build($group, $latest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $reports->toMarkdown($group, $latest);

        $response = response($body)->header(
            'Content-Type',
            $json ? 'application/json; charset=utf-8' : 'text/markdown; charset=utf-8'
        );

        if ($request->boolean('download')) {
            $response->header(
                'Content-Disposition',
                'attachment; filename="'.$group->group_id.'.'.($json ? 'json' : 'md').'"'
            );
        }

        return $response;
    }

    /**
     * The four figures in the System Health page header.
     *
     * Every one of these is a bounded query over error_groups, which has one
     * row per bug rather than one per occurrence. The equivalent figures read
     * off error_events would be the same numbers at a cost that grows with
     * traffic.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function headerStats(): array
    {
        $unresolved = ErrorGroup::query()->open()->count();

        $newToday = ErrorGroup::query()
            ->where('status', ErrorStatus::New)
            ->where('first_seen', '>=', Carbon::now()->startOfDay())
            ->count();

        $regressed = ErrorGroup::query()
            ->where('status', ErrorStatus::Regressed)
            ->count();

        $fatal = ErrorGroup::query()
            ->whereIn('level', ErrorLevel::namesForBadge('fatal'))
            ->where('last_seen', '>=', Carbon::now()->subDay())
            ->count();

        return [
            ['label' => __('Unresolved'), 'value' => (string) $unresolved],
            ['label' => __('New today'), 'value' => (string) $newToday],
            ['label' => __('Regressed'), 'value' => (string) $regressed],
            ['label' => __('Fatal, 24h'), 'value' => (string) $fatal],
        ];
    }

    /**
     * The tab strip above the filter bar.
     *
     * Defined here rather than stored, because every one of these is a query
     * this module already understands and none of them is worth a table, a
     * migration and a CRUD screen to reproduce. An administrator who wants a
     * different filter edits the search box; the URL carries it.
     *
     * The counts run through the same parser the list uses, so a tab can never
     * promise a number the filter behind it would not produce. They are counts
     * over error_groups, which holds one row per bug rather than one per
     * occurrence, and they are cached for a minute so a busy panel does not
     * repeat five aggregates on every render.
     *
     * @return array<int, array{slug: string, label: string, query: string, count: int}>
     */
    private function savedViews(ErrorQueryParser $parser): array
    {
        $definitions = [
            ['slug' => 'unresolved', 'label' => __('All unresolved'), 'query' => 'is:unresolved'],
            ['slug' => 'mine', 'label' => __('Assigned to me'), 'query' => 'is:unresolved assigned:me'],
            ['slug' => 'gateways', 'label' => __('Payment gateways'), 'query' => 'is:unresolved source:gateway'],
            ['slug' => 'new-24h', 'label' => __('New in 24h'), 'query' => 'is:new age:-24h'],
            ['slug' => 'regressions', 'label' => __('Regressions'), 'query' => 'is:regressed'],
        ];

        $adminId = auth('admin')->id();

        return Cache::remember(
            'observability.views.'.$adminId,
            now()->addMinute(),
            static function () use ($definitions, $parser, $adminId): array {
                return array_map(static function (array $view) use ($parser, $adminId): array {
                    $builder = ErrorGroup::query();
                    $parser->apply($builder, $parser->parse($view['query']), $adminId);

                    return $view + ['count' => $builder->count()];
                }, $definitions);
            }
        );
    }
}
