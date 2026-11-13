<?php

namespace App\Console\Commands\Bitnob;

use App\Services\Bitnob\BitnobService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-service probe for Bitnob stablecoin address generation.
 *
 * Runs on the operator's own server with the Client ID + HMAC Key already
 * stored on the gateway — credentials never leave the machine. It calls the
 * same signed `POST /api/addresses` endpoint the deposit flow uses, so a
 * success here means crypto deposits will work, and a failure prints the
 * provider's own message (for example when a chain is not enabled for the
 * account).
 */
class TestStablecoinAddress extends Command
{
    protected $signature = 'bitnob:test-address {chain=ethereum : Chain (ethereum, polygon, base, solana, tron, bsc)} {--currency=USDT : USDT or USDC}';

    protected $description = 'Probe Bitnob stablecoin address generation with the saved credentials';

    public function handle(): int
    {
        // Resolved here rather than injected: the client validates credentials
        // in its constructor, and a misconfigured gateway should print a clean
        // message instead of an unhandled container exception.
        try {
            $api = app(BitnobService::class);
        } catch (Throwable $e) {
            $this->error('✘ '.$e->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'chain'    => strtolower(trim((string) $this->argument('chain'))),
            'currency' => strtoupper(trim((string) $this->option('currency'))),
            'label'    => 'DK probe',
        ];

        $this->line('Mode:  <info>'.$api->mode().'</info>');
        $this->line('POST:  <info>'.$api->baseUrl().$api->url('deposit_address').'</info>');
        $this->line('Body:  '.json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            $response = $api->post($api->url('deposit_address'), $payload);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('✘ '.$e->getMessage());
            $this->warn('If the message mentions an unsupported chain, ask Bitnob to enable it for your account, or pick one of the chains they have enabled.');

            return self::FAILURE;
        }

        $data    = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $address = $data['address'] ?? null;

        $this->newLine();
        $this->line('Response: '.json_encode($data, JSON_UNESCAPED_SLASHES));
        $this->newLine();

        if (! $address) {
            $this->warn('✘ Bitnob accepted the request but returned no address — share the response above.');

            return self::FAILURE;
        }

        $this->info("✔ Address generated on {$payload['chain']}: {$address}");

        return self::SUCCESS;
    }
}
