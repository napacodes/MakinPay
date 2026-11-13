<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\Api\Budgets\BudgetService;
use App\Services\Api\Insights\TransactionCategorizer;
use App\Support\AmountEntry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Budgets: one per spending category, in the display currency, measured
 * against Insights. The overview carries the hero, every budget with what
 * it has spent, and where a first budget could start.
 */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function index(Request $request): JsonResponse
    {
        $offset = (int) $request->integer('offset', 0);

        return ApiResponse::ok($this->budgets->overview($request->user(), max(-24, min(0, $offset))));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $user      = $request->user();

        if ($user->budgets()->where('category', $validated['category'])->exists()) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('There is already a budget for that category.'), ['category' => [__('There is already a budget for that category.')]]);
        }

        $budget = $user->budgets()->create($validated + ['currency' => $user->displayCurrency()]);

        return ApiResponse::created(['budget' => $this->budgets->describe($user, $budget, $user->displayCurrency())]);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        $this->own($request, $budget);
        $validated = $this->validated($request, $budget);

        if ($validated['category'] !== $budget->category && $request->user()->budgets()->where('category', $validated['category'])->exists()) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('There is already a budget for that category.'), ['category' => [__('There is already a budget for that category.')]]);
        }

        // A raised limit is a fresh start for alerts; a period already alerted
        // on is alerted on again only when the new limit is crossed too.
        $budget->fill($validated);
        if ($budget->isDirty(['amount', 'alert_threshold', 'category', 'period'])) {
            $budget->last_alert_level = null;
        }
        $budget->save();

        return ApiResponse::ok(['budget' => $this->budgets->describe($request->user(), $budget->refresh(), $request->user()->displayCurrency())]);
    }

    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        $this->own($request, $budget);
        $budget->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Budget $budget = null): array
    {
        $display = $request->user()->displayCurrency();

        $validated = $request->validate([
            'category'        => ['required', 'string', Rule::in(TransactionCategorizer::ALL)],
            'amount'          => AmountEntry::rules($display),
            'period'          => ['required', Rule::in(Budget::PERIODS)],
            'starts_on'       => ['required_if:period,custom', 'nullable', 'date'],
            'ends_on'         => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:starts_on'],
            'alert_threshold' => ['nullable', 'integer', Rule::in(Budget::THRESHOLDS)],
            'rollover'        => ['nullable', 'boolean'],
        ]);

        return [
            'category'        => $validated['category'],
            'amount'          => (string) $validated['amount'],
            'period'          => $validated['period'],
            'starts_on'       => $validated['period'] === 'custom' ? $validated['starts_on'] : null,
            'ends_on'         => $validated['period'] === 'custom' ? $validated['ends_on'] : null,
            'alert_threshold' => (int) ($validated['alert_threshold'] ?? $budget?->alert_threshold ?? 80),
            'rollover'        => (bool) ($validated['rollover'] ?? $budget?->rollover ?? false),
        ];
    }

    private function own(Request $request, Budget $budget): void
    {
        abort_unless((int) $budget->user_id === (int) $request->user()->id, 404);
    }
}
