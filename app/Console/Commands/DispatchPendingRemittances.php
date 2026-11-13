<?php

namespace App\Console\Commands;

use App\Enums\RemittanceStatus;
use App\Jobs\DispatchRemittancePayout;
use App\Models\RemittanceTransfer;
use Illuminate\Console\Command;

class DispatchPendingRemittances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remittance:dispatch-pending {--limit=100 : Maximum transfers to enqueue per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recover and enqueue remittance payouts that are waiting for dispatch';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $count = 0;

        RemittanceTransfer::query()
            ->where('status', RemittanceStatus::Pending->value)
            ->where(function ($query): void {
                $query->where('dispatch_state', 'scheduled')
                    ->orWhere(function ($query): void {
                        $query->where('dispatch_state', 'ambiguous')
                            ->whereNotNull('gateway_reference')
                            ->where('dispatch_attempts', '<', 5)
                            ->where(function ($query): void {
                                $query->whereNull('last_dispatch_at')
                                    ->orWhere('last_dispatch_at', '<=', now()->subMinutes(5));
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $transferId) use (&$count): void {
                DispatchRemittancePayout::dispatch($transferId);
                $count++;
            });

        $this->info(__('Queued :count pending remittance payout(s).', ['count' => $count]));

        return self::SUCCESS;
    }
}
