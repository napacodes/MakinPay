<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\BillPayment;

use App\Enums\BillPayment\BillPaymentActorType;
use App\Enums\BillPayment\BillPaymentDispatchState;
use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Enums\BillPayment\BillPaymentOutcome;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\BillPayment\ClaimBillPaymentRequest;
use App\Http\Requests\Backend\BillPayment\ConfirmBillPaymentNotPaidRequest;
use App\Http\Requests\Backend\BillPayment\MarkBillPaymentFailedRequest;
use App\Http\Requests\Backend\BillPayment\MarkBillPaymentPaidRequest;
use App\Models\Admin;
use App\Models\BillCategory;
use App\Models\Biller;
use App\Models\BillPayment;
use App\Models\BillPaymentEvent;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillPaymentActionResult;
use App\Services\BillPayment\BillPaymentCheckOutcome;
use App\Services\BillPayment\BillPaymentService;
use App\Services\BillPayment\BillPaymentStatusChecker;
use App\Services\Dashboard\DashboardQueueService;
use App\Services\Dashboard\DashboardSla;
use App\Support\DecimalMoney;
use App\Support\Money;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The operator's side of bill payments: the queue of bills to pay at the
 * biller, the history of every payment, and one payment's detail with the
 * three things an operator does to it (claim, mark paid, mark failed). A
 * payment sent to a provider can also be checked with that provider, and one
 * in review confirmed not charged and refunded after a recent check.
 *
 * Reading needs bill-payment-list; acting needs bill-payment-action. Every
 * action goes through BillPaymentService, which re-reads the payment under
 * its lock and decides there; what this controller shows about which actions
 * apply is a hint, never the check. The token is decrypted only for an admin
 * allowed to act.
 *
 * Deliberately not behind admin.feature:bill_payment (see routes/admin.php):
 * switching the module off must never hide pending customer money from the
 * only screen that can refund it.
 */
class BillPaymentController extends BaseController
{
    public const PER_PAGE = 20;

    /** History filter `stage`: payments waiting on their provider's answer. */
    public const STAGE_WITH_PROVIDER = 'with_provider';

    /** History filter `stage`: resolved payments a provider answer contradicts. */
    public const STAGE_CONTRADICTION = 'contradiction';

    /**
     * Why a payment sits in review, in the operator's words, keyed by
     * `metadata.review_reason`.
     *
     * @var array<string, string>
     */
    private const REVIEW_REASONS = [
        BillPaymentService::REVIEW_OUTCOME_UNKNOWN             => 'The provider did not give a clear answer. Check the payment with the provider before resolving it.',
        BillPaymentService::REVIEW_AMOUNT_OR_CURRENCY_MISMATCH => 'The provider reported a different amount or currency from the one billed.',
        BillPaymentService::REVIEW_PROVIDER_RESULT_REFUSED     => 'The provider answered, but its answer could not be applied to this payment.',
        BillPaymentService::REVIEW_PROVIDER_REFERENCE_CHANGED  => 'The provider reported a different reference from the one already recorded for this payment. Check the payment with the provider before resolving it.',
        BillPaymentService::REVIEW_PROVIDER_REFERENCE_CONFLICT => 'The provider reported a reference another payment already holds. Check both payments with the provider before resolving this one.',
        BillPaymentService::REVIEW_PROVIDER_UNRESOLVED         => 'The provider gave no final answer in time. Check the payment with the provider before resolving it.',
    ];

    /**
     * A provider answer that contradicts a resolved payment, in the
     * operator's words, keyed by `metadata.provider_contradiction.kind`.
     *
     * @var array<string, string>
     */
    public const CONTRADICTIONS = [
        BillPaymentService::CONTRADICTION_REVERSED_AFTER_SETTLE => 'The provider reported this payment as reversed after it was settled. No money was moved. Check with the provider and resolve it manually.',
        BillPaymentService::CONTRADICTION_PAID_AFTER_REFUND     => 'The provider reported this payment as paid after it was refunded. No money was moved. Check with the provider and resolve it manually.',
    ];

    public function __construct(private readonly BillPaymentService $payments) {}

    public static function permissions(): array
    {
        return [
            'showQueue|index|show'                                       => 'bill-payment-list',
            'claim|markPaid|markFailed|checkWithProvider|confirmNotPaid' => 'bill-payment-action',
        ];
    }

