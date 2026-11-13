<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduledPayment;
use App\Services\Schedules\ScheduledPaymentRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Make the scheduled payments whose moment has come. Each is one attempt
 * written under a unique key, so overlapping ticks cannot pay twice.
 */
class RunScheduledPayments extends Command
{
    protected $signature = 'schedules:run';

    protected $description = 'Run scheduled payments that are due.';

    public function handle(ScheduledPaymentRunner $runner): int
    {
        $ran      = 0;
        $released = $runner->releaseStale();
        ScheduledPayment::query()->where('status', 'active')->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->with(['user', 'wallet.currency', 'vault'])->orderBy('next_run_at')
            ->chunkById(50, function ($chunk) use ($runner, &$ran): void {
                foreach ($chunk as $schedule) {
                    // One schedule's surprise must not cost the rest their run.
                    try {
                        if ($runner->run($schedule) !== null) {
                            $ran++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
        $this->components->info(sprintf('%d scheduled payment(s) run, %d interrupted run(s) released.', $ran, $released));

        return self::SUCCESS;
    }
}
