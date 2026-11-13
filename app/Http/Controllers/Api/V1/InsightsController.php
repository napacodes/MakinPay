<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\TransactionExtras;
use App\Http\Controllers\Controller;
use App\Services\Api\Insights\InsightsService;
use App\Services\Api\Insights\TransactionCategorizer;
use App\Services\CurrencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The customer's spending, summarised on the server from their own rows.
 * `offset` counts periods back from the current one (0 now, -1 the one
 * before), so the app can page through months without knowing the calendar.
 */
class InsightsController extends Controller
{
    use TransactionExtras;

    public function __construct(private readonly InsightsService $insights, private readonly CurrencyService $currencies) {}

    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period'   => ['sometimes', 'string', Rule::in(InsightsService::PERIODS)],
            'offset'   => ['sometimes', 'integer', 'min:-60', 'max:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
        ]);

        return ApiResponse::ok($this->insights->overview(
            $request->user(),
            $validated['period'] ?? 'month',
            (int) ($validated['offset'] ?? 0),
            $this->displayCurrencyFor($request, $request->user(), $this->currencies),
        ));
    }

    public function category(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate([
            'period'   => ['sometimes', 'string', Rule::in(InsightsService::PERIODS)],
            'offset'   => ['sometimes', 'integer', 'min:-60', 'max:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
        ]);

        abort_unless(TransactionCategorizer::isValid($category), 404);

        return ApiResponse::ok($this->insights->category(
            $request->user(),
            $category,
            $validated['period'] ?? 'month',
            (int) ($validated['offset'] ?? 0),
            $this->displayCurrencyFor($request, $request->user(), $this->currencies),
        ));
    }
}
