<?php

namespace App\Console\Commands;

use App\Enums\RemittanceStatus;
use App\Models\Admin;
use App\Models\RemittanceTransfer;
use App\Notifications\RemittanceOperationsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class AlertStuckRemittanceTransfers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remittance:alert-stuck {--limit=500 : Maximum unresolved transfers to include}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify remittance admins about stale or ambiguous payout operations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit        = max(1, min(1000, (int) $this->option('limit')));
        $stuckCutoff  = now()->subMinutes(max(1, (int) config('remittance.reconciliation.stuck_after_minutes', 30)));
        $repeatCutoff = now()->subMinutes(max(1, (int) config('remittance.reconciliation.alert_repeat_minutes', 360)));

        $transfers = RemittanceTransfer::query()
            ->where('payout_gateway', '!=', 'manual')
            ->where(function ($query) use ($stuckCutoff): void {
                $query->where(function ($query) use ($stuckCutoff): void {
                    $query->whereIn('status', [RemittanceStatus::Submitted->value, RemittanceStatus::Processing->value])
                        ->where('created_at', '<=', $stuckCutoff);
                })->orWhere(function ($query) use ($stuckCutoff): void {
                    $query->where('status', RemittanceStatus::Pending->value)
                        ->where('dispatch_state', 'ambiguous')
                        ->where('last_dispatch_at', '<=', $stuckCutoff);
                });
            })
            ->where(function ($query) use ($repeatCutoff): void {
                $query->whereNull('reconciliation_alerted_at')
                    ->orWhere('reconciliation_alerted_at', '<=', $repeatCutoff);
            })
            ->oldest()
            ->limit($limit)
            ->get(['id', 'reference']);

        if ($transfers->isEmpty()) {
            $this->info(__('No unalerted stuck remittance transfers were found.'));

            return self::SUCCESS;
        }

        $admins = Admin::permission('remittance-transfer-list')
            ->where('status', true)
            ->get();

        if ($admins->isEmpty()) {
            $this->error(__('No active remittance administrator can receive the operations alert.'));

            return self::FAILURE;
        }

        Notification::sendNow($admins, new RemittanceOperationsAlert(
            count: $transfers->count(),
            oldestReference: (string) $transfers->first()->reference,
        ));

        RemittanceTransfer::query()
            ->whereKey($transfers->modelKeys())
            ->update(['reconciliation_alerted_at' => now()]);

        $this->info(__('Alerted :admins admin(s) about :count unresolved remittance payout(s).', [
            'admins' => $admins->count(),
            'count'  => $transfers->count(),
        ]));

        return self::SUCCESS;
    }
}
