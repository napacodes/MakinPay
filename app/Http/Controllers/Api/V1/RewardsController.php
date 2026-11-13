<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Services\Rewards\CashbackService;
use App\Services\Rewards\RewardsHub;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The rewards hub from the app: totals across cashback, referrals and
 * ranks, the month of cashback ready to claim, and the history behind
 * the totals. Claiming credits the default wallet once.
 */
class RewardsController extends Controller
{
    public function __construct(private readonly RewardsHub $hub, private readonly CashbackService $cashback) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->hub->for($request->user()));
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['sometimes', Rule::in(RewardsHub::KINDS)],
        ]);

        return ApiResponse::paginated($this->hub->history($request->user(), $validated['kind'] ?? null));
    }

    public function claimCashback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        try {
            $claim = $this->cashback->claim($request->user(), $validated['period']);
        } catch (NotifyErrorException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        return ApiResponse::ok(['claim' => $this->cashback->row($claim)] + $this->hub->for($request->user()));
    }
}
