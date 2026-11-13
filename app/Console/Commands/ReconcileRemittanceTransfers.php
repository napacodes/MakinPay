<?php

namespace App\Console\Commands;

use App\Enums\RemittanceStatus;
use App\Jobs\ReconcileRemittancePayout;
use App\Models\RemittanceTransfer;
use Illuminate\Console\Command;

class ReconcileRemittanceTransfers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remittance:reconcile {--limit=100 : Maximum in-flight transfers to enqueue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue safe provider-status reconciliation for stale in-flight remittances';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit  = max(1, min(1000, (int) $this->option('limit')));
        $cutoff = now()->subMinutes(max(1, (int) config('remittance.reconciliation.poll_after_minutes', 5)));
        $count  = 0;

        RemittanceTransfer::query()
            ->whereIn('status', [RemittanceStatus::Submitted->value, RemittanceStatus::Processing->value])
            ->where('payout_gateway', '!=', 'manual')
            ->whereNotNull('gateway_reference')
            ->whereNotNull('provider_idempotency_key')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_reconciled_at')
                    ->orWhere('last_reconciled_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $transferId) use (&$count): void {
                ReconcileRemittancePayout::dispatch($transferId);
                $count++;
            });

        $this->info(__('Queued :count remittance reconciliation job(s).', ['count' => $count]));

        return self::SUCCESS;
    }
}
