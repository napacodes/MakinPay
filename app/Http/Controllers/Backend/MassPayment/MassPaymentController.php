<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\MassPayment;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\MassPayment\CancelRemainingMassPaymentRequest;
use App\Http\Requests\Backend\MassPayment\CloseMassPaymentAfterReviewRequest;
use App\Http\Resources\Api\V1\MassPaymentBatchResource;
use App\Models\Admin;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentEvent;
use App\Models\MassPaymentRow;
use App\Services\MassPayment\MassPaymentActionResult;
use App\Services\MassPayment\MassPaymentActor;
use App\Services\MassPayment\MassPaymentProcessor;
use App\Services\MassPayment\MassPaymentReport;
use App\Services\MassPayment\MassPaymentService;
use App\Services\MassPayment\MassPayoutProcessor;
use App\Services\MassPayment\MassPayoutReconciler;
use App\Support\DecimalMoney;
use App\Support\Money;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The operator's side of mass payments: every batch, one batch's detail with
 * its lines and audit trail, the batch report, and the three things an admin
 * does to a batch that money is still reserved for (resume, cancel the
 * remaining lines, close after review).
 *
 * Reading needs mass-payment-list; resuming and cancelling need
 * mass-payment-action; closing after review, which settles a batch without
 * moving money, needs mass-payment-manage. Every action goes through
 * MassPaymentService, which re-reads the batch under its lock and decides
 * there; what this controller shows about which actions apply is a hint,
 * never the check.
 *
 * Deliberately not behind admin.feature:mass_payment (see routes/admin.php):
 * switching the module off must never hide reserved customer money from the
 * only screens that can release it.
 */
class MassPaymentController extends BaseController
{
    public const PER_PAGE = 20;

    public const ROWS_PER_PAGE = 50;

    /** The most audit-trail entries the detail page lists. */
    public const TIMELINE_LIMIT = 200;

    /** Line events the timeline keeps; every other line event is summarised by the lines table. */
    private const TIMELINE_ROW_EVENTS = ['row_flagged', 'row_reviewed', 'row_needs_review', 'account_revealed'];

    /**
     * What a ledger anomaly code means, in the operator's words.
     *
     * @var array<string, string>
     */
    private const ANOMALIES = [
        MassPaymentProcessor::ANOMALY_DEBIT_MISSING     => 'A reserved debit for this line is missing from the ledger.',
        MassPaymentProcessor::ANOMALY_DEBIT_NOT_PENDING => 'A reserved debit was changed outside Mass payments, so the line was not paid or refunded.',
        MassPaymentProcessor::ANOMALY_AMOUNT_MISMATCH   => 'A reserved debit no longer matches the amount frozen on its line.',
        MassPaymentProcessor::ANOMALY_CREDIT_EXISTS     => 'The recipient already has a credit for this line.',
        MassPaymentProcessor::ANOMALY_REFUND_EXISTS     => 'A refund already exists for this line\'s debit.',
        MassPaymentProcessor::ANOMALY_CONSERVATION      => 'The batch\'s reserved total does not add up to its lines.',
    ];

    /**
     * Each audit-trail note, in the operator's words.
     *
     * @var array<string, string>
     */
    private const EVENT_LABELS = [
        'created'               => 'Draft created',
        'refreshed'             => 'Draft refreshed',
        'discarded'             => 'Draft discarded',
        'expired'               => 'Draft expired',
        'confirmed'             => 'Confirmed and total reserved',
        'processing'            => 'Payout started',
        'held'                  => 'Held for review',
        'resumed'               => 'Resumed',
        'stop_requested'        => 'Stop requested',
        'row_flagged'           => 'Line flagged for review',
        'row_reviewed'          => 'Line closed after review',
        'row_failed'            => 'Line refunded',
        'closed_after_review'   => 'Closed after review',
        'finalized'             => 'Finalized',
        'processing_job_failed' => 'Payout job failed',
        'approved'              => 'Approved',
        'rejected'              => 'Rejected and refunded',
        'row_needs_review'      => 'Payout line sent to review',
        'account_revealed'      => 'Payout account revealed',
    ];

