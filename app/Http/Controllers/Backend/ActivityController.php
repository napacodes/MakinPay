<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Enums\LoginRisk;
use App\Enums\UserRole;
use App\Http\Requests\Backend\Activity\ActivityLogFilterRequest;
use App\Models\LoginActivity;
use App\Models\User;
use App\Services\Security\LoginActivityDetail;
use App\Services\Security\LoginActivityStats;
use App\Services\UserAccountSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Security → Activity history.
 *
 * Every sign-in the platform recorded, with the network, device and risk
 * context the evaluator attached to it, plus the four actions an operator
 * can take from the drawer. The heavy reading lives in the two services;
 * this class decides what the request asked for and who is allowed to.
 */
class ActivityController extends BaseController
{
    /** The range the screen opens on when the operator has not picked one. */
    private const DEFAULT_RANGE_DAYS = 7;

    public function __construct(
        private readonly LoginActivityStats $stats,
        private readonly LoginActivityDetail $detail,
        private readonly UserAccountSummaryService $accounts,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function permissions(): array
    {
        return [
            'index|show|export'                               => 'user-activity-log',
            'revoke|forceTwoFactor|unblock|signOutAllDevices' => 'user-manage',
        ];
    }

    public function index(ActivityLogFilterRequest $request): View
    {
        /*
         * Arrivals only, customers only — the two scopes this screen has
         * always meant. Refusals are counted in the header strip and listed
         * in the drawer's per-account history, where they answer a question
         * rather than doubling the length of the table.
         */
        $this->withDefaultRange($request);

        $activities = LoginActivity::with('user:id,first_name,last_name,username,role,avatar')
            ->forUsers()
            ->succeeded()
            ->filter($request)
            ->orderByDesc('login_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return view('backend.activity.index', [
            'activities'    => $activities,
            'groups'        => $this->groupByDay($activities->getCollection(), $request),
            'headerStats'   => $this->stats->headerStats(),
            'flaggedToday'  => $this->stats->flaggedToday(),
            'filters'       => $request->activeFilters(),
            'chips'         => $this->chips($request),
            'rangeValue'    => (string) $request->input('range', ''),
            'rangeLabel'    => $this->rangeLabel($request->input('range')),
            'roleFilters'   => ['' => __('All')] + UserRole::options(),
            'riskFilters'   => $this->riskFilters(),
            'perPage'       => $request->perPage(),
            'retentionDays' => (int) setting('login_activity_retention_days', 180),
        ]);
    }

    /**
     * The drawer, as JSON.
     */
    public function show(LoginActivity $activity): JsonResponse
    {
        $activity->loadMissing('user');

        return response()->json($this->detail->payload($activity));
    }

    /**
     * End the one session this row opened.
     */
    public function revoke(LoginActivity $activity): JsonResponse
    {
        if ($activity->session_revoked_at !== null) {
            return $this->answer('warning', __('That session has already ended.'));
        }

        $revoked = $this->accounts->revokeSession($activity);

        $activity->forceFill(['session_revoked_at' => Carbon::now()])->save();

        $this->record('login_activity.session_revoked', ['login_activity_id' => $activity->id, 'revoked' => $revoked]);

        return $this->answer('success', __('Session ended.'));
    }

    /**
     * Sign the account out everywhere and make the next arrival prove itself.
     *
     * Two-factor is only switched on for an account that already holds a
     * secret. The challenge screen has no enrolment path, so turning it on
     * for an account without one would not harden the account — it would
     * lock the person out of it permanently. Those accounts are made to
     * choose a new password instead, which is a re-authentication the
     * account holder can actually complete.
     */
    public function forceTwoFactor(User $user): JsonResponse
    {
        $revoked  = $this->accounts->revokeSessions($user);
        $enrolled = (bool) $user->google2fa_secret;

        $user->forceFill($enrolled
            ? ['two_factor_enabled' => true]
            : ['must_change_password' => true])->save();

        $this->record('login_activity.force_two_factor', [
            'user_id'  => $user->id,
            'revoked'  => $revoked,
            'enrolled' => $enrolled,
        ]);

        return $this->answer('success', $enrolled
            ? __('Signed out everywhere. Two-factor is now required for this account.')
            : __('Signed out everywhere. This account has no authenticator yet, so it must set a new password on the next sign-in.'));
    }

