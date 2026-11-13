<?php

/*
|--------------------------------------------------------------------------
| Feature Catalog
|--------------------------------------------------------------------------
|
| Central catalog of every manageable platform feature. This file drives
| the FeatureSeeder and acts as the source of truth when syncing to the
| `features` and `feature_access_rules` tables. Add new features here –
| no further code changes are required for the admin UI to pick them up.
|
| Panels: 'user', 'merchant', 'agent'
| (The 'admin' panel is always considered accessible for admins so that
| the feature itself can be managed from the admin side.)
|
| "is_core" features (like deposit/withdraw) can be toggled but the UI
| warns the admin first, since disabling them typically breaks business
| flows that depend on them.
|
| "manage_mode" may be set to "role_toggle" for features that only
| enable or disable a user role surface. Those features are shown in a
| protected role-controls section and do not expose panel rule editing.
|
| The "rules" key defines default business constraints. It is merged
| into feature_access_rules.conditions on seed and can be changed from
| the admin UI at runtime (requires_kyc, requires_phone, countries_allowed).
|
*/

return [

    'categories' => [
        'money_movement' => [
            'label'       => 'Money movement',
            'description' => 'Deposits, withdrawals, transfers and exchange.',
            'icon'        => 'wallet',
            'order'       => 10,
        ],
        'p2p' => [
            'label'       => 'P2P marketplace',
            'description' => 'Peer-to-peer ads, trading rooms and disputes.',
            'icon'        => 'p2p_trading',
            'order'       => 20,
        ],
        'business' => [
            'label'       => 'Business & merchant',
            'description' => 'Payment links, merchant payments, agents and subscriptions.',
            'icon'        => 'merchant',
            'order'       => 30,
        ],
        'cards' => [
            'label'       => 'Virtual cards',
            'description' => 'Card issuing and card transactions.',
            'icon'        => 'card',
            'order'       => 40,
        ],
        'engagement' => [
            'label'       => 'Engagement & growth',
            'description' => 'Referrals, ranks, vouchers and gift cards.',
            'icon'        => 'referral',
            'order'       => 50,
        ],
    ],

    'features' => [

        'deposit_money' => [
            'label'       => 'Deposit Money',
            'description' => 'Allow users to top up their wallet using any active payment gateway or manual deposit method.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'is_core'     => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        'withdraw_money' => [
            'label'       => 'Withdraw Money',
            'description' => 'Allow users to transfer wallet balance to linked bank, mobile money, or crypto payout methods.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'is_core'     => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => true,
            ],
        ],

        'send_money' => [
            'label'       => 'Send Money',
            'description' => 'Allow users to transfer wallet balance instantly to another platform user.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc'       => true,
                'identifier_methods' => [
                    'wallet_id' => true,
                    'email'     => true,
                    'mobile'    => false,
                ],
                'require_verified_email'  => false,
                'require_verified_mobile' => false,
            ],
        ],

        'request_money' => [
            'label'       => 'Request Money',
            'description' => 'Allow users to raise payment requests that another user can approve and pay from their wallet.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc'       => false,
                'identifier_methods' => [
                    'wallet_id' => true,
                    'email'     => true,
                    'mobile'    => false,
                ],
                'require_verified_email'  => false,
                'require_verified_mobile' => false,
            ],
        ],

        'exchange_money' => [
            'label'       => 'Exchange Money',
            'description' => 'Let users convert balance between supported wallet currencies using the exchange rate engine.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        'wallet_earn' => [
            'label'       => 'Wallet Earn',
            'description' => 'Allow users to stake wallet balances in supported currencies and earn scheduled rewards.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => true,
            ],
        ],

        'mobile_recharge' => [
            'label'       => 'Mobile Recharge',
            'description' => 'Allow users, merchants and agents to recharge mobile numbers from wallet balance through the configured provider.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc'   => false,
                'requires_phone' => true,
            ],
        ],

        /*
         * Ships switched off: billers are configured first, and the admin
         * turns the module on when they are ready. sync() writes is_enabled
         * only when it creates the row, so the admin's later choice survives.
         */
        'bill_payment' => [
            'label'       => 'Bill Payments',
            'description' => 'Allow users, merchants and agents to pay utility, TV, internet, education, insurance and other bills from wallet balance through admin-configured billers.',
            'category'    => 'money_movement',
            'is_enabled'  => false,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        /*
         * Ships switched off and merchant-only: the first catalog entry with
         * a panel set to false. sync() still writes a rule for the user and
         * agent panels, hidden and blocked, so the admin can open either one
         * from Feature controls. Mobile recipients are on because the mass
         * payment resolver refuses an ambiguous match outright.
         */
        'mass_payment' => [
            'label'       => 'Mass Payments',
            'description' => 'Let merchants pay many recipients at once from one wallet, by CSV upload or rows entered by hand, with a preview before any money moves.',
            'category'    => 'money_movement',
            'is_enabled'  => false,
            'panels'      => ['user' => false, 'merchant' => true, 'agent' => false],
            'rules'       => [
                'requires_kyc'       => true,
                'identifier_methods' => [
                    'wallet_id' => true,
                    'email'     => true,
                    'mobile'    => true,
                ],
                'require_verified_email'  => false,
                'require_verified_mobile' => false,
            ],
        ],

        /*
         * Mass payouts to external accounts through one withdraw method per
         * batch. Ships switched off and merchant-only, like mass_payment; the
         * routes also need withdraw_money, and the per-user withdraw and
         * mass_payout keys.
         */
        'mass_payout' => [
            'label'       => 'Mass Payouts',
            'description' => 'Let merchants pay many external bank or mobile money accounts at once through one withdraw method, with a preview and admin approval before any money is sent.',
            'category'    => 'money_movement',
            'is_enabled'  => false,
            'panels'      => ['user' => false, 'merchant' => true, 'agent' => false],
            'rules'       => [
                'requires_kyc' => true,
            ],
        ],

        'budgets' => [
            'label'       => 'Budgets',
            'description' => 'Let users set spending limits per category, measured against Insights, with alerts when a limit is near or crossed.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [],
        ],

        'vaults' => [
            'label'       => 'Vaults',
            'description' => 'Let users ring-fence part of a wallet into savings goals with auto-save rules and round-ups.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [],
        ],

        'scheduled_payments' => [
            'label'       => 'Scheduled Payments',
            'description' => 'Let users schedule sends, vault top-ups and recharges once or on a cadence under a standing mandate.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [],
        ],

        'bank_transfer' => [
            'label'       => 'Bank Transfer Payouts',
            'description' => 'Expose linked-bank withdraw methods to end users. Disabling hides bank payout options everywhere.',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => true,
            ],
        ],

        'remittance' => [
            'label'       => 'International Remittance',
            'description' => 'Send money abroad with locked FX rates, beneficiary management, and pluggable payout partners (Tap Tap Send style).',
            'category'    => 'money_movement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => true,
            ],
        ],

        'payment_link' => [
            'label'       => 'Payment Links',
            'description' => 'Allow users, merchants and agents to generate shareable payment links that anyone can pay from a wallet or supported gateway.',
            'category'    => 'business',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        'merchant_payment' => [
            'label'       => 'Merchant Payment',
            'description' => 'Allow customers to pay registered merchants from their wallet or connected payment methods.',
            'category'    => 'business',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => true,
            ],
        ],

        'vendor_marketplace' => [
            'label'       => 'Vendor Marketplace',
            'description' => 'In-platform vendor storefront with wallet split payments — vendors receive the net amount and the platform keeps a commission. Disabling hides every marketplace surface.',
            'category'    => 'business',
            'is_enabled'  => true,
            'is_core'     => false,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        'agent_program' => [
            'label'       => 'Agent Program',
            'description' => 'Enable agent registration, agent login, and the agent dashboard. When disabled, every agent surface is hidden across the platform.',
            'category'    => 'business',
            'manage_mode' => 'role_toggle',
            'is_enabled'  => true,
            'is_core'     => false,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        'subscription_system' => [
            'label'       => 'Subscription System',
            'description' => 'Enable subscription plans, checkout, active plan status, renewals, cancellations, and subscription history for users.',
            'category'    => 'business',
            'is_enabled'  => true,
            'is_core'     => false,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        'p2p_marketplace' => [
            'label'       => 'P2P Marketplace',
            'description' => 'Peer-to-peer ads, trading rooms, payment methods, and disputes. Disabling hides every P2P surface.',
            'category'    => 'p2p',
            'is_enabled'  => true,
            'is_core'     => false,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc'      => true,
                'countries_allowed' => [],
            ],
        ],

        'virtual_card' => [
            'label'       => 'Virtual Cards',
            'description' => 'Issue, manage, and transact on virtual cards backed by the configured card providers.',
            'category'    => 'cards',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => true,
            ],
        ],

        'referral_program' => [
            'label'       => 'Referral Program',
            'description' => 'Referral link, referral tree, and referral reward settings exposed to the end user.',
            'category'    => 'engagement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [],
        ],

        'user_ranks' => [
            'label'       => 'User Ranks',
            'description' => 'Manage rank progression, wallet limits, referral levels, and reward tiers from Feature Management.',
            'category'    => 'engagement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [],
        ],

        'vouchers' => [
            'label'       => 'Vouchers',
            'description' => 'Redeemable gift/top-up vouchers for wallet balance. Controls both creation and redemption surfaces.',
            'category'    => 'engagement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

        'gift_cards' => [
            'label'       => 'Gift Cards',
            'description' => 'Designed gift cards funded from a wallet, delivered by email with a private redeem link and a public preview page.',
            'category'    => 'engagement',
            'is_enabled'  => true,
            'panels'      => ['user' => true, 'merchant' => true, 'agent' => true],
            'rules'       => [
                'requires_kyc' => false,
            ],
        ],

    ],

];