    /**
     * The bills someone has to act on, oldest first.
     *
     * Read through the dashboard's queue scope, which is the one queue
     * definition (BillPayment::awaitingAction()), so this page, the dashboard
     * row and the sidebar badge count the same rows.
     */
    public function showQueue(Request $request, DashboardSla $sla): View
    {
        $admin   = $this->admin($request);
        $filters = $this->filters($request, ['search', 'biller', 'category', 'country', 'mine']);

        $payments = $this->filtered(DashboardQueueService::pendingScope('bill_payment'), $filters)
            ->with(['user', 'biller.category', 'claimedBy', 'transaction'])
            ->when($filters['mine'] === '1', fn (Builder $query): Builder => $query->where('claimed_by_admin_id', $admin->getKey()))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $targetHours = $sla->hoursFor('bill_payment');
        $oldest      = DashboardQueueService::pendingScope('bill_payment')->min('created_at');
        $oldestAt    = $oldest !== null ? Carbon::parse($oldest) : null;

        return view('backend.bill_payment.payments.queue', [
            'payments'    => $payments,
            'rows'        => $this->rowStates($payments->getCollection(), $admin, $sla, $targetHours),
            'filters'     => $filters,
            'targetHours' => $targetHours,
            'stats'       => [
                'awaiting'   => DashboardQueueService::pendingScope('bill_payment')->count(),
                'review'     => DashboardQueueService::pendingScope('bill_payment')->where('dispatch_state', BillPaymentDispatchState::NeedsReview->value)->count(),
                'oldest'     => $oldestAt !== null ? $this->ageLabel($oldestAt) : null,
                'oldestTone' => $oldestAt !== null ? $sla->state($this->hoursSince($oldestAt), $targetHours) : null,
                'paidToday'  => BillPayment::query()
                    ->where('status', BillPaymentStatus::Completed->value)
                    ->where('processed_at', '>=', now()->startOfDay())
                    ->count(),
            ],
        ] + $this->filterOptions());
    }

    /**
     * Every bill payment, newest first.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request, ['search', 'status', 'stage', 'provider', 'biller', 'category', 'country', 'from', 'to']);

        $payments = $this->filtered(BillPayment::query(), $filters)
            ->with(['user', 'biller.category'])
            ->when(BillPaymentStatus::tryFrom($filters['status']) !== null, fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when($filters['stage'] === self::STAGE_WITH_PROVIDER, fn (Builder $query): Builder => $query->where('dispatch_state', BillPaymentDispatchState::AwaitingProvider->value))
            ->when($filters['stage'] === self::STAGE_CONTRADICTION, fn (Builder $query): Builder => $query->whereNotNull('metadata->provider_contradiction'))
            ->when(ctype_digit($filters['provider']), fn (Builder $query): Builder => $query->where('bill_payment_provider_id', (int) $filters['provider']))
            ->when($this->isDate($filters['from']), fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($this->isDate($filters['to']), fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('backend.bill_payment.payments.index', [
            'payments' => $payments,
            'filters'  => $filters,
            'statuses' => BillPaymentStatus::cases(),
            'stages'   => [
                self::STAGE_WITH_PROVIDER => __('With provider'),
                self::STAGE_CONTRADICTION => __('Provider contradiction'),
            ],
            'providers' => BillPaymentProvider::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'stats'     => [
                'total'    => BillPayment::query()->count(),
                'paid'     => BillPayment::query()->where('status', BillPaymentStatus::Completed->value)->count(),
                'failed'   => BillPayment::query()->where('status', BillPaymentStatus::Failed->value)->count(),
                'awaiting' => BillPayment::query()->whereIn('status', [BillPaymentStatus::Pending->value, BillPaymentStatus::Processing->value])->count(),
            ],
        ] + $this->filterOptions());
    }

    public function show(Request $request, BillPayment $billPayment, DashboardSla $sla): View
    {
        $billPayment->load([
            'user',
            'wallet.currency',
            'biller.category',
            'item',
            'provider',
            'transaction',
            'refundTransaction',
            'events',
            'claimedBy',
            'resolvedBy',
        ]);

        $admin  = $this->admin($request);
        $canAct = $admin->can('bill-payment-action');

        $metadata = is_array($billPayment->metadata) ? $billPayment->metadata : [];
        $reason   = is_string($metadata['review_reason'] ?? null) ? $metadata['review_reason'] : null;

        /*
         * The anomaly settle() or refund() recorded, or, on an open payment
         * whose debit was changed elsewhere and not yet acted on, the one they
         * would record: the page explains why neither action is offered.
         */
        $ledgerAnomaly = is_scalar($metadata['ledger_anomaly'] ?? null)
            ? (string) $metadata['ledger_anomaly']
            : (($billPayment->status?->isTerminal() ?? false) ? null : $this->payments->debitAnomaly($billPayment));