    public function __construct(private readonly MassPaymentService $service) {}

    public static function permissions(): array
    {
        return [
            'index|show|downloadReport' => 'mass-payment-list',
            'resume|cancelRemaining'    => 'mass-payment-action',
            'closeAfterReview'          => 'mass-payment-manage',
        ];
    }

    /**
     * Every batch, newest first. The header sums only base-currency figures,
     * never amounts in different currencies.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $batches = MassPaymentBatchResource::withRowCounts($this->filtered(MassPaymentBatch::query(), $filters))
            ->with(['user:id,first_name,last_name,username,email'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('backend.mass_payment.index', [
            'batches'  => $batches,
            'filters'  => $filters,
            'statuses' => MassPaymentBatchStatus::cases(),
            'stats'    => $this->stats(),
        ]);
    }

    public function show(Request $request, MassPaymentBatch $batch): View
    {
        $batch->load(['user', 'wallet', 'currency', 'reviewedBy', 'approvedBy']);

        $admin     = $this->admin($request);
        $status    = $batch->status();
        $rowFilter = MassPaymentRowStatus::tryFrom((string) $request->query('row_status', ''));

        $rows = MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->with([
                'debitTransaction:id,trx_id,status',
                'creditTransaction:id,trx_id,status',
                'refundTransaction:id,trx_id,status',
            ])
            ->when($rowFilter !== null, fn (Builder $query): Builder => $query->where('status', $rowFilter->value))
            ->orderBy('line_no')
            ->paginate(self::ROWS_PER_PAGE, ['*'], 'rows')
            ->withQueryString();

        $flagged  = MassPaymentRow::query()->where('mass_payment_batch_id', $batch->id)->where('status', MassPaymentRowStatus::Pending->value)->where('needs_review', true)->count();
        $anomaly  = $this->batchAnomaly($batch);
        $isHeld   = $status === MassPaymentBatchStatus::NeedsReview;
        $stopping = $batch->stop_requested_at !== null;
        $isPayout = $batch->isPayout();

        return view('backend.mass_payment.show', [
            'batch'            => $batch,
            'status'           => $status,
            'rows'             => $rows,
            'rowFilter'        => $rowFilter,
            'rowStatuses'      => MassPaymentRowStatus::cases(),
            'counts'           => $this->rowCounts($batch),
            'flagged'          => $flagged,
            'anomaly'          => $anomaly,
            'holdNote'         => $isHeld ? $this->holdNote((string) $batch->hold_reason) : null,
            'timeline'         => $this->timeline($batch),
            'figures'          => $this->figures($batch),
            'refundable'       => $this->refundable($batch),
            'canResume'        => $admin->can('mass-payment-action') && $isHeld && ! $stopping && $anomaly === null && $flagged === 0,
            'canCancel'        => $admin->can('mass-payment-action') && $status->holdsMoney() && $status !== MassPaymentBatchStatus::AwaitingApproval && ! $stopping,
            'canClose'         => $admin->can('mass-payment-manage') && $isHeld && ($anomaly !== null || $flagged > 0),
            'canOpenLedger'    => $admin->can('transaction-list'),
            'isPayout'         => $isPayout,
            'payout'           => $isPayout ? $this->payoutTerms($batch) : null,
            'canApprove'       => $isPayout && $admin->can('mass-payment-approve') && $status === MassPaymentBatchStatus::AwaitingApproval && ! $stopping,
            'canResolve'       => $isPayout && $admin->can('mass-payment-action'),
            'canResolveReview' => $isPayout && $admin->can('mass-payment-action') && $admin->can('mass-payment-manage'),
        ]);
    }

    /**
     * The terms a payout batch was built on, as frozen into its metadata, in
     * the operator's words.
     *
     * @return array{method: string, driver: string, feeMode: string, approval: string}
     */
    private function payoutTerms(MassPaymentBatch $batch): array
    {
        $terms   = is_array($batch->metadata['payout'] ?? null) ? $batch->metadata['payout'] : [];
        $drivers = (array) config('remittance.gateways', []);
        $driver  = (string) ($terms['driver'] ?? $batch->payout_driver);

        return [
            'method'   => (string) ($terms['method_name'] ?? __('Removed method')),
            'driver'   => (string) ($drivers[$driver] ?? Str::headline($driver)),
            'feeMode'  => ($terms['fee_mode'] ?? null) === 'deducted' ? __('Deducted from each amount') : __('Added on top of each amount'),
            'approval' => match (true) {
                $batch->approved_at !== null     => __('Approved by :admin', ['admin' => $batch->approvedBy?->name ?? __('an admin')]),
                (bool) $batch->approval_required => __('Required'),
                default                          => __('Not required'),
            },
        ];
    }

