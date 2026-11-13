<?php

namespace App\Jobs;

use App\Models\LoginActivity;
use App\Services\Security\LoginRiskEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Judges one recorded sign-in away from the request that made it.
 *
 * Queued on purpose: the rules read that account's own history, and the
 * person is already signed in by the time this runs. Holding the response
 * open to compare geography would make every login as slow as the slowest
 * of those queries, and a failure here must never look like a failed login.
 */
class EvaluateLoginRisk implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $activityId) {}

    public function handle(LoginRiskEvaluator $evaluator): void
    {
        $activity = LoginActivity::find($this->activityId);

        if ($activity === null) {
            return;
        }

        try {
            $evaluator->evaluate($activity);
        } catch (Throwable $exception) {
            /*
             * The row keeps its `normal` default, which is the honest answer
             * when nothing was successfully measured about it.
             */
            Log::warning('Could not evaluate the risk of a sign-in.', [
                'login_activity_id' => $activity->id,
                'reason'            => $exception->getMessage(),
            ]);
        }
    }
}
