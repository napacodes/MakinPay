<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduledPayment;
use App\Notifications\ScheduledPaymentNotification;
use App\Support\ApiMoney;
use Illuminate\Console\Command;

/**
 * The day before a scheduled payment, at the hour the customer chose,
 * say what is coming and what the wallet holds. Stamped per run so an
 * hourly tick sends it once.
 */
class RemindScheduledPayments extends Command
{
    protected $signature = 'schedules:remind';

    protected $description = 'Remind customers about scheduled payments due tomorrow.';

    public function handle(): int
    {
        $sent = 0;
        $now  = now();
        $hour = (int) $now->format('H');
        ScheduledPayment::query()->where('status', 'active')->where('remind', true)->whereNotNull('next_run_at')
            ->whereBetween('next_run_at', [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()])
            ->with(['user', 'wallet.currency'])->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$sent, $hour): void {
                foreach ($chunk as $schedule) {
                    $at = (int) substr((string) $schedule->remind_at, 0, 2);
                    if ($hour < $at || ($schedule->reminded_for !== null && $schedule->reminded_for->equalTo($schedule->next_run_at))) {
                        continue;
                    }
                    $wallet = $schedule->wallet;
                    $schedule->update(['reminded_for' => $schedule->next_run_at]);
                    $schedule->user?->notify(new ScheduledPaymentNotification($schedule, 'scheduled_payment_reminder', [
                        'balance' => $wallet === null ? '' : ApiMoney::amount((string) $wallet->balance, (string) $wallet->currency->code).' '.$wallet->currency->code,
                    ]));
                    $sent++;
                }
            });
        $this->components->info(sprintf('%d reminder(s) sent.', $sent));

        return self::SUCCESS;
    }
}