    /** Why a held batch is held, and what the operator can do about it. */
    private function holdNote(string $reason): ?string
    {
        return match ($reason) {
            MassPaymentProcessor::HOLD_SENDER_UNAVAILABLE  => __('The sender account cannot make payments right now, so nothing is being paid. Resume once the account is active again, or cancel the remaining rows to refund them.'),
            MassPayoutProcessor::HOLD_PROVIDER_UNAVAILABLE => __('The payout provider refused the platform\'s credentials, so nothing more is being sent. Fix the provider settings, then resume. Nothing was sent for the rows still reserved.'),
            default                                        => null,
        };
    }

    /** The batch report, the same CSV the owner downloads. */
    public function downloadReport(MassPaymentBatch $batch, MassPaymentReport $report): StreamedResponse
    {
        return $report->download($batch);
    }

    public function resume(Request $request, MassPaymentBatch $batch): RedirectResponse
    {
        return $this->respond(
            fn (): MassPaymentActionResult => $this->service->resume($batch, $this->admin($request)),
            fn (MassPaymentActionResult $result): string => __('Mass payment :reference resumed. The remaining rows are being paid.', [
                'reference' => $batch->reference,
            ]),
        );
    }

    public function cancelRemaining(CancelRemainingMassPaymentRequest $request, MassPaymentBatch $batch): RedirectResponse
    {
        return $this->respond(
            fn (): MassPaymentActionResult => $this->service->requestStop(
                $batch,
                MassPaymentActor::admin($this->admin($request)),
                (string) $request->validated('reason'),
            ),
            fn (MassPaymentActionResult $result): string => $result->batch?->status()->isTerminal() === true
                ? __('The remaining rows of :reference were cancelled. Each one was refunded to the sender\'s wallet.', ['reference' => $batch->reference])
                : __('The remaining rows of :reference are being cancelled. Each one is refunded to the sender\'s wallet.', ['reference' => $batch->reference]),
        );
    }

    public function closeAfterReview(CloseMassPaymentAfterReviewRequest $request, MassPaymentBatch $batch): RedirectResponse
    {
        return $this->respond(
            fn (): MassPaymentActionResult => $this->service->closeAfterReview(
                $batch,
                $this->admin($request),
                (string) $request->validated('note'),
            ),
            fn (MassPaymentActionResult $result): string => __('Mass payment :reference closed after review. No money was moved.', [
                'reference' => $batch->reference,
            ]),
        );
    }

    /**
     * One flash per outcome. A refusal is an error with the service's own
     * words; a repeat of something already done changes nothing and says so;
     * a close that left unflagged rows pending says what to do next.
     *
     * @param Closure(): MassPaymentActionResult       $action
     * @param Closure(MassPaymentActionResult): string $doneMessage
     */
    private function respond(Closure $action, Closure $doneMessage): RedirectResponse
    {
        try {
            $result = $action();
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->back();
        }

        match ($result->outcome) {
            MassPaymentActionResult::DONE             => notifyEvs('success', $doneMessage($result)),
            MassPaymentActionResult::ALREADY_RESOLVED => notifyEvs('info', __('This mass payment was already resolved. Nothing was changed.')),
            MassPaymentActionResult::NOT_READY        => notifyEvs('info', __('The flagged rows were closed, but rows are still pending. Resume the batch or cancel the remaining rows.')),
            default                                   => notifyEvs('error', __('This mass payment\'s ledger does not add up. No money was moved; see the notice on this page.')),
        };

        return redirect()->back();
    }

