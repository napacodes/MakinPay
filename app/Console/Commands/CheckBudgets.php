<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Budget;
use App\Notifications\BudgetAlertNotification;
use App\Services\Api\Budgets\BudgetService;
use App\Support\SpendCategoryLabel;
use Illuminate\Console\Command;

/**
 * Tell a customer when a budget has crossed the line they drew, or gone
 * over - once per period per level, stamped on the budget so the next
 * hour's run says nothing twice.
 */
class CheckBudgets extends Command
{
    protected $signature = 'budgets:check';

    protected $description = 'Send budget warnings for budgets past their alert threshold.';

    public function handle(BudgetService $budgets): int
    {
        $sent = 0;

        Budget::query()->with('user')->orderBy('id')->chunkById(200, function ($chunk) use ($budgets, &$sent): void {
            foreach ($chunk as $budget) {
                $user = $budget->user;
                if ($user === null) {
                    continue;
                }
                $item   = $budgets->describe($user, $budget, $user->displayCurrency());
                $level  = BudgetService::alertLevel($item['ratio'], $budget->alert_threshold);
                $period = $budget->periodKey();

                if ($level === null || ($budget->last_alert_period === $period && (int) $budget->last_alert_level >= $level)) {
                    continue;
                }

                $budget->update(['last_alert_period' => $period, 'last_alert_level' => $level]);
                $user->notify(new BudgetAlertNotification($budget, $item, SpendCategoryLabel::of($budget->category)));
                $sent++;
            }
        });

        $this->components->info(sprintf('%d budget alert(s) sent.', $sent));

        return self::SUCCESS;
    }
}
