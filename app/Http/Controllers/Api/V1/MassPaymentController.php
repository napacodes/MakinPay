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
use App\Http\Requests\Api\V1\MassPayment\PreviewMassPaymentRecipientRequest;
use App\Http\Requests\Api\V1\MassPayment\StoreMassPaymentRequest;
use App\Http\Resources\Api\V1\MassPaymentBatchResource;
use App\Http\Resources\Api\V1\MassPaymentRowResource;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Services\MassPayment\MassPaymentActionResult;
use App\Services\MassPayment\MassPaymentActor;
use App\Services\MassPayment\MassPaymentMonthSummary;
use App\Services\MassPayment\MassPaymentReport;
use App\Services\MassPayment\MassPaymentService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mass payments from the app: the history, the template, building a draft
 * from rows or a CSV, the preview's rows, confirming, discarding, stopping,
 * and the report.
 *
 * The same rules as the web pages, read from the same places. Every route
 * carries `feature.enabled:mass_payment` (the module and the caller's panel,
 * merchants only by default, which is why there is no api.role here: layer A
 * owns that decision) and both per-user switches, `feature:send_money` and
 * `feature:mass_payment`. Nothing here computes money; MassPaymentService
 * prices, reserves and pays, and this controller only shows the strings it
 * stored.
 *
 * Every batch is loaded through the caller's own relation, so another
 * customer's batch is RESOURCE_NOT_FOUND exactly like one that does not
 * exist. Confirm hands the uuid to the service, which checks the PIN first
 * and then looks the batch up in the same scope. That scope is wallet
 * batches only, so a payout batch, even the caller's own, is
 * RESOURCE_NOT_FOUND here as well.
 *
 * Confirming is safe to send twice whatever the middleware does. The route's
 * api.idempotency hands a retry with the same key the stored first answer
 * (202); a retry the middleware let through, with a new key or none, reaches
 * the service, which finds the batch already past Draft under its lock and
 * answers it as a replay (200, `replayed: true`) without a second debit.
 *
 * The refusals the app branches on: INSUFFICIENT_BALANCE (422, nothing
 * written), MASS_PAYMENT_CHANGED (409, with the refreshed batch to review
 * again) and MASS_PAYMENT_NOT_CONFIRMABLE (409, with a stable `reason`).
 * Both 409s are stored by api.idempotency, so a corrected confirm is sent
 * with a new key.
 */
class MassPaymentController extends Controller
{
    private const HISTORY_PER_PAGE = 15;

    private const HISTORY_MAX_PER_PAGE = 50;

    private const ROWS_PER_PAGE = 50;

    private const ROWS_MAX_PER_PAGE = 100;

    /** How many lines a new draft's answer carries; the rest are paged from rows. */
    private const CREATED_ROWS_LIMIT = 100;

    public function __construct(private readonly MassPaymentService $service) {}

    /**
     * The caller's batches, newest first, optionally of one status.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', Rule::enum(MassPaymentBatchStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::HISTORY_MAX_PER_PAGE],
        ]);

        $status = isset($validated['status']) ? (string) $validated['status'] : null;

        $batches = MassPaymentBatchResource::withRowCounts($request->user()->massPaymentBatches()->ofKind(MassPaymentKind::Wallet))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? self::HISTORY_PER_PAGE));

        return ApiResponse::paginated(MassPaymentBatchResource::collection($batches));
    }

    /**
     * The CSV template: the header the parser reads and two example lines.
     */
    public function template(MassPaymentReport $report): StreamedResponse
    {
        return $report->template();
    }

    /**
     * Build a draft from a JSON `rows` list or a multipart `file`. No money
     * moves and no PIN is asked: the answer is the preview, 201, with its
     * first lines. A file or list that cannot be read is VALIDATION_FAILED.
     */
    public function store(StoreMassPaymentRequest $request): JsonResponse
    {
        $batch = $this->service->createDraft(
            $request->user(),
            (string) $request->validated('wallet_id'),
            $request->draftInput(),
            $request->source(),
            MassPaymentService::CHANNEL_API,
            $request->batchName(),
        );

        $rows = $batch->rows()
            ->with(MassPaymentRowResource::RELATIONS)
            ->limit(self::CREATED_ROWS_LIMIT + 1)
            ->get();

        $hasMore = $rows->count() > self::CREATED_ROWS_LIMIT;
        $rows    = $this->inBatch($rows->take(self::CREATED_ROWS_LIMIT), $batch);

        return ApiResponse::created($this->batchBody($request, $batch) + [
            'rows'          => MassPaymentRowResource::collection($rows)->resolve($request),
            'rows_has_more' => $hasMore,
        ]);
    }