    /**
     * The header strip: batches someone has to act on, batches paying out,
     * and what was paid and released today in the base currency.
     *
     * @return array{attention: int, processing: int, paidToday: string, releasedToday: string, baseCurrency: string}
     */
    private function stats(): array
    {
        $base     = (string) siteCurrency();
        $decimals = Money::decimalsIfKnown($base) ?? 8;
        $today    = now()->startOfDay();

        $paid     = MassPaymentRow::query()->where('status', MassPaymentRowStatus::Paid->value)->where('paid_at', '>=', $today);
        $released = MassPaymentRow::query()->where('status', MassPaymentRowStatus::Failed->value)->where('failed_at', '>=', $today);

        return [
            'attention'     => MassPaymentBatch::query()->awaitingAction()->count(),
            'processing'    => MassPaymentBatch::query()->whereIn('status', [MassPaymentBatchStatus::Queued->value, MassPaymentBatchStatus::Processing->value])->count(),
            'paidToday'     => DecimalMoney::normalize((string) ((clone $paid)->sum('base_amount') ?: '0'), $decimals),
            'releasedToday' => DecimalMoney::normalize(DecimalMoney::add(
                (string) ((clone $released)->sum('base_amount') ?: '0'),
                (string) ((clone $released)->sum('base_fee') ?: '0'),
                8,
            ), $decimals),
            'baseCurrency' => $base,
        ];
    }

    /**
     * The batch's money at its currency's own precision: the total reserved
     * at confirm, then what its lines have paid, released and still hold,
     * read from the lines themselves so a batch still paying out is current.
     *
     * @return array{currency: string, total: string, fee: string, paid: string, released: string, held: string}
     */
    private function figures(MassPaymentBatch $batch): array
    {
        $currency = (string) $batch->currency_code;
        $decimals = Money::decimalsIfKnown($currency) ?? 8;

        /*
         * Added up here with DecimalMoney rather than SUM() in the database:
         * a batch has at most HARD_MAX_ROWS lines, and the figures stay exact
         * on every driver.
         */
        $sums = ['paid' => '0', 'failed' => '0', 'pending' => '0'];

        MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->whereIn('status', array_keys($sums))
            ->whereNotNull('payable_amount')
            ->toBase()
            ->select(['status', 'payable_amount'])
            ->orderBy('id')
            ->each(function (object $row) use (&$sums): void {
                $sums[$row->status] = DecimalMoney::add($sums[$row->status], (string) $row->payable_amount, 8);
            });

        return [
            'currency' => $currency,
            'total'    => DecimalMoney::normalize((string) ($batch->total_payable ?? '0'), $decimals),
            'fee'      => DecimalMoney::normalize((string) ($batch->total_fee ?? '0'), $decimals),
            'paid'     => DecimalMoney::normalize($sums[MassPaymentRowStatus::Paid->value], $decimals),
            'released' => DecimalMoney::normalize($sums[MassPaymentRowStatus::Failed->value], $decimals),
            'held'     => DecimalMoney::normalize($sums[MassPaymentRowStatus::Pending->value], $decimals),
        ];
    }

    /**
     * What cancel remaining would refund: the pending lines not flagged for
     * review, and their payable at the batch currency's precision. A flagged
     * line is skipped by the stop walk and stays reserved until it is closed
     * after review, so the modal must not promise it.
     *
     * @return array{count: int, amount: string}
     */
    private function refundable(MassPaymentBatch $batch): array
    {
        $decimals = Money::decimalsIfKnown((string) $batch->currency_code) ?? 8;
        $count    = 0;
        $amount   = '0';

        MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->where('needs_review', false)
            ->whereNotNull('payable_amount')
            ->toBase()
            ->select(['id', 'payable_amount'])
            ->orderBy('id')
            ->each(function (object $row) use (&$count, &$amount): void {
                $count++;
                $amount = DecimalMoney::add($amount, (string) $row->payable_amount, 8);
            });

        return ['count' => $count, 'amount' => DecimalMoney::normalize($amount, $decimals)];
    }

