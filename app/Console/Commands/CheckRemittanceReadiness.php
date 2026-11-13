<?php

namespace App\Console\Commands;

use App\Services\Remittance\RemittanceReadinessService;
use Illuminate\Console\Command;

class CheckRemittanceReadiness extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remittance:readiness
                            {--production : Apply production and staging-UAT requirements}
                            {--probe : Contact configured external providers using read-only health endpoints}
                            {--gateway=* : Require and optionally probe a payout gateway even when no active corridor uses it}
                            {--json : Return a machine-readable report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate remittance production configuration and external UAT readiness';

    /**
     * Execute the console command.
     */
    public function handle(RemittanceReadinessService $readiness): int
    {
        $report = $readiness->report(
            production: (bool) $this->option('production'),
            probe: (bool) $this->option('probe'),
            requiredGateways: (array) $this->option('gateway'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            [__('Status'), __('Check'), __('Result')],
            collect($report['checks'])->map(fn (array $check): array => [
                strtoupper($check['status']),
                $check['label'],
                $check['message'],
            ])->all(),
        );

        if (! $report['ready']) {
            $this->error(__('Remittance is not ready for production/UAT. Resolve every FAIL item before deployment.'));

            return self::FAILURE;
        }

        $this->info(__('Remittance configuration checks passed. WARN items still require operational review.'));

        return self::SUCCESS;
    }
}
