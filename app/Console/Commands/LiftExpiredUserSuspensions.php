<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Clears suspensions whose auto-lift time has passed.
 *
 * `User::isSuspended()` already reads the clock, so an expired suspension
 * stops blocking sign-in on its own; this sweep tidies the columns so the
 * admin screens stop showing a lapsed window and the lift is recorded.
 */
class LiftExpiredUserSuspensions extends Command
{
    protected $signature = 'users:lift-expired-suspensions';

    protected $description = 'Lift user suspensions whose auto-lift time has passed';

    public function handle(): int
    {
        $lifted = 0;

        User::query()
            ->whereNotNull('suspended_at')
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->each(function (User $user) use (&$lifted): void {
                Log::info('User suspension auto-lifted', [
                    'user_id'         => $user->id,
                    'reason'          => $user->suspension_reason,
                    'suspended_at'    => $user->suspended_at?->toDateTimeString(),
                    'suspended_until' => $user->suspended_until?->toDateTimeString(),
                ]);

                $user->liftSuspension();
                $lifted++;
            });

        $this->info("Lifted {$lifted} expired suspension(s).");

        return self::SUCCESS;
    }
}
