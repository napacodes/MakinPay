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

        if (! is_array($credentials) || array_key_exists('remittance_enabled', $credentials)) {
            return;
        }

        $credentials['remittance_enabled'] = false;

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

        if (! is_array($credentials) || ! array_key_exists('remittance_enabled', $credentials)) {
            return;
        }

        unset($credentials['remittance_enabled']);

        DB::table('payment_gateways')->where('id', $gateway->id)->update([
            'credentials' => json_encode($credentials),
            'updated_at'  => now(),
        ]);
    }
};
