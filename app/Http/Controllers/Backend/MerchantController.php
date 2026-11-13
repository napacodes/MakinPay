<?php

namespace App\Http\Controllers\Backend;

use App\Enums\EnvironmentMode;
use App\Enums\MerchantStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Requests\Merchant\MerchantActionRequest;
use App\Models\Merchant;
use App\Notifications\TemplateNotification;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MerchantController extends BaseController
{
    /**
     * How long a merchant request may wait before the queue calls it late.
     *
     * A display threshold, not a policy: nothing is approved, rejected or
     * escalated by it, it only decides which tone the wait is drawn in. If an
     * operator ever wants to choose the number, it belongs in admin settings
     * rather than here — but a switch nobody asked for is a switch that has to
     * be maintained.
     */
    private const REVIEW_SLA_HOURS = 48;

    public static function permissions(): array
    {
        return [
            'index|pendingMerchant|approvedMerchant|rejectedMerchant|review' => 'merchant-list',
            'merchantAction'                                                 => 'merchant-manage',
        ];
    }

    public function index(Request $request): View
    {
        return $this->merchantScreen($request, null, __('Merchant List'));
    }

    /**
     * The queue is not the list with a filter on it. An admin here is deciding,
     * not browsing: rows are oldest first, the wait is a column of its own, and
     * the description — the thing the decision is actually made on — is on the
     * row instead of behind a toggle inside the modal.
     */
    public function pendingMerchant(Request $request): View
    {
        $merchants = Merchant::query()
            ->with(['user', 'currency', 'supportedCurrencies'])
            ->where('status', MerchantStatus::PENDING)
            ->filter($request)
            ->oldest()
            ->paginate(10)
            ->withQueryString();

        return view('backend.merchant.pending', [
            'merchants' => $merchants,
            'title'     => __('Pending Merchant'),
            'scope'     => MerchantStatus::PENDING->value,
            'counts'    => $this->scopeCounts(),
            'queue'     => $this->pendingQueueStats(),
            'slaHours'  => self::REVIEW_SLA_HOURS,
        ]);
    }

    /**
     * Whole-queue figures, computed ahead of the paginator so page two reports
     * what page one reports.
     *
     * @return array{waiting:int, breaching:int, oldest_wait:?string, no_description:int}
     */
    private function pendingQueueStats(): array
    {
        $pending  = Merchant::query()->where('status', MerchantStatus::PENDING);
        $oldestAt = (clone $pending)->min('created_at');

        return [
            'waiting'   => (clone $pending)->count(),
            'breaching' => (clone $pending)
                ->where('created_at', '<', now()->subHours(self::REVIEW_SLA_HOURS))
                ->count(),
            'oldest_wait'    => $oldestAt ? Carbon::parse($oldestAt)->diffForHumans(null, true) : null,
            'no_description' => (clone $pending)
                ->where(fn ($query) => $query->whereNull('business_description')->orWhere('business_description', ''))
                ->count(),
        ];
    }

    public function approvedMerchant(Request $request): View
    {
        return $this->merchantScreen($request, MerchantStatus::APPROVED, __('Approved Merchant'));
    }

    public function rejectedMerchant(Request $request): View
    {
        return $this->merchantScreen($request, MerchantStatus::REJECTED, __('Rejected Merchant'));
    }

    /**
     * The four scopes rendered one screen with a different title and nothing
     * else; the status filter was even hidden on three of them, so which slice
     * you were looking at was unreachable from the page. They are tabs now, and
     * every one of them needs the same counts and totals beside them.
     */
    private function merchantScreen(Request $request, ?MerchantStatus $status, string $title): View
    {
        $merchants = Merchant::query()
            ->with(['user', 'currency', 'supportedCurrencies'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->filter($request)
            ->paginate(10)
            ->withQueryString();

        return view('backend.merchant.index', [
            'merchants' => $merchants,
            'title'     => $title,
            'scope'     => $status?->value ?? 'all',
            'counts'    => $this->scopeCounts(),
            'stats'     => $this->merchantStats(),
        ]);
    }

    /**
     * One grouped query, not five counts. Deliberately unfiltered: a tab's
     * count says how big that slice is, not how much of it the current search
     * happens to match.
     *
     * @return array<string, int>
     */
    private function scopeCounts(): array
    {
        $byStatus = Merchant::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                ($row->status instanceof MerchantStatus ? $row->status->value : (string) $row->status) => (int) $row->aggregate,
            ]);

        $counts = ['all' => (int) $byStatus->sum()];

        foreach (MerchantStatus::cases() as $case) {
            $counts[$case->value] = $byStatus->get($case->value, 0);
        }

        return $counts;
    }

    /**
     * Figures the tabs cannot express. Every one runs over the whole table
     * rather than the paginated page, so they do not change on page two.
     *
     * avg_fee and avg_fee_fixed are display figures — a headline average of
     * what merchants are charged — never an input to a charge. Nothing
     * settles money from them.
     *
     * @return array{total:int, pending:int, oldest_wait:?string, live:int, live_share:int, sandbox:int, avg_fee:?float, avg_fee_fixed:?float}
     */
    private function merchantStats(): array
    {
        $total = Merchant::query()->count();

        $pending  = Merchant::query()->where('status', MerchantStatus::PENDING);
        $oldestAt = (clone $pending)->min('created_at');

        $live      = Merchant::query()->where('status', MerchantStatus::APPROVED);
        $liveCount = (clone $live)->count();

        return [
            'total'         => $total,
            'pending'       => (clone $pending)->count(),
            'oldest_wait'   => $oldestAt ? Carbon::parse($oldestAt)->diffForHumans(null, true) : null,
            'live'          => $liveCount,
            'live_share'    => $total > 0 ? (int) round($liveCount / $total * 100) : 0,
            'sandbox'       => (clone $live)->where('current_mode', EnvironmentMode::SANDBOX)->count(),
            'avg_fee'       => (clone $live)->avg('fee'),
            'avg_fee_fixed' => (clone $live)->avg('fee_fixed'),
        ];
    }

    /**
     * One merchant, one page, four states.
     *
     * The decision used to happen in a modal rendered once per table row, so
     * old() and @error — which are per-request, not per-modal — prefilled and
     * flagged every merchant on the page after a failed submit. A page cannot
     * have that problem, and it has room for the evidence the decision is
     * actually made on.
     */
    public function review(Merchant $merchant): View
    {
        $merchant->load(['user.kycSubmission', 'currency', 'supportedCurrencies', 'paymentMethods']);

        return view('backend.merchant.review', [
            'title'    => $merchant->business_name,
            'merchant' => $merchant,
            'checks'   => $this->reviewChecks($merchant),
            'preview'  => $merchant->feePreview(),
        ]);
    }

    /**
     * What the platform already knows about this request.
     *
     * Every one of these is computed from data the app holds. Nothing here is
     * a score, a guess or a reachability probe — an admin deciding whether to
     * let a business take money should not be shown a number the system cannot
     * actually stand behind.
     *
     * @return list<array{label: string, detail: string, tone: string}>
     */
    private function reviewChecks(Merchant $merchant): array
    {
        $user = $merchant->user;

        $siteHost  = $this->hostOf($merchant->site_url);
        $emailHost = Str::lower(trim(Str::after((string) $merchant->business_email, '@')));

        $domainKnown = $siteHost !== null && $emailHost !== '';
        $domainMatch = $domainKnown
            && ($emailHost === $siteHost || Str::endsWith($emailHost, '.'.$siteHost));

        $domainCheck = [
            'label'  => $domainMatch ? __('Email matches site') : __('Email does not match site'),
            'detail' => $domainKnown ? $siteHost : __('No site URL to compare'),
            'tone'   => $domainMatch ? 'pass' : ($domainKnown ? 'warn' : 'neutral'),
        ];

        if ($user === null) {
            return [
                [
                    'label'  => __('Owner account deleted'),
                    'detail' => __('There is no account to settle to, so it cannot go live'),
                    'tone'   => 'warn',
                ],
                $domainCheck,
            ];
        }

        $otherProfiles = $user->merchants()->where('id', '!=', $merchant->id)->count();

        return [
            [
                'label'  => $user->isKycVerified() ? __('KYC verified') : __('KYC not verified'),
                'detail' => $user->isKycVerified()
                    ? __('Owner identity confirmed')
                    : __('The owner has not passed verification'),
                'tone' => $user->isKycVerified() ? 'pass' : 'warn',
            ],
            $domainCheck,
            [
                'label'  => __('Account :age old', ['age' => $user->created_at?->diffForHumans(null, true) ?? '—']),
                'detail' => __('Joined :date', ['date' => $user->created_at?->format('Y-m-d') ?? '—']),
                'tone'   => 'neutral',
            ],
            [
                'label' => $otherProfiles === 0
                    ? __('First merchant profile')
                    : trans_choice('{1} :count other profile|[2,*] :count other profiles', $otherProfiles, ['count' => $otherProfiles]),
                'detail' => $otherProfiles === 0
                    ? __('No others on this account')
                    : __('Same owner, already on the platform'),
                'tone' => 'neutral',
            ],
        ];
    }

    /** The bare host of a URL, without a leading www. */
    private function hostOf(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url(Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.$url, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? Str::lower(Str::replaceStart('www.', '', $host))
            : null;
    }

    public function merchantAction(MerchantActionRequest $request)
    {
        $validated = $request->validated();

        $merchant = Merchant::findOrFail($validated['merchant_id']);

        $action     = $validated['action'];
        $updateData = [];

        /*
         * A merchant whose owner account is gone has nobody to settle to.
         * Rejecting or pausing it is fine; putting it live is not.
         */
        if ($merchant->user === null && in_array($action, ['approve', 'enable'], true)) {
            throw new NotifyErrorException(__('This merchant has no owner account, so it cannot be approved or enabled.'));
        }

        // Handle approval (includes fee update). If already approved, keep status; if disabled, only update fee.
        if ($action === 'approve') {
            // Update the whole fee shape only on the approve path. Components
            // the form did not send keep their current value, so an older
            // cached Blade posting only `fee` still works.
            $updateData['fee']       = $validated['fee']       ?? $merchant->fee;
            $updateData['fee_fixed'] = $validated['fee_fixed'] ?? $merchant->fee_fixed;
            $updateData['fee_min']   = array_key_exists('fee_min', $validated) ? $validated['fee_min'] : $merchant->fee_min;
            $updateData['fee_max']   = array_key_exists('fee_max', $validated) ? $validated['fee_max'] : $merchant->fee_max;

            if ($merchant->status === MerchantStatus::PENDING) {
                $updateData['status'] = MerchantStatus::APPROVED;

                // Notify only on first-time approval from pending
                $merchant->user?->notify(new TemplateNotification(
                    identifier: 'merchant_user_notify_shop_approved',
                    data: [
                        'business_name' => $merchant->business_name,
                    ],
                    action: route('user.merchant.index'),
                ));
            }
            // If current is APPROVED, we keep it approved; if DISABLED, we do not change status here
        }

        // Handle rejection only when not already rejected
        if ($action === 'reject' && $merchant->status === MerchantStatus::PENDING) {
            // Guaranteed present: the form request makes it required to reject.
            // Stored as well as sent, so the rejected profile can show what the
            // merchant was told and a second admin can see why.
            $reason = trim((string) ($validated['rejection_reason'] ?? ''));

            $updateData['status']           = MerchantStatus::REJECTED;
            $updateData['rejection_reason'] = $reason;

            $merchant->user?->notify(new TemplateNotification(
                identifier: 'merchant_user_notify_shop_rejected',
                data: [
                    'business_name'    => $merchant->business_name,
                    'rejection_reason' => $reason,
                ],
                action: route('user.merchant.index'),
            ));
        }

        // Handle disable: only allowed from APPROVED
        if ($action === 'disable' && $merchant->status === MerchantStatus::APPROVED) {
            $updateData['status'] = MerchantStatus::DISABLED;
        }

        // Handle enable: only allowed from DISABLED
        if ($action === 'enable' && $merchant->status === MerchantStatus::DISABLED) {
            $updateData['status'] = MerchantStatus::APPROVED;
        }

        if (! empty($updateData)) {
            $merchant->update($updateData);
        }

        notifyEvs('success', 'Merchant  updated successfully');

        return back();
    }
}
