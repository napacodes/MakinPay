<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Marketplace split routing. `marketplace_settings.split_provider` selects
 * which SplitProvider settles new orders (wallet | stripe_connect) and
 * `marketplace_orders.provider_reference` stores the gateway-side handle
 * (Stripe PaymentIntent, Mercado Pago payment id, ...) needed to refund an
 * order that never touched an internal wallet.
 *
 * The Stripe Connect credentials ride on the existing `payment_gateways`
 * row, so the admin gateway modal renders them without any schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_settings', function (Blueprint $table) {
            $table->string('split_provider', 30)->default('wallet')->after('enabled');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->string('provider_reference')->nullable()->after('provider')->index();
        });

        $this->mergeStripeConnectCredentials();

        Cache::forget('marketplace_settings.current');
    }

    public function down(): void
    {
        Schema::table('marketplace_settings', function (Blueprint $table) {
            $table->dropColumn('split_provider');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropIndex(['provider_reference']);
            $table->dropColumn('provider_reference');
        });

        Cache::forget('marketplace_settings.current');
    }

    /**
     * Add the Connect keys to the Stripe gateway credentials without
     * disturbing the keys an admin has already filled in.
     */
    private function mergeStripeConnectCredentials(): void
    {
        $gateway = DB::table('payment_gateways')->where('code', 'stripe')->first();

        if (! $gateway) {
            return;
        }

        $credentials = json_decode((string) $gateway->credentials, true);
        $credentials = is_array($credentials) ? $credentials : [];

        $defaults = [
            'connect_enabled'        => false,
            'connect_account_type'   => 'express',
            'connect_webhook_secret' => 'connect_webhook_secret',
        ];

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $credentials)) {
                $credentials[$key] = $value;
            }
        }

        DB::table('payment_gateways')->where('code', 'stripe')->update([
            'credentials' => json_encode($credentials),
            'updated_at'  => now(),
        ]);

        Cache::forget('payment_gateways_all');
        Cache::forget('payment_gateway_code_stripe');
    }
};
