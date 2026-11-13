<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * StroWallet now issues a single public key, and the secret key became an
 * optional credential that only signs the legacy express IPN. Installations
 * seeded before that still carry the placeholder `'secret_key' => 'secret_key'`,
 * which the admin form would show as though a secret had been entered.
 *
 * Only that placeholder is cleared. A secret an operator typed in is left
 * exactly as it is, and the field itself stays so an account that still
 * uses the express flow can keep its secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        $gateway = DB::table('payment_gateways')->where('code', 'strowallet')->first();

        if (! $gateway) {
            return;
        }

        $credentials = json_decode((string) $gateway->credentials, true);

        if (! is_array($credentials)) {
            return;
        }

        $secret = $credentials['secret_key'] ?? null;

        if (! is_string($secret) || strcasecmp(trim($secret), 'secret_key') !== 0) {
            return;
        }

        $credentials['secret_key'] = '';

        DB::table('payment_gateways')->where('id', $gateway->id)->update([
            'credentials' => json_encode($credentials),
            'updated_at'  => now(),
        ]);

        Cache::forget('payment_gateways_all');
        Cache::forget('payment_gateway_code_strowallet');
        Cache::forget('payment_gateway_id_'.$gateway->id);
    }

    public function down(): void
    {
        //
    }
};
