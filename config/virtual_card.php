<?php

use App\Services\VirtualCard\Drivers\Bitnob\BitnobCardProvider;
use App\Services\VirtualCard\Drivers\BsiCards\BsiCardsProvider;
use App\Services\VirtualCard\Drivers\Stripe\StripeCardProvider;
use App\Services\VirtualCard\Drivers\StroWallet\StroWalletProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Provider Implementations
    |--------------------------------------------------------------------------
    | Map provider `code` (column on virtual_card_providers) to the class that
    | implements VirtualCardProviderInterface. Adding a new gateway is a single
    | line here — no factory edits, no UI edits.
    */
    'providers' => [
        'stripe'     => StripeCardProvider::class,
        'strowallet' => StroWalletProvider::class,
        'bitnob'     => BitnobCardProvider::class,
        'bsicards'   => BsiCardsProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Card Currency
    |--------------------------------------------------------------------------
    | The currency a provider keeps its cards in. When a wallet in another
    | currency funds such a card, VirtualCardManager converts at the platform's
    | stored rates and records the card-side figure and the rate on the row.
    | A provider row's `config.card_currency` overrides this; a provider with
    | no entry funds cards in the wallet's own currency, as before.
    */
    'card_currencies' => [
        'strowallet' => 'USD',
        'bitnob'     => 'USD',
        'bsicards'   => 'USD',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Capabilities
    |--------------------------------------------------------------------------
    | Used when a provider row is missing the `capabilities` JSON. Every
    | capability defaults to false except issuance, so the UI hides actions
    | that the provider has not opted in to.
    */
    'default_capabilities' => [
        'issue'        => true,
        'card_details' => true,
        'topup'        => false,
        'withdraw'     => false,
        'freeze'       => false,
        'limits'       => false,
        'controls'     => false,
        // Listing a card's purchases and declines.
        'activity' => false,
        // Asking for a new card in place of a blocked, lost or expired one:
        // a platform request the administrator approves, so any provider
        // that issues can do it.
        'replace' => true,
        // Apple Pay / Google Pay push-provisioning; no provider offers it yet.
        'push_provisioning' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-provider Capability Overrides
    |--------------------------------------------------------------------------
    | The DB `capabilities` column wins over this. This map seeds and acts as
    | the fallback when a provider row is missing capabilities data.
    */
    'capabilities' => [
        'stripe' => [
            'issue'        => true,
            'card_details' => true,
            // Stripe Issuing has no native "fund this card" endpoint —
            // Treasury (enterprise-only) is the first-class flow. We
            // model top-up / withdraw via the card's
            // `spending_controls.spending_limits` (all_time interval):
            // top-up raises the ceiling, withdraw lowers it. The wallet
            // ledger on our side handles the rest.
            'topup'    => true,
            'withdraw' => true,
            'freeze'   => true,
            'limits'   => true,
            'controls' => true,
        ],
        'strowallet' => [
            'issue'        => true,
            'card_details' => true,
            'topup'        => true,
            'withdraw'     => true,
            'freeze'       => true, // /bitvcard/nfc-cards/status, legacy /bitvcard/action/status as fallback
            'limits'       => false,
            'controls'     => false,
            'activity'     => true, // /bitvcard/card-transactions/
        ],
        'bitnob' => [
            'issue'        => true,
            'card_details' => true,
            'topup'        => true,
            'withdraw'     => true,
            'freeze'       => true,
            'limits'       => true,
            'controls'     => false, // Bitnob has no per-card "online/atm/intl/contactless" toggle endpoint
        ],
        'bsicards' => [
            'issue'        => true,
            'card_details' => true,
            'topup'        => true,
            'withdraw'     => false, // BSICARDS has no unload endpoint
            'freeze'       => true,  // block-card / unblock-card
            'limits'       => false,
            'controls'     => false,
            'activity'     => true,
        ],
    ],
];