    /**
     * Let a blocked account back in.
     */
    public function unblock(User $user): JsonResponse
    {
        $cleared = LoginActivity::query()
            ->where('user_id', $user->id)
            ->risk(LoginRisk::BLOCKED)
            ->update(['risk' => LoginRisk::NORMAL->value, 'risk_reason' => __('Unblocked by an administrator.')]);

        /*
         * A blocked verdict suspends the account, so letting it back in has
         * to lift that too — clearing the row's label alone would leave the
         * person locked out by a screen that says they are not.
         */
        if ($user->isSuspended()) {
            $user->liftSuspension();
        }

        LoginActivityStats::forget();

        $this->record('login_activity.unblocked', ['user_id' => $user->id, 'rows' => $cleared]);

        return $this->answer('success', __('Account unblocked.'));
    }

    /**
     * End every customer session on the platform.
     */
    public function signOutAllDevices(): RedirectResponse
    {
        $revoked = $this->accounts->revokeAllUserSessions();

        $this->record('login_activity.sign_out_all', ['revoked' => $revoked]);

        notifyEvs('success', trans_choice(
            '{0} No open sessions to end; every remember-me token was rotated.|{1} One session ended.|[2,*] :count sessions ended.',
            $revoked,
            ['count' => $revoked]
        ));

        return redirect()->back();
    }