    /**
     * What the caller's wallet batches paid out this month, one entry per
     * currency.
     */
    public function summary(Request $request, MassPaymentMonthSummary $summary): JsonResponse
    {
        return ApiResponse::ok($summary->forUser($request->user(), MassPaymentKind::Wallet));
    }

    /**
     * One recipient checked as it is typed: whether the draft would find
     * them, and the masked label it would show. Costs one line of the hourly
     * recipient budget; over it is 429 like a draft.
     */
    public function previewRecipient(PreviewMassPaymentRecipientRequest $request): JsonResponse
    {
        $resolution = $this->service->previewRecipient($request->user(), (string) $request->validated('wallet_id'), $request->recipient());

        return ApiResponse::ok([
            'resolved' => $resolution->isResolved(),
            'type'     => $resolution->type,
            'label'    => $resolution->label,
            'issue'    => $resolution->issue === null ? null : [
                'code'    => $resolution->issue->value,
                'message' => $resolution->message(),
            ],
        ]);
    }

    public function show(Request $request, string $batch): JsonResponse
    {
        return ApiResponse::ok($this->batchBody($request, $this->ownedBatch($request, $batch)));
    }

    /**
     * The batch's lines in file order, a page at a time, optionally of one
     * status.
     */
    public function rows(Request $request, string $batch): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', Rule::enum(MassPaymentRowStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::ROWS_MAX_PER_PAGE],
        ]);

        $owned  = $this->ownedBatch($request, $batch);
        $status = isset($validated['status']) ? (string) $validated['status'] : null;

        $rows = $owned->rows()
            ->with(MassPaymentRowResource::RELATIONS)
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? self::ROWS_PER_PAGE));

        $this->inBatch($rows->getCollection(), $owned);

        return ApiResponse::paginated(MassPaymentRowResource::collection($rows));
    }

    public function report(Request $request, string $batch, MassPaymentReport $report): StreamedResponse
    {
        return $report->download($this->ownedBatch($request, $batch));
    }

    /**
     * Reserve the total and queue the payout, exactly once: 202 when this
     * call reserved it, 200 with `replayed: true` when the batch was already
     * confirmed.
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

    /**
     * Discard a draft. Discarding one already discarded answers it as it
     * stands; anything past Draft is refused.
     */
    public function discard(Request $request, string $batch): JsonResponse
    {
        $discarded = $this->service->discard($request->user(), $batch);

        return ApiResponse::ok($this->batchBody($request, $discarded));
    }

    /**
     * Stop a queued or paying batch: every line not yet paid is refunded.
     * `already_resolved` is true when it had already finished or was already
     * being stopped, in which case nothing changed.
     */
    public function stop(Request $request, string $batch): JsonResponse
    {
        $result = $this->service->requestStop(
            $this->ownedBatch($request, $batch),
            MassPaymentActor::user($request->user()),
        );

        return ApiResponse::ok($this->batchBody($request, $result->batch) + [
            'already_resolved' => $result->is(MassPaymentActionResult::ALREADY_RESOLVED),
        ]);
    }

    /**
     * One of the caller's batches, with its row counts, or a 404.
     */
    private function ownedBatch(Request $request, string $uuid): MassPaymentBatch
    {
        return MassPaymentBatchResource::withRowCounts($request->user()->massPaymentBatches()->ofKind(MassPaymentKind::Wallet))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * The batch as the app sees it. Every batch passed here was loaded in
     * the caller's scope or handed back by the service for the caller's own
     * batch; one loaded without its row counts has them counted by the
     * resource.
     *
     * @return array<string, mixed>
     */
    private function batchBody(Request $request, MassPaymentBatch $batch): array
    {
        return (new MassPaymentBatchResource($batch->loadMissing(MassPaymentBatchResource::RELATIONS)))->resolve($request);
    }

    /**
     * Give each row its batch, so the resource reads the currency without a
     * query per row.
     *
     * @param  Collection<int, MassPaymentRow> $rows
     * @return Collection<int, MassPaymentRow>
     */
    private function inBatch(Collection $rows, MassPaymentBatch $batch): Collection
    {
        return $rows->each(fn (MassPaymentRow $row): MassPaymentRow => $row->setRelation('batch', $batch));
    }
}