    /**
     * How many lines are in each status, every status present.
     *
     * @return array<string, int>
     */
    private function rowCounts(MassPaymentBatch $batch): array
    {
        $byStatus = MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = ['rows' => (int) $byStatus->sum()];

        foreach (MassPaymentRowStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * The anomaly recorded on the batch itself, in the operator's words, or
     * null when there is none.
     */
    private function batchAnomaly(MassPaymentBatch $batch): ?string
    {
        $metadata = is_array($batch->metadata) ? $batch->metadata : [];
        $code     = $metadata['ledger_anomaly'] ?? null;

        if (! is_scalar($code) || (string) $code === '') {
            return null;
        }

        return self::anomalyLabel((string) $code);
    }

    /** Why a payout line went to review, in the operator's words. */
    public static function payoutReviewLabel(mixed $code): string
    {
        return match (is_scalar($code) ? (string) $code : '') {
            MassPayoutProcessor::REVIEW_PROVIDER_MISMATCH => __('The provider reported a different amount or currency for this payout.'),
            MassPayoutProcessor::REVIEW_REFERENCE_TAKEN   => __('The provider\'s reference is already recorded on another payout.'),
            MassPayoutReconciler::REVIEW_NOT_FOUND        => __('The provider still holds no record of this payout.'),
            MassPayoutReconciler::REVIEW_UNCONFIRMED      => __('The provider has not confirmed this payout for a long time.'),
            MassPayoutReconciler::REVIEW_CONTRADICTION    => __('The provider gave contradictory answers about this payout.'),
            default                                       => __('This payout\'s outcome is unknown. Check with the provider before deciding.'),
        };
    }

    /** A ledger anomaly code in the operator's words. */
    public static function anomalyLabel(string $code): string
    {
        return __(self::ANOMALIES[$code] ?? 'The ledger disagrees with this mass payment.');
    }

    /**
     * The batch's audit trail, oldest first, with each actor named: every
     * batch-level event plus the line flags and closes. A line's refund is
     * shown on its row instead of here, so a large batch stays readable.
     *
     * @return list<array{at: CarbonInterface|null, actor: string, actorType: string, label: string, from: string|null, to: string|null, detail: string|null}>
     */
    private function timeline(MassPaymentBatch $batch): array
    {
        $events = MassPaymentEvent::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('row_id')
                ->orWhereIn('note', self::TIMELINE_ROW_EVENTS))
            ->orderBy('id')
            ->limit(self::TIMELINE_LIMIT)
            ->get();

        $adminNames = $this->adminNames($events);

        return $events->map(function (MassPaymentEvent $event) use ($batch, $adminNames): array {
            $actor = match ($event->actor_type) {
                MassPaymentActor::TYPE_ADMIN => (string) ($adminNames[$event->actor_id] ?? __('Admin #:id', ['id' => (int) $event->actor_id])),
                MassPaymentActor::TYPE_USER  => (string) ($batch->user?->name ?? __('Customer')),
                default                      => __('System'),
            };

            return [
                'at'        => $event->created_at,
                'actor'     => $actor,
                'actorType' => in_array($event->actor_type, [MassPaymentActor::TYPE_ADMIN, MassPaymentActor::TYPE_USER], true) ? $event->actor_type : MassPaymentActor::TYPE_SYSTEM,
                'label'     => __(self::EVENT_LABELS[(string) $event->note] ?? Str::headline((string) $event->note)),
                'from'      => $this->statusLabel($event->from_status, $event->row_id !== null),
                'to'        => $this->statusLabel($event->to_status, $event->row_id !== null),
                'detail'    => $this->eventDetail($event),
            ];
        })->values()->all();
    }

    /**
     * The one line of context an event carries: the admin's reason or note,
     * why the batch was held, or which line was flagged and why.
     */
    private function eventDetail(MassPaymentEvent $event): ?string
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $line    = isset($payload['line_no']) && is_numeric($payload['line_no']) ? (int) $payload['line_no'] : null;

        return match ((string) $event->note) {
            'stop_requested', 'rejected' => is_string($payload['reason'] ?? null) ? __('Reason: :reason', ['reason' => $payload['reason']]) : null,
            'held' => match ($payload['reason'] ?? null) {
                MassPaymentProcessor::HOLD_SENDER_UNAVAILABLE  => __('The sender account could not make payments.'),
                MassPaymentProcessor::HOLD_LEDGER_ANOMALY      => __('The ledger disagreed with the batch.'),
                MassPayoutProcessor::HOLD_PROVIDER_UNAVAILABLE => __('The payout provider refused the platform\'s credentials.'),
                default                                        => null,
            },
            'row_needs_review', 'account_revealed' => __('Line :line.', ['line' => (string) $line]),
            'row_flagged'         => trim(__('Line :line.', ['line' => (string) $line]).' '.(is_string($payload['ledger_anomaly'] ?? null) ? self::anomalyLabel($payload['ledger_anomaly']) : '')),
            'row_reviewed'        => __('Line :line.', ['line' => (string) $line]),
            'closed_after_review' => is_string($payload['note'] ?? null) ? __('Note: :note', ['note' => $payload['note']]) : null,
            default               => null,
        };
    }

