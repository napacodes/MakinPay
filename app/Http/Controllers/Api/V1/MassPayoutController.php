<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Exceptions\MassPaymentChangedException;
use App\Exceptions\MassPaymentNotConfirmableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MassPayment\ConfirmMassPaymentRequest;
use App\Http\Requests\Api\V1\MassPayment\StoreMassPayoutRequest;
use App\Http\Resources\Api\V1\MassPaymentBatchResource;
use App\Http\Resources\Api\V1\MassPayoutBatchResource;
use App\Http\Resources\Api\V1\MassPayoutMethodResource;
use App\Http\Resources\Api\V1\MassPayoutRowResource;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Services\MassPayment\MassPaymentActionResult;
use App\Services\MassPayment\MassPaymentMonthSummary;
use App\Services\MassPayment\MassPaymentReport;
use App\Services\MassPayment\MassPaymentService;
use App\Services\MassPayment\MassPayoutAccount;
use App\Services\MassPayment\MassPayoutMethods;
use App\Services\MassPayment\MassPayoutService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mass payouts from the app: the methods a wallet may pay out through, the
 * history, the per-method template, building a draft from rows or a CSV, the
 * preview's rows, confirming, discarding, stopping, and the report.
 *
 * The same rules as the web pages, read from the same places. Every route
 * carries `feature.enabled:mass_payout` and `feature.enabled:withdraw_money`
 * (layer A owns the panel, merchants only by default) and the per-user
 * `feature:withdraw` and `feature:mass_payout`. Nothing here computes money;
 * MassPayoutService prices, reserves and sends.
 *
 * Every batch is loaded through the caller's own relation and only as a
 * payout batch, so another customer's batch, or a wallet batch, is
 * RESOURCE_NOT_FOUND exactly like one that does not exist. No answer ever
 * carries an account's details - only the masked label - because
 * api.idempotency stores answers in plain text.
 *
 * Confirming is safe to send twice, as for wallet batches: api.idempotency
 * replays a retry with the same key, and a retry it lets through reaches the
 * service, which answers it as a replay without a second debit. A confirmed
 * batch is 202 whether it was queued or is waiting for approval; `status`
 * says which. The refusals the app branches on are the wallet batches' plus
 * MASS_PAYOUT_METHOD_UNAVAILABLE (422) at draft or confirm.
 */
class MassPayoutController extends Controller
{
    private const HISTORY_PER_PAGE = 15;

    private const HISTORY_MAX_PER_PAGE = 50;

    private const ROWS_PER_PAGE = 50;

    private const ROWS_MAX_PER_PAGE = 100;

    /** How many lines a new draft's answer carries; the rest are paged from rows. */
    private const CREATED_ROWS_LIMIT = 100;

    public function __construct(
        private readonly MassPayoutService $service,
        private readonly MassPayoutMethods $methods,
        private readonly MassPayoutAccount $accounts,
    ) {}