    /**
     * The current filters as a CSV file.
     */
    public function export(ActivityLogFilterRequest $request): StreamedResponse
    {
        $query = LoginActivity::with('user:id,first_name,last_name,username,role')
            ->forUsers()
            ->succeeded()
            ->filter($this->withDefaultRange($request));

        $filename = 'sign-ins-'.now()->format('Y-m-d-His').'.csv';

        $this->record('login_activity.exported', ['filters' => $request->activeFilters()]);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            /* Excel reads a BOM-less UTF-8 file as the local codepage. */
            fwrite($stream, "\xEF\xBB\xBF");

            fputcsv($stream, [
                'Signed In', 'Name', 'Username', 'Role', 'IP Address', 'City', 'Country',
                'Network', 'Browser', 'Platform', 'Device', 'Method', 'Risk', 'Risk Reason',
            ], escape: '');

            $query->chunkById(500, function ($rows) use ($stream): void {
                foreach ($rows as $row) {
                    fputcsv($stream, array_map($this->csvCell(...), [
                        $row->login_at?->format('Y-m-d H:i:s'),
                        trim(($row->user->first_name ?? '').' '.($row->user->last_name ?? '')),
                        $row->user->username       ?? '',
                        $row->user->role?->title() ?? '',
                        /*
                         * Masked while the demo is on. A CSV is the easiest
                         * of all of these to walk away with.
                         */
                        maskSensitive($row->ip_address, 'ip'),
                        $row->city,
                        $row->country,
                        $row->isp,
                        $row->browser,
                        $row->platform,
                        $row->device_type,
                        $row->method?->label() ?? '',
                        ($row->risk ?? LoginRisk::NORMAL)->label(),
                        $row->risk_reason,
                    ]), escape: '');
                }
            });

            fclose($stream);
        }, $filename, [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
            'Pragma'                 => 'no-cache',
        ]);
    }

    /**
     * A leading formula character makes a spreadsheet execute the cell.
     */
    private function csvCell(mixed $value): string
    {
        $value = str_replace("\0", '', trim((string) $value));

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * The screen opens on the last seven days unless asked otherwise.
     *
     * Applied to the request rather than the query so the picker, the chips
     * and the export all read the same range back.
     */
    private function withDefaultRange(ActivityLogFilterRequest $request): ActivityLogFilterRequest
    {
        /*
         * `has`, not `filled`. An empty `range=` is the operator asking for
         * all of history from the picker's reset button; only an absent key
         * means "you have not chosen, here is the default".
         */
        $range = $request->has('range')
            ? (string) $request->input('range', '')
            : Carbon::today()->subDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString().','.Carbon::today()->toDateString();

        /*
         * `daterange` is the key LoginActivity::scopeFilter shares with every
         * other filtered screen, so the chosen range is mapped onto it here
         * rather than teaching the model a second name for the same thing.
         */
        $request->merge(['range' => $range, 'daterange' => $range]);

        return $request;
    }

    /**
     * The rows of one page, split into the days they happened on.
     *
     * @param  Collection<int, LoginActivity>                                    $rows
     * @return list<array{label: string, count: int, rows: list<LoginActivity>}>
     */
    private function groupByDay($rows, ActivityLogFilterRequest $request): array
    {
        $grouped = $rows->groupBy(fn (LoginActivity $row): string => $row->login_at?->toDateString() ?? '');
        $totals  = $this->dailyTotals($grouped->keys(), $request);

        return $grouped
            ->map(fn ($day, string $date): array => [
                'label' => $this->dayLabel($date),
                /*
                 * The day's real total, not this page's share of it. A day
                 * split across a page boundary would otherwise print "Today ·
                 * 25 sign-ins" then "Today · 5 sign-ins", and neither number
                 * is the answer to the question the header appears to ask.
                 */
                'count' => (int) ($totals[$date] ?? $day->count()),
                'rows'  => $day->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * How many sign-ins each day on this page actually holds.
     *
     * One grouped query bounded by the page's own span, rather than a count
     * per day — the page shows at most a handful of days, but the table it
     * counts grows with every sign-in on the platform.
     *
     * @param  Collection<int, string> $days
     * @return array<string, int>
     */
    private function dailyTotals(Collection $days, ActivityLogFilterRequest $request): array
    {
        $dates = $days->filter()->values();

        if ($dates->isEmpty()) {
            return [];
        }

        return LoginActivity::query()
            ->forUsers()
            ->succeeded()
            ->filter($request)
            ->whereBetween('login_at', [
                Carbon::parse($dates->last())->startOfDay(),
                Carbon::parse($dates->first())->endOfDay(),
            ])
            ->selectRaw('DATE(login_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();
    }

    private function dayLabel(string $date): string
    {
        if ($date === '') {
            return __('Unknown date');
        }

        $day = Carbon::parse($date);

        return match (true) {
            $day->isToday()     => __('Today'),
            $day->isYesterday() => __('Yesterday'),
            default             => $day->format('d M Y'),
        };
    }

    /**
     * What the date button says.
     */
    private function rangeLabel(?string $range): string
    {
        if ((string) $range === '') {
            return __('All time');
        }

        $parts = explode(',', (string) $range);

        if (count($parts) !== 2) {
            return __('Last :count days', ['count' => self::DEFAULT_RANGE_DAYS]);
        }

        $from = Carbon::parse($parts[0]);
        $to   = Carbon::parse($parts[1]);

        if ($from->isSameDay($to)) {
            return $from->isToday() ? __('Today') : $from->format('d M Y');
        }

        if ($to->isToday() && $from->isSameDay(Carbon::today()->subDays(self::DEFAULT_RANGE_DAYS - 1))) {
            return __('Last :count days', ['count' => self::DEFAULT_RANGE_DAYS]);
        }

        return $from->format('d M').' – '.$to->format('d M Y');
    }

    /**
     * The removable filter chips above the table.
     *
     * The date range is not a chip: the screen is always looking at some
     * range, so there is no "off" for that button to return to.
     *
     * @return list<array{key: string, label: string}>
     */
    private function chips(ActivityLogFilterRequest $request): array
    {
        $chips = [];

        if ($role = $request->input('type')) {
            $chips[] = ['key' => 'type', 'label' => __('Role: :value', ['value' => UserRole::options()[$role] ?? $role])];
        }

        if ($risk = $request->input('risk')) {
            $chips[] = ['key' => 'risk', 'label' => __('Risk: :value', ['value' => $this->riskFilters()[$risk] ?? $risk])];
        }

        if ($search = trim((string) $request->input('search'))) {
            $chips[] = ['key' => 'search', 'label' => '“'.$search.'”'];
        }

        return $chips;
    }

    /**
     * @return array<string, string>
     */
    private function riskFilters(): array
    {
        return [
            ''                        => __('All'),
            'flagged'                 => __('Flagged'),
            LoginRisk::BLOCKED->value => __('Blocked'),
        ];
    }

    /**
     * One audit line per action taken from this screen.
     *
     * @param array<string, mixed> $context
     */
    private function record(string $action, array $context): void
    {
        Log::info($action, $context + ['admin_id' => auth('admin')->id()]);
    }

    private function answer(string $type, string $message): JsonResponse
    {
        return response()->json(['type' => $type, 'message' => $message]);
    }
}