    private function statusLabel(?string $value, bool $isRow): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $isRow
            ? (MassPaymentRowStatus::tryFrom($value)?->label() ?? $value)
            : (MassPaymentBatchStatus::tryFrom($value)?->label() ?? $value);
    }

    /**
     * @param  Collection<int, MassPaymentEvent> $events
     * @return Collection<int, string>
     */
    private function adminNames(Collection $events): Collection
    {
        $ids = $events
            ->filter(fn (MassPaymentEvent $event): bool => $event->actor_type === MassPaymentActor::TYPE_ADMIN && $event->actor_id !== null)
            ->pluck('actor_id')
            ->unique()
            ->values();

        return $ids->isEmpty() ? collect() : Admin::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * The filters the list understands, trimmed, with anything unknown read
     * as "no filter".
     *
     * @return array{search: string, user: string, status: string, kind: string, attention: string, from: string, to: string}
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['search', 'user', 'status', 'kind', 'attention', 'from', 'to'] as $key) {
            $value = $request->query($key, '');

            $filters[$key] = is_string($value) ? trim($value) : '';
        }

        if (MassPaymentBatchStatus::tryFrom($filters['status']) === null) {
            $filters['status'] = '';
        }

        if (MassPaymentKind::tryFrom($filters['kind']) === null) {
            $filters['kind'] = '';
        }

        $filters['attention'] = $filters['attention'] === '1' ? '1' : '';

        foreach (['from', 'to'] as $key) {
            if (! $this->isDate($filters[$key])) {
                $filters[$key] = '';
            }
        }

        return $filters;
    }

    /**
     * Search matches the MP reference or the batch id; user matches the
     * owner's email, username or name.
     *
     * @param  Builder<MassPaymentBatch> $query
     * @param  array<string, string>     $filters
     * @return Builder<MassPaymentBatch>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        $search = $filters['search'];
        $user   = $filters['user'];

        return $query
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('uuid', $search)))
            ->when($user !== '', fn (Builder $query): Builder => $query->whereHas('user', fn (Builder $owner): Builder => $owner
                ->where(fn (Builder $match): Builder => $match
                    ->where('email', 'like', '%'.$user.'%')
                    ->orWhere('username', 'like', '%'.$user.'%')
                    ->orWhere('first_name', 'like', '%'.$user.'%')
                    ->orWhere('last_name', 'like', '%'.$user.'%'))))
            ->when($filters['status'] !== '', fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when($filters['kind'] !== '', fn (Builder $query): Builder => $query->ofKind(MassPaymentKind::from($filters['kind'])))
            ->when($filters['attention'] === '1', fn (Builder $query): Builder => $query->awaitingAction())
            ->when($filters['from'] !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $filters['to']));
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
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
