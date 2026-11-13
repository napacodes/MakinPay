<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Registers BSICARDS as a virtual-card provider on existing installations.
 *
 * Two rows, each inserted only when missing:
 *
 *  - payment_gateways `bsicards` holds the API keys the admin enters (public
 *    key + secret key; the key prefix decides sandbox or live, so there is no
 *    sandbox toggle) and receives the card webhooks at /ipn/bsicards.
 *  - virtual_card_providers `bsicards` issues Visa (Digital Visa Wallet) and
 *    Mastercard (Digital USD Mastercard) cards in USD.
 *
 * The provider starts switched off: it cannot issue until keys are entered,
 * and offering it to customers before that would only produce failed
 * requests. An installation that already has either row — added by hand or
 * by an earlier run — keeps it exactly as it is; nothing is overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('payment_gateways')->where('code', 'bsicards')->exists()) {
            DB::table('payment_gateways')->insert([
                'logo'        => 'general/static/gateway/bsicards.svg',
                'name'        => 'BSICARDS',
                'code'        => 'bsicards',
                'currencies'  => json_encode(['USD']),
                'credentials' => json_encode([
                    'public_key' => 'public_key',
                    'secret_key' => '',
                ]),
                'withdraw_field' => null,
                'ipn'            => true,
                'status'         => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        if (! DB::table('virtual_card_providers')->where('code', 'bsicards')->exists()) {
            DB::table('virtual_card_providers')->insert([
                'payment_gateway_id'   => DB::table('payment_gateways')->where('code', 'bsicards')->value('id'),
                'name'                 => 'BSICARDS',
                'code'                 => 'bsicards',
                'logo'                 => 'general/static/gateway/bsicards.svg',
                'description'          => 'Visa and Mastercard virtual cards in USD, issued through BSI Group.',
                'supported_networks'   => json_encode(['visa', 'mastercard']),
                'supported_currencies' => json_encode(['USD']),
                // Covers what BSICARDS charges the operator per card: its
                // issuance fee plus the starter load it puts on every new card
                // (Visa $5, Mastercard $3) and that load's fee. A starting
                // point only — the provider stays off until it is reviewed.
                'issue_fee'            => 9.00,
                'min_balance'          => 5.00,
                'status'               => false,
                'order'                => 4,
                'capabilities'         => json_encode([
                    'issue'        => true,
                    'card_details' => true,
                    'topup'        => true,
                    'withdraw'     => false,
                    'freeze'       => true,
                    'limits'       => false,
                    'controls'     => false,
                    'activity'     => true,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->forgetCaches();
    }

    /**
     * Rows are left in place: once an operator has entered keys and issued
     * cards, removing the provider would orphan those cards.
     */
    public function down(): void
    {
        $this->forgetCaches();
    }

    private function forgetCaches(): void
    {
        Cache::forget('payment_gateways_all');
        Cache::forget('payment_gateway_code_bsicards');
        Cache::forget('virtual_card_providers_all');
        Cache::forget('virtual_card_provider_code_bsicards');
    }
};
