<?php

namespace App\Console\Commands;

use App\Services\MerchantSubscriptionService;
use Illuminate\Console\Command;

class ProcessMerchantSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'merchant-subscriptions:process {--limit=100 : Maximum subscriptions to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create hosted renewal invoices for due merchant API subscriptions';

    /**
     * Execute the console command.
     */
    public function handle(MerchantSubscriptionService $service): int
    {
        $limit     = max(1, min(1000, (int) $this->option('limit')));
        $processed = $service->processDue($limit);

        $this->info(__('Created :count merchant subscription renewal invoice(s).', ['count' => $processed]));

        return self::SUCCESS;
    }
}
