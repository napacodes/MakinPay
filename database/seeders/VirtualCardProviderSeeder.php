<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use App\Models\VirtualCardProvider;
use Illuminate\Database\Seeder;

/**
 * Seed the virtual card providers and link each one to its payment_gateways
 * row by code. The link is what powers the "Gateway Settings" action on the
 * admin provider listing — without it, credentials live nowhere reachable.
 */
class VirtualCardProviderSeeder extends Seeder
{
    public function run(): void
    {
        $gatewayId = fn (string $code) => PaymentGateway::where('code', $code)->value('id');

        VirtualCardProvider::updateOrCreate(
            ['code' => 'stripe'],
            [
                'payment_gateway_id'   => $gatewayId('stripe'),
                'name'                 => 'Stripe Issuing',
                'logo'                 => 'general/static/gateway/stripe.png',
                'supported_networks'   => ['mastercard', 'visa'],
                'supported_currencies' => ['USD', 'EUR', 'GBP'],
                'issue_fee'            => 2.00,
                'min_balance'          => 10.00,
                'status'               => true,
                'order'                => 1,
                'capabilities'         => [
                    'issue'        => true,
                    'card_details' => true,
                    'topup'        => false,
                    'withdraw'     => false,
                    'freeze'       => true,
                    'limits'       => true,
                    'controls'     => true,
                ],
            ]
        );

        VirtualCardProvider::updateOrCreate(
            ['code' => 'strowallet'],
            [
                'payment_gateway_id'   => $gatewayId('strowallet'),
                'name'                 => 'StroWallet Provider',
                'logo'                 => 'general/static/gateway/strowallet.png',
                'supported_networks'   => ['mastercard', 'visa'],
                'supported_currencies' => ['USD', 'NGN'],
                'issue_fee'            => 1.50,
                'min_balance'          => 5.00,
                'status'               => true,
                'order'                => 2,
                'capabilities'         => [
                    'issue'        => true,
                    'card_details' => true,
                    'topup'        => true,
                    'withdraw'     => true,
                    'freeze'       => true,
                    'limits'       => false,
                    'controls'     => false,
                ],
            ]
        );

        VirtualCardProvider::updateOrCreate(
            ['code' => 'bitnob'],
            [
                'payment_gateway_id'   => $gatewayId('bitnob'),
                'name'                 => 'Bitnob',
                'logo'                 => 'general/static/gateway/bitnob.png',
                'supported_networks'   => ['visa'],
                'supported_currencies' => ['USD'],
                'issue_fee'            => 1.00,
                'min_balance'          => 2.00,
                'status'               => true,
                'order'                => 3,
                'capabilities'         => [
                    'issue'        => true,
                    'card_details' => true,
                    'topup'        => true,
                    'withdraw'     => true,
                    'freeze'       => true,
                    'limits'       => true,
                    'controls'     => false,
                ],
            ]
        );

        // Off until the admin enters the BSICARDS keys. BSICARDS has no
        // unload endpoint, so withdraw stays off.
        VirtualCardProvider::updateOrCreate(
            ['code' => 'bsicards'],
            [
                'payment_gateway_id'   => $gatewayId('bsicards'),
                'name'                 => 'BSICARDS',
                'logo'                 => 'general/static/gateway/bsicards.svg',
                'description'          => 'Visa and Mastercard virtual cards in USD, issued through BSI Group.',
                'supported_networks'   => ['visa', 'mastercard'],
                'supported_currencies' => ['USD'],
                // BSICARDS issuance fee + its own starter load (Visa $5,
                // Mastercard $3) and load fee, all charged to the operator.
                'issue_fee'            => 9.00,
                'min_balance'          => 5.00,
                'status'               => false,
                'order'                => 4,
                'capabilities'         => [
                    'issue'        => true,
                    'card_details' => true,
                    'topup'        => true,
                    'withdraw'     => false,
                    'freeze'       => true,
                    'limits'       => false,
                    'controls'     => false,
                    'activity'     => true,
                ],
            ]
        );
    }
}
