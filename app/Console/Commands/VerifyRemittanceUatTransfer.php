<?php

namespace App\Console\Commands;

use App\Enums\RemittanceStatus;
use App\Models\RemittanceTransfer;
use App\Services\Remittance\RemittanceReadinessService;
use App\Services\Remittance\RemittanceService;
use App\Services\Remittance\RemittanceUatVerifier;
use Illuminate\Console\Command;

class VerifyRemittanceUatTransfer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remittance:uat
                            {reference : Existing sandbox remittance reference}
                            {--reconcile : Run one read-only provider status poll before verification}
                            {--json : Return a machine-readable report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify an existing sandbox remittance across provider, compliance, ledger, and audit layers';

    /**
     * Execute the console command.
     */
    public function handle(
        RemittanceReadinessService $readiness,
        RemittanceService $remittance,
        RemittanceUatVerifier $verifier,
    ): int {
        $reference = trim((string) $this->argument('reference'));
        $transfer  = RemittanceTransfer::query()->where('reference', $reference)->first();

        if (! $transfer) {
            $this->error(__('Remittance transfer :reference was not found.', ['reference' => $reference]));

            return self::FAILURE;
        }

        $readinessReport = $readiness->report(
            production: true,
            probe: true,
            requiredGateways: [$transfer->payout_gateway],
        );

        if (! $readinessReport['ready']) {
            $failed = collect($readinessReport['checks'])
                ->where('status', 'fail')
                ->values()
                ->all();

            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'passed' => false,
                    'stage'  => 'readiness',
                    'checks' => $failed,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                foreach ($failed as $check) {
                    $this->error($check['label'].': '.$check['message']);
                }
            }

            return self::FAILURE;
        }

        if ($this->option('reconcile') && in_array($transfer->status, [
            RemittanceStatus::Submitted,
            RemittanceStatus::Processing,
        ], true)) {
            $transfer = $remittance->reconcileProviderStatusById($transfer->id);
        }

        $report = $verifier->verify($transfer->fresh());

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'reference' => $transfer->reference,
                ...$report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                [__('Status'), __('Check'), __('Evidence')],
                collect($report['checks'])->map(fn (array $check): array => [
                    $check['passed'] ? 'PASS' : 'FAIL',
                    $check['id'],
                    $check['message'],
                ])->all(),
            );
        }

        if (! $report['passed']) {
            $this->error(__('Sandbox remittance UAT did not pass every invariant.'));

            return self::FAILURE;
        }

        $this->info(__('Sandbox remittance UAT passed for :reference.', ['reference' => $transfer->reference]));

        return self::SUCCESS;
    }
}
