<?php

namespace App\Console\Commands;

use App\Models\PaymentGateway;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use Illuminate\Console\Command;

class CheckRemittancePayoutProvider extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remittance:check-payout-provider
                            {gateway : Registered payout gateway code}
                            {--enable : Enable remittance only after a successful health probe}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a read-only payout provider health check and optionally enable remittance';

    /**
     * Execute the console command.
     */
    public function handle(PayoutGatewayFactory $factory): int
    {
        $gatewayCode = strtolower(trim((string) $this->argument('gateway')));
        $result      = $factory->probe($gatewayCode);

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        if (! $this->option('enable') || $gatewayCode === 'manual') {
            return self::SUCCESS;
        }

        $gateway = PaymentGateway::query()->where('code', $gatewayCode)->first();

        if (! $gateway) {
            $this->error(__('The payment gateway record was not found.'));

            return self::FAILURE;
        }

        $credentials                       = (array) $gateway->credentials;
        $credentials['remittance_enabled'] = true;
        $gateway->update(['credentials' => $credentials]);

        $this->info(__('Remittance payouts are now enabled for :gateway.', ['gateway' => $gatewayCode]));

        return self::SUCCESS;
    }
}
