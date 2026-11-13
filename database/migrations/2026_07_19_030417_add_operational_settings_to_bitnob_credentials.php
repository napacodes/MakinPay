<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $gateway = DB::table('payment_gateways')->where('code', 'bitnob')->first();

        if (! $gateway) {
            return;
        }

        $credentials = json_decode((string) $gateway->credentials, true);

        if (! is_array($credentials)) {
            return;
        }

        $credentials += [
            'sandbox_base_url'          => 'https://api.bitnob.com',
            'live_base_url'             => 'https://api.bitnob.com',
            'card_sandbox_base_url'     => 'https://sandboxapi.bitnob.co/api/v1',
            'card_live_base_url'        => 'https://api.bitnob.co/api/v1',
            'request_timeout'           => 20,
            'remittance_payment_reason' => 'personal_transfer',
        ];

        DB::table('payment_gateways')->where('id', $gateway->id)->update([
            'credentials' => json_encode($credentials),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $gateway = DB::table('payment_gateways')->where('code', 'bitnob')->first();

        if (! $gateway) {
            return;
        }

        $credentials = json_decode((string) $gateway->credentials, true);

        if (! is_array($credentials)) {
            return;
        }

        foreach ([
            'sandbox_base_url',
            'live_base_url',
            'card_sandbox_base_url',
            'card_live_base_url',
            'request_timeout',
            'remittance_payment_reason',
        ] as $key) {
            unset($credentials[$key]);
        }

        DB::table('payment_gateways')->where('id', $gateway->id)->update([
            'credentials' => json_encode($credentials),
            'updated_at'  => now(),
        ]);
    }
};
