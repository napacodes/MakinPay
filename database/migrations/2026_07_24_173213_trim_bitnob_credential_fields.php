<?php

use App\Models\PaymentGateway;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bitnob only needs the Client ID + HMAC Key (API auth) and the Webhook
     * Secret (webhook verification); everything else falls back to defaults in
     * BitnobProviderSettings. Drop the unused/dead credential keys
     * (public_key, secret_key, lightning_key and the default-backed operational
     * settings) from the existing gateway so the Credential Vault only shows
     * fields that matter. Values entered for the kept keys are preserved.
     */
    private const KEEP = [
        'client_id',
        'hmac_key',
        'webhook_secret',
        'sandbox',
        'remittance_enabled',
    ];

    public function up(): void
    {
        $gateway = PaymentGateway::query()->where('code', 'bitnob')->first();

        if (! $gateway) {
            return;
        }

        $credentials = is_array($gateway->credentials) ? $gateway->credentials : [];

        $trimmed = array_intersect_key($credentials, array_flip(self::KEEP));
        $trimmed += [
            'sandbox'            => $credentials['sandbox']            ?? true,
            'remittance_enabled' => $credentials['remittance_enabled'] ?? false,
        ];

        $gateway->update(['credentials' => $trimmed]);
    }

    public function down(): void
    {
        // Removed keys were unused or default-backed; nothing to restore.
    }
};