    /**
     * The methods the caller may pay out through from one of their wallets,
     * with the account fields each line needs.
     */
    public function methods(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_id' => ['required', 'string', Rule::exists('wallets', 'uuid')->where(fn ($query) => $query->where('user_id', $request->user()->id))],
        ]);

        $wallet = $request->user()->wallets()->where('uuid', (string) $validated['wallet_id'])->with('currency')->firstOrFail();

        return ApiResponse::ok([
            'methods' => MassPayoutMethodResource::collection($this->methods->forWallet($request->user(), $wallet))->resolve($request),
        ]);
    }

    /**
     * What the caller's payout batches paid out this month, one entry per
     * currency.
     */
    public function summary(Request $request, MassPaymentMonthSummary $summary): JsonResponse
    {
        return ApiResponse::ok($summary->forUser($request->user(), MassPaymentKind::Payout));
    }

    /** The caller's payout batches, newest first, optionally of one status. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', Rule::enum(MassPaymentBatchStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::HISTORY_MAX_PER_PAGE],
        ]);

        $status = isset($validated['status']) ? (string) $validated['status'] : null;

        $batches = MassPaymentBatchResource::withRowCounts($request->user()->massPaymentBatches()->ofKind(MassPaymentKind::Payout))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? self::HISTORY_PER_PAGE));

        return ApiResponse::paginated(MassPayoutBatchResource::collection($batches));
    }

    /**
     * The blank file for one method the caller may use from one of their
     * wallets; any other method is RESOURCE_NOT_FOUND.
     */
    public function template(Request $request, MassPaymentReport $report): StreamedResponse
    {
        $validated = $request->validate([
            'wallet_id' => ['required', 'string'],
            'method_id' => ['required', 'integer', 'min:1'],
        ]);

        $wallet = $request->user()->wallets()->where('uuid', (string) $validated['wallet_id'])->with('currency')->firstOrFail();
        $method = $this->methods->findForWallet($request->user(), $wallet, (int) $validated['method_id']);

        abort_if($method === null, 404);

        return $report->payoutTemplate($this->accounts->fieldNames($method), (string) $method->name);
    }

    /**
     * Build a draft from a JSON `rows` list or a multipart `file`. No money
     * moves, no PIN is asked and no provider is called: the answer is the
     * preview, 201, with its first lines.
     */
    public function store(StoreMassPayoutRequest $request): JsonResponse
    {
        $batch = $this->service->createDraft(
            $request->user(),
            (string) $request->validated('wallet_id'),
            $request->methodId(),
            $request->draftInput(),
            $request->source(),
            MassPaymentService::CHANNEL_API,
            $request->batchName(),
        );

        $rows = $batch->rows()
            ->with(MassPayoutRowResource::RELATIONS)
            ->limit(self::CREATED_ROWS_LIMIT + 1)
            ->get();

        $hasMore = $rows->count() > self::CREATED_ROWS_LIMIT;
        $rows    = $this->inBatch($rows->take(self::CREATED_ROWS_LIMIT), $batch);

        return ApiResponse::created($this->batchBody($request, $batch) + [
            'rows'          => MassPayoutRowResource::collection($rows)->resolve($request),
            'rows_has_more' => $hasMore,
        ]);
    }

    public function show(Request $request, string $batch): JsonResponse
    {
        return ApiResponse::ok($this->batchBody($request, $this->ownedBatch($request, $batch)));
    }

    /** The batch's lines in file order, a page at a time, optionally of one status. */
    public function rows(Request $request, string $batch): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', Rule::enum(MassPaymentRowStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::ROWS_MAX_PER_PAGE],
        ]);

        $owned  = $this->ownedBatch($request, $batch);
        $status = isset($validated['status']) ? (string) $validated['status'] : null;

        $rows = $owned->rows()
            ->with(MassPayoutRowResource::RELATIONS)
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? self::ROWS_PER_PAGE));

        $this->inBatch($rows->getCollection(), $owned);

        return ApiResponse::paginated(MassPayoutRowResource::collection($rows));
    }

    public function report(Request $request, string $batch, MassPaymentReport $report): StreamedResponse
    {
        return $report->download($this->ownedBatch($request, $batch));
    }

    /**
     * Reserve the total and send the batch on or hold it for approval,
     * exactly once: 202 when this call reserved it, 200 with
     * `replayed: true` when it was already confirmed.
     */
    public function confirm(ConfirmMassPaymentRequest $request, string $batch): JsonResponse
    {
        try {
            $confirmed = $this->service->confirm($request->user(), $batch, $request->confirmation());
        } catch (MassPaymentChangedException $exception) {
            return ApiResponse::error(
                ApiErrorCode::MassPaymentChanged,
                $exception->getMessage(),
                extra: ['data' => ['batch' => $this->batchBody($request, $exception->batch)]],
            );
        } catch (MassPaymentNotConfirmableException $exception) {
            return ApiResponse::error(
                ApiErrorCode::MassPaymentNotConfirmable,
                $exception->getMessage(),
                extra: [
                    'reason' => $exception->reason,
                    'data'   => ['batch' => $exception->batch === null ? null : $this->batchBody($request, $exception->batch)],
                ],
            );
        }

        $body = $this->batchBody($request, $confirmed->batch) + ['replayed' => $confirmed->replayed];

        return $confirmed->replayed ? ApiResponse::ok($body) : ApiResponse::accepted($body);
    }

    public function discard(Request $request, string $batch): JsonResponse
    {
        return ApiResponse::ok($this->batchBody($request, $this->service->discard($request->user(), $batch)));
    }

    /**
     * Stop the batch: payouts not yet sent are refunded, those already with
     * their provider finish there. `already_resolved` is true when nothing
     * changed.
     */
    public function stop(Request $request, string $batch): JsonResponse
    {
        $result = $this->service->stop($request->user(), $batch);

        return ApiResponse::ok($this->batchBody($request, $result->batch) + [
            'already_resolved' => $result->is(MassPaymentActionResult::ALREADY_RESOLVED),
        ]);
    }

    /** One of the caller's payout batches, with its row counts, or a 404. */
    private function ownedBatch(Request $request, string $uuid): MassPaymentBatch
    {
        return MassPaymentBatchResource::withRowCounts($request->user()->massPaymentBatches()->ofKind(MassPaymentKind::Payout))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function batchBody(Request $request, MassPaymentBatch $batch): array
    {
        return (new MassPayoutBatchResource($batch->loadMissing(MassPaymentBatchResource::RELATIONS)))->resolve($request);
    }

    /**
     * @param  Collection<int, MassPaymentRow> $rows
     * @return Collection<int, MassPaymentRow>
     */
    private function inBatch(Collection $rows, MassPaymentBatch $batch): Collection
    {
        return $rows->each(fn (MassPaymentRow $row): MassPaymentRow => $row->setRelation('batch', $batch));
    }
}
