<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentLink\StorePaymentLinkRequest;
use App\Http\Requests\PaymentLink\UpdatePaymentLinkRequest;
use App\Http\Resources\Api\V1\Merchant\PaymentLinkResource;
use App\Models\PaymentLink;
use App\Services\PaymentLinkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment links, managed from the phone.
 *
 * No idempotency key on these. They create and edit a page, not a transfer;
 * a duplicate link on a retry is an annoyance a merchant deletes, not money
 * that moved twice. Requiring a key here would cost more than it protects.
 *
 * The route key is the token rather than the id, matching what the read
 * surface returns and what a merchant actually shares.
 *
 * Ownership is enforced by looking the link up through the caller's own rows.
 * The service checks it too for the merchant a link is attached to - a link
 * can name a merchant shop, and resolveMerchant refuses one belonging to
 * somebody else - so neither layer is doing the other's job.
 */
class PaymentLinkController extends Controller
{
    public function __construct(private readonly PaymentLinkService $paymentLinks) {}

    public function store(StorePaymentLinkRequest $request): JsonResponse
    {
        $link = $this->paymentLinks->create($request->user(), $request->validated());

        return ApiResponse::created(new PaymentLinkResource($link->load('currency')));
    }

    public function update(UpdatePaymentLinkRequest $request, string $link): JsonResponse
    {
        $record = $this->owned($request, $link);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $updated = $this->paymentLinks->update($record, $request->validated());

        return ApiResponse::ok(new PaymentLinkResource($updated->load('currency')));
    }

    /**
     * Stop or restart a link without deleting it.
     *
     * A merchant closing a campaign usually wants the page to stop taking
     * money, not to disappear from their own history.
     */
    public function toggle(Request $request, string $link): JsonResponse
    {
        $record = $this->owned($request, $link);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $updated = $this->paymentLinks->toggleStatus($record);

        return ApiResponse::ok(new PaymentLinkResource($updated->load('currency')));
    }

    public function destroy(Request $request, string $link): JsonResponse
    {
        $record = $this->owned($request, $link);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $record->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    private function owned(Request $request, string $token): ?PaymentLink
    {
        return PaymentLink::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $token)
            ->first();
    }
}