        return view('backend.bill_payment.payments.show', [
            'payment'           => $billPayment,
            'canAct'            => $canAct,
            'row'               => $this->rowStates(collect([$billPayment]), $admin, $sla, $sla->hoursFor('bill_payment'))[$billPayment->id],
            'token'             => $canAct ? $this->decryptedToken($billPayment) : null,
            'reviewNote'        => $reason !== null ? __(self::REVIEW_REASONS[$reason] ?? 'The payment needs a person to check it before it is resolved.') : null,
            'ledgerAnomaly'     => $ledgerAnomaly,
            'timeline'          => $this->timeline($billPayment),
            'canCheck'          => $canAct && $this->payments->canCheckWithProvider($billPayment),
            'canConfirmNotPaid' => $canAct && $this->payments->canConfirmNotPaid($billPayment),
            'providerChecks'    => $this->providerChecks($billPayment, $metadata),
            'contradiction'     => $this->contradiction($metadata),
        ]);
    }

    /**
     * Ask the payment's provider about it now, and apply the answer the way
     * the reconcile run would. The checker decides whether the payment can
     * be asked about at all; a refusal is flashed in its own words and the
     * provider is not called.
     */
    public function checkWithProvider(Request $request, BillPayment $billPayment, BillPaymentStatusChecker $checker): RedirectResponse
    {
        $check = $checker->check((int) $billPayment->id, BillPaymentStatusChecker::TRIGGER_ADMIN, $this->admin($request));

        if (! $check->checked) {
            notifyEvs('error', $check->message);

            return redirect()->back();
        }

        [$type, $consequence] = $this->checkConsequence($check, BillPayment::query()->findOrFail($billPayment->id));

        notifyEvs($type, trim($check->message.' '.$consequence));

        return redirect()->back();
    }

    /**
     * Refund a provider payment in review that the admin confirmed, after a
     * recent check, was not charged at the provider.
     */
    public function confirmNotPaid(ConfirmBillPaymentNotPaidRequest $request, BillPayment $billPayment): RedirectResponse
    {
        $data = $request->validated();

        return $this->respond(
            fn (): BillPaymentActionResult => $this->payments->confirmNotPaidAndRefund(
                $billPayment,
                $this->admin($request),
                (string) $data['reason'],
                (string) $data['note'],
            ),
            __('Payment :reference confirmed not charged. :amount was returned to the customer\'s wallet.', [
                'reference' => $billPayment->reference,
                'amount'    => $this->amount((string) $billPayment->total_amount, (string) $billPayment->currency),
            ]),
        );
    }

    public function claim(ClaimBillPaymentRequest $request, BillPayment $billPayment): RedirectResponse
    {
        try {
            $this->payments->claim($billPayment, $this->admin($request), $request->takesOver());
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->back();
        }

        notifyEvs('success', __('Payment :reference is yours. Other operators can see you are on it.', [
            'reference' => $billPayment->reference,
        ]));

        return redirect()->back();
    }

    public function markPaid(MarkBillPaymentPaidRequest $request, BillPayment $billPayment): RedirectResponse
    {
        $data = $request->validated();

        return $this->respond(
            fn (): BillPaymentActionResult => $this->payments->markPaid(
                $billPayment,
                $this->admin($request),
                (string) $data['receipt_reference'],
                isset($data['token']) ? (string) $data['token'] : null,
                isset($data['admin_note']) ? (string) $data['admin_note'] : null,
            ),
            __('Payment :reference marked paid. The customer has been told.', ['reference' => $billPayment->reference]),
        );
    }

    public function markFailed(MarkBillPaymentFailedRequest $request, BillPayment $billPayment): RedirectResponse
    {
        $data = $request->validated();

        return $this->respond(
            fn (): BillPaymentActionResult => $this->payments->markFailed(
                $billPayment,
                $this->admin($request),
                (string) $data['reason'],
                isset($data['admin_note']) ? (string) $data['admin_note'] : null,
            ),
            __('Payment :reference marked failed. :amount was returned to the customer\'s wallet.', [
                'reference' => $billPayment->reference,
                'amount'    => $this->amount((string) $billPayment->total_amount, (string) $billPayment->currency),
            ]),
        );
    }

    /**
     * One flash per outcome. A refusal is an error with the service's own
     * words; a repeat of something already done changes nothing and says so.
     *
     * @param Closure(): BillPaymentActionResult $action
     */
    private function respond(Closure $action, string $doneMessage): RedirectResponse
    {
        try {
            $result = $action();
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->back();
        }

        match ($result->outcome) {
            BillPaymentActionResult::DONE             => notifyEvs('success', $doneMessage),
            BillPaymentActionResult::ALREADY_RESOLVED => notifyEvs('info', __('This payment was already resolved.')),
            default                                   => notifyEvs('error', __('This payment\'s ledger entry was changed outside Bill payments. It needs a developer to reconcile it.')),
        };

        return redirect()->back();
    }

    /**
     * What a check with the provider did to the payment, as a flash type and
     * one sentence after the provider's answer.
     *
     * @return array{0: string, 1: string}
     */
    private function checkConsequence(BillPaymentCheckOutcome $check, BillPayment $payment): array
    {
        if ($check->action?->outcome === BillPaymentActionResult::ANOMALY) {
            return ['error', __('Its answer could not be applied to this payment. No money was moved; see the notice on this page.')];
        }

        if ($check->action?->outcome === BillPaymentActionResult::DONE) {
            if ($payment->status === BillPaymentStatus::Completed) {
                return ['success', __('The payment is now settled.')];
            }

            if ($payment->status === BillPaymentStatus::Failed) {
                return ['success', __(':amount was returned to the customer\'s wallet.', [
                    'amount' => $this->amount((string) $payment->total_amount, (string) $payment->currency),
                ])];
            }
        }

        return ['info', match (true) {
            $payment->status?->isTerminal() ?? false                            => __('Nothing was changed.'),
            $payment->dispatchState() === BillPaymentDispatchState::NeedsReview => __('The payment is in review.'),
            default                                                             => __('The payment is still waiting on the provider.'),
        }];
    }

    /**
     * The provider-polling facts the detail page shows for a payment sent to
     * a provider: how often it was checked, when it is checked next, the
     * final-status time the provider gave, why it is in review, and the last
     * check an admin ran. Null for an operator payment.
     *
     * @param  array<string, mixed>                                                                                                                                                                                                             $metadata
     * @return array{attempts: int, checkedAt: CarbonInterface|null, nextAt: CarbonInterface|null, finalAt: CarbonInterface|null, reviewReason: string|null, last: array{at: CarbonInterface|null, outcome: string, by: string|null}|null}|null
     */
    private function providerChecks(BillPayment $payment, array $metadata): ?array
    {
        if ($payment->fulfilment !== BillPaymentFulfilment::Provider) {
            return null;
        }

        $isOpen = ! ($payment->status?->isTerminal() ?? false);
        $last   = is_array($metadata['last_provider_check'] ?? null) ? $metadata['last_provider_check'] : null;
        $reason = is_string($metadata['review_reason'] ?? null) ? $metadata['review_reason'] : null;

        return [
            'attempts'     => (int) $payment->provider_check_attempts,
            'checkedAt'    => $payment->provider_checked_at,
            'nextAt'       => $isOpen && $payment->dispatchState() === BillPaymentDispatchState::AwaitingProvider ? $payment->provider_next_check_at : null,
            'finalAt'      => $payment->provider_final_status_at,
            'reviewReason' => $isOpen && $reason !== null ? __(self::REVIEW_REASONS[$reason] ?? 'The payment needs a person to check it before it is resolved.') : null,
            'last'         => $last === null ? null : [
                'at'      => $this->moment($last['at'] ?? null),
                'outcome' => $this->outcomeLabel($last['outcome'] ?? null),
                'by'      => $this->adminName($last['admin_id'] ?? null),
            ],
        ];
    }

    /**
     * The provider answer that contradicts this resolved payment, in the
     * operator's words, with when it was flagged. Null when there is none.
     *
     * @param  array<string, mixed>                               $metadata
     * @return array{note: string, at: CarbonInterface|null}|null
     */
    private function contradiction(array $metadata): ?array
    {
        $flag = $metadata['provider_contradiction'] ?? null;

        if (! is_array($flag)) {
            return null;
        }

        $kind = is_string($flag['kind'] ?? null) ? $flag['kind'] : '';

        return [
            'note' => __(self::CONTRADICTIONS[$kind] ?? 'The provider reported something about this payment that contradicts how it was resolved. No money was moved. Check with the provider and resolve it manually.'),
            'at'   => $this->moment($flag['at'] ?? null),
        ];
    }

    private function outcomeLabel(mixed $outcome): string
    {
        return match (is_string($outcome) ? BillPaymentOutcome::tryFrom($outcome) : null) {
            BillPaymentOutcome::Completed => __('Paid'),
            BillPaymentOutcome::Failed    => __('Not paid'),
            BillPaymentOutcome::Pending   => __('Still processing'),
            BillPaymentOutcome::Unknown   => __('No clear answer'),
            default                       => __('Unclear'),
        };
    }

    private function adminName(mixed $adminId): ?string
    {
        if (! is_int($adminId) && ! (is_string($adminId) && ctype_digit($adminId))) {
            return null;
        }

        return (string) (Admin::query()->whereKey((int) $adminId)->value('name') ?? __('Admin #:id', ['id' => (int) $adminId]));
    }

    /** A stored ISO 8601 time, or null when it is missing or unreadable. */
    private function moment(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What each listed payment needs on screen: how late it is against the
     * SLA, which actions apply to it now, and who holds it.
     *
     * `claim` is null (nobody), mine, other (another admin, still inside
     * claim_ttl_minutes) or expired. It is kept on a resolved payment too, so
     * the detail page still says who was on it; the claim form itself is only
     * offered while the payment is open.
     *
     * @param  Collection<int, BillPayment>                                                                                                                                                              $payments
     * @return array<int, array{age: string, ageLabel: string, canMarkPaid: bool, canMarkFailed: bool, claim: string|null, claimHolder: string|null, claimAgo: string|null, claimAgoShort: string|null}>
     */
    private function rowStates(Collection $payments, Admin $admin, DashboardSla $sla, int $targetHours): array
    {
        $ttl  = max(0, (int) config('bill_payments.claim_ttl_minutes', 30));
        $rows = [];

        foreach ($payments as $payment) {
            $claimedBy = $payment->claimed_by_admin_id === null ? null : (int) $payment->claimed_by_admin_id;

            $claim = match (true) {
                $claimedBy                                                  === null                   => null,
                $claimedBy                                                  === (int) $admin->getKey() => 'mine',
                $payment->claimed_at?->greaterThan(now()->subMinutes($ttl)) === true                   => 'other',
                default                                                                                => 'expired',
            };

            $rows[$payment->id] = [
                'age'           => $payment->created_at !== null ? $sla->state($this->hoursSince($payment->created_at), $targetHours) : DashboardSla::STATE_IN,
                'ageLabel'      => $payment->created_at !== null ? $this->ageLabel($payment->created_at) : '—',
                'canMarkPaid'   => $this->payments->canMarkPaid($payment),
                'canMarkFailed' => $this->payments->canMarkFailed($payment),
                'claim'         => $claim,
                'claimHolder'   => $claimedBy !== null ? ($payment->claimedBy?->name ?? __('Admin #:id', ['id' => $claimedBy])) : null,
                'claimAgo'      => $payment->claimed_at?->diffForHumans(),
                'claimAgoShort' => $payment->claimed_at?->diffForHumans(['short' => true]),
            ];
        }

        return $rows;
    }

    /**
     * The payment's audit trail, oldest first, with each actor named.
     *
     * @return list<array{at: CarbonInterface|null, actor: string, actorType: string, from: string|null, to: string, note: string|null}>
     */
    private function timeline(BillPayment $payment): array
    {
        $adminIds = $payment->events
            ->filter(fn (BillPaymentEvent $event): bool => $event->actor_type === BillPaymentActorType::Admin && $event->actor_id !== null)
            ->pluck('actor_id')
            ->unique()
            ->values();

        $adminNames = $adminIds->isEmpty()
            ? collect()
            : Admin::query()->whereIn('id', $adminIds)->pluck('name', 'id');

        return $payment->events->map(function (BillPaymentEvent $event) use ($payment, $adminNames): array {
            $actor = match ($event->actor_type) {
                BillPaymentActorType::Admin    => (string) ($adminNames[$event->actor_id] ?? __('Admin #:id', ['id' => (int) $event->actor_id])),
                BillPaymentActorType::User     => (string) ($payment->user?->name ?? __('Customer')),
                BillPaymentActorType::Provider => (string) ($payment->provider?->name ?? __('Provider')),
                default                        => __('System'),
            };

            return [
                'at'        => $event->created_at,
                'actor'     => $actor,
                'actorType' => $event->actor_type?->value ?? BillPaymentActorType::System->value,
                'from'      => $this->stageLabel($event->from_status, $event->from_dispatch_state),
                'to'        => (string) $this->stageLabel($event->to_status, $event->to_dispatch_state),
                'note'      => $event->note,
            ];
        })->values()->all();
    }

    private function stageLabel(?string $status, ?string $dispatchState): ?string
    {
        if ($status === null && $dispatchState === null) {
            return null;
        }

        $statusLabel = BillPaymentStatus::tryFrom((string) $status)?->label()               ?? (string) $status;
        $stageLabel  = BillPaymentDispatchState::tryFrom((string) $dispatchState)?->label() ?? (string) $dispatchState;

        return trim($statusLabel.' · '.$stageLabel, ' ·');
    }

    /**
     * The stored token, for an admin allowed to see it. A value the current
     * key can no longer decrypt is reported and shown as missing rather than
     * failing the whole page.
     */
    private function decryptedToken(BillPayment $payment): ?string
    {
        if ($payment->getRawOriginal('token') === null) {
            return null;
        }

        $token = rescue(fn (): mixed => $payment->token, null, report: true);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The filters a list understands, trimmed, with anything unknown read as
     * "no filter".
     *
     * @param  list<string>          $keys
     * @return array<string, string>
     */
    private function filters(Request $request, array $keys): array
    {
        $filters = [];

        foreach ($keys as $key) {
            $value = $request->query($key, '');

            $filters[$key] = is_string($value) ? trim($value) : '';
        }

        if (isset($filters['country'])) {
            $filters['country'] = strtoupper($filters['country']);
        }

        if (isset($filters['stage']) && ! in_array($filters['stage'], [self::STAGE_WITH_PROVIDER, self::STAGE_CONTRADICTION], true)) {
            $filters['stage'] = '';
        }

        if (isset($filters['mine'])) {
            $filters['mine'] = $filters['mine'] === '1' ? '1' : '';
        }

        return $filters;
    }

    /**
     * The filters both lists share: search, biller, category and country.
     *
     * Search matches the BP reference, the debit's transaction id, the
     * customer number (with or without its spaces) and the customer's email.
     *
     * @param  Builder<BillPayment>  $query
     * @param  array<string, string> $filters
     * @return Builder<BillPayment>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? '';

        return $query
            ->when($search !== '', function (Builder $query) use ($search): void {
                $compact = preg_replace('/\s+/u', '', $search) ?? $search;

                $query->where(fn (Builder $match): Builder => $match
                    ->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('customer_number', 'like', '%'.$compact.'%')
                    ->orWhereHas('transaction', fn (Builder $transaction): Builder => $transaction->where('trx_id', 'like', '%'.$search.'%'))
                    ->orWhereHas('user', fn (Builder $user): Builder => $user->where('email', 'like', '%'.$search.'%')));
            })
            ->when(ctype_digit($filters['biller'] ?? ''), fn (Builder $query): Builder => $query->where('biller_id', (int) $filters['biller']))
            ->when(preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $filters['category'] ?? '') === 1, fn (Builder $query): Builder => $query->where('category_code', $filters['category']))
            ->when(preg_match('/^[A-Z]{2}$/', $filters['country'] ?? '') === 1, fn (Builder $query): Builder => $query->where('country', $filters['country']));
    }

    /**
     * @return array{billers: Collection<int, Biller>, categories: Collection<int, BillCategory>, countries: Collection<int, string>}
     */
    private function filterOptions(): array
    {
        return [
            'billers'    => Biller::query()->orderBy('name')->get(['id', 'name', 'country']),
            'categories' => BillCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name']),
            'countries'  => Biller::query()->distinct()->orderBy('country')->pluck('country'),
        ];
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private function hoursSince(CarbonInterface $moment): float
    {
        return max(0.0, (float) $moment->diffInSeconds(now())) / 3600;
    }

    private function ageLabel(CarbonInterface $moment): string
    {
        return $moment->diffForHumans(now(), [
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'short'  => true,
            'parts'  => 2,
        ]);
    }

    /**
     * An amount at its currency's own precision, cut rather than rounded,
     * with the code after it: "101.50 USD".
     */
    private function amount(string $value, string $currency): string
    {
        $decimals = Money::decimalsIfKnown($currency) ?? 8;

        return Money::format(DecimalMoney::normalize($value, $decimals), $currency, false).' '.strtoupper($currency);
    }

    /**
     * The signed-in admin. The admin middleware guarantees one; a request
     * without one is refused rather than acted on anonymously.
     */
    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');

        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
