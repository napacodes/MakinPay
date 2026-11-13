<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\NotifyErrorException;
use App\Models\RateAlert;
use App\Notifications\RateAlertTriggeredNotification;
use App\Services\Api\RateAlertService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fire every active rate alert the market has reached. Scheduled right
 * after `currency:refresh-rates`, so it sees the rates that run wrote.
 */
class CheckRateAlerts extends Command
{
    protected $signature = 'rate-alerts:check';

    protected $description = 'Notify customers whose rate targets have been reached, and run any auto-exchanges.';

    public function handle(RateAlertService $alerts): int
    {
        $fired = 0;

        RateAlert::query()->active()->with(['user', 'sourceWallet.currency', 'destinationWallet.currency'])->orderBy('id')->chunkById(100, function ($chunk) use ($alerts, &$fired): void {
            foreach ($chunk as $alert) {
                $rate = $alerts->currentRate($alert->from_currency, $alert->to_currency);

                if ($rate === null || ! $alert->isMetBy($rate)) {
                    continue;
                }

                /*
                 * Claimed before anything else happens. A second worker (or a
                 * retry) that finds the row no longer active does nothing, so
                 * an auto-exchange cannot run twice for one alert.
                 */
                $claimed = RateAlert::query()
                    ->whereKey($alert->id)
                    ->where('status', RateAlert::STATUS_ACTIVE)
                    ->update(['status' => RateAlert::STATUS_TRIGGERED, 'triggered_rate' => $rate, 'triggered_at' => now()]);

                if ($claimed !== 1) {
                    continue;
                }

                $reference = null;
                $failure   = null;
                if ($alert->auto_exchange) {
                    try {
                        $reference = $alerts->autoExchange($alert->refresh());
                    } catch (NotifyErrorException $exception) {
                        // Written for the customer - "Insufficient balance." -
                        // so it is what they are told.
                        $failure = $exception->getMessage();
                    } catch (Throwable $exception) {
                        report($exception);
                        $failure = __('something went wrong on our side.');
                    }
                }

                $alert->user?->notify(new RateAlertTriggeredNotification($alert->refresh(), $reference, $failure));
                $fired++;
            }
        });

        $this->components->info(sprintf('%d alert(s) fired.', $fired));

        return self::SUCCESS;
    }
}
