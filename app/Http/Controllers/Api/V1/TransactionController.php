<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AmountFlow;
use App\Enums\Api\ApiErrorCode;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Http\Controllers\Api\V1\Concerns\TransactionExtras;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Models\MerchantCategory;
use App\Services\Api\Insights\TransactionCategorizer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The customer's own ledger.
 *
 * Transaction::scopeApplyFilters is reused rather than reimplemented, so the
 * app and the panel cannot end up meaning different things by the same filter
 * name - but its `user_id` key is stripped before the filters reach it. That
 * key exists for the admin screens; forwarded from a request it would be an
 * invitation to read somebody else's ledger by naming them.
 *
 * Cursor pagination, not pages. A ledger grows at the top, so page two of an
 * offset-paginated list shifts under the reader every time a transaction
 * lands, and the app shows a row twice or skips one.
 */
class TransactionController extends Controller
{
    use TransactionExtras;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        $query = $this->applyExtraFilters(
            $request->user()
                ->transactions()
                // Not passed through from the request: the owner is the token's,
                // and applyFilters must not be told otherwise.
                ->applyFilters($filters + ['user_id' => null]),
            $filters,
        );

        // The filter sheet's live count: how many rows match, not how many
        // this page holds.
        $total = (clone $query)->count();

        $transactions          = $query->latest('id')->cursorPaginate($this->perPage($request))->withQueryString();
        $body                  = ApiResponse::paginated(TransactionResource::collection($transactions))->getData(true);
        $body['meta']['total'] = $total;

        return response()->json($body);
    }

    public function show(Request $request, string $transaction): JsonResponse
    {
        $record = $request->user()
            ->transactions()
            ->where('trx_id', $transaction)
            ->first();

        // Not found rather than forbidden, so a reference belonging to
        // somebody else cannot be confirmed to exist.
        return $record === null
            ? ApiResponse::error(ApiErrorCode::ResourceNotFound)
            : ApiResponse::ok(new TransactionResource($record));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'trx_type'        => ['sometimes', 'array'],
            'trx_type.*'      => [Rule::in(array_column(TrxType::cases(), 'value'))],
            'status'          => ['sometimes', Rule::in(array_column(TrxStatus::cases(), 'value'))],
            'processing_type' => ['sometimes', 'string', 'max:40'],
            'provider'        => ['sometimes', 'string', 'max:60'],
            'search'          => ['sometimes', 'string', 'max:120'],
            'dateRange'       => ['sometimes', 'string', 'max:64'],
            'from'            => ['sometimes', 'date'],
            'to'              => ['sometimes', 'date'],
            'currency'        => ['sometimes', 'string', 'max:10'],
            'flow'            => ['sometimes', Rule::in(['plus', 'minus'])],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        unset($validated['per_page']);

        return $validated;
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), self::MAX_PER_PAGE));
    }

    /**
     * The customer files a payment under another category. The row keeps
     * the choice, and so does the merchant: every later payment to them is
     * filed the same way without asking again.
     */
    public function updateCategory(Request $request, string $transaction): JsonResponse
    {
        $validated = $request->validate(['category' => ['required', 'string', Rule::in(TransactionCategorizer::ALL)]]);

        $record = $request->user()->transactions()->where('trx_id', $transaction)->first();

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if ($record->amount_flow !== AmountFlow::MINUS) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('Only spending can be categorised.'));
        }

        $record->update(['trx_data' => array_merge((array) $record->trx_data, ['category' => $validated['category']])]);

        $key = TransactionCategorizer::merchantKey($record);
        if ($key !== null) {
            MerchantCategory::query()->updateOrCreate(['user_id' => $request->user()->id, 'merchant_key' => $key], ['category' => $validated['category']]);
        }
        TransactionCategorizer::forget((int) $request->user()->id);

        return ApiResponse::ok(new TransactionResource($record->fresh()));
    }
}
