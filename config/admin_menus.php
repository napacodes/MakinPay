<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sidebar Menu Items
    |--------------------------------------------------------------------------
    |
    | Here you can change the sidebar menu items
    |
    */

    'sidebar_icons' => [
        'default'         => 'sidebar-subitem',
        'menu_size'       => 22,
        'submenu_size'    => 19,
        'quick_link_size' => 19,
        'map'             => [
            'app'                 => 'sidebar-app',
            'apps'                => 'sidebar-apps',
            'apps-1'              => 'sidebar-system',
            'auto-payment'        => 'sidebar-auto-payment',
            'badge-account'       => 'sidebar-identity',
            'blog'                => 'sidebar-blog',
            'cache'               => 'sidebar-cache',
            'card'                => 'sidebar-card',
            'cardHolder'          => 'sidebar-cardholder',
            'chart-up'            => 'sidebar-growth',
            'chat-1'              => 'sidebar-ticket-open',
            'chat-2'              => 'sidebar-ticket-progress',
            'chat-3'              => 'sidebar-ticket-closed',
            'chat-4'              => 'sidebar-ticket-history',
            'cil-fork'            => 'sidebar-plugin',
            'cil-server'          => 'sidebar-server',
            'cil-settings'        => 'sidebar-settings',
            'cil-speedometer'     => 'sidebar-dashboard',
            'currency-exchange'   => 'sidebar-exchange',
            'custom-landing'      => 'sidebar-landing',
            'deposit'             => 'sidebar-deposit',
            'earn'                => 'sidebar-earn',
            'deposit-1'           => 'sidebar-history',
            'email'               => 'sidebar-email',
            'fee'                 => 'sidebar-fee',
            'feature-management'  => 'sidebar-feature',
            'footer'              => 'sidebar-footer',
            'globe'               => 'sidebar-globe',
            'history'             => 'sidebar-history',
            'kyc'                 => 'sidebar-kyc',
            'layer'               => 'sidebar-subscription',
            'list-2'              => 'sidebar-navigation',
            'manual-payment'      => 'sidebar-manual-payment',
            'merchant'            => 'sidebar-merchant',
            'mobile-recharge'     => 'mobile-recharge',
            'agent'               => 'sidebar-agent',
            'message'             => 'sidebar-message',
            'media'               => 'sidebar-palette',
            'money-cog'           => 'sidebar-currency',
            'notification'        => 'sidebar-notification',
            'observability'       => 'sidebar-observe',
            'optimize'            => 'sidebar-rocket',
            'p2p_trading'         => 'sidebar-p2p',
            'link'                => 'sidebar-link',
            'page'                => 'sidebar-page',
            'palette'             => 'sidebar-palette',
            'payment'             => 'sidebar-payment',
            'vendor'              => 'sidebar-vendor',
            'people'              => 'sidebar-people',
            'provider'            => 'sidebar-provider',
            'quick-activity'      => 'history',
            'quick-settings'      => 'sidebar-settings',
            'quick-support'       => 'support-open',
            'quick-site-optimize' => 'sidebar-rocket',
            'project-updater'     => 'sidebar-system',
            'ranking'             => 'sidebar-ranking',
            'receipt'             => 'sidebar-receipt',
            'referral'            => 'sidebar-referral',
            'request-1'           => 'sidebar-request',
            'request-list'        => 'sidebar-list',
            'role'                => 'sidebar-role',
            'schedule'            => 'sidebar-schedule',
            'send-mail'           => 'sidebar-send-mail',
            'seo'                 => 'sidebar-seo',
            'shop'                => 'sidebar-store',
            'shop-1'              => 'sidebar-store',
            'site-setting'        => 'sidebar-site-setting',
            'social-link'         => 'sidebar-social',
            'storefront'          => 'sidebar-storefront',
            'support'             => 'sidebar-support',
            'tags'                => 'sidebar-tags',
            'template'            => 'sidebar-template',
            'transaction-2'       => 'sidebar-transactions',
            'translate'           => 'sidebar-language',
            'trending-up'         => 'sidebar-growth',
            'users-1'             => 'sidebar-users',
            'virtual-card'        => 'sidebar-card',
            'wallet'              => 'sidebar-wallet',
            'wallet-plus'         => 'sidebar-wallet-plus',
            'withdraw-1'          => 'sidebar-withdraw',
            'withdraw-2'          => 'sidebar-history',
        ],
    ],

    [
        'order' => 0,
        'menus' => [
            [
                'label' => 'Dashboard',
                'icon'  => 'cil-speedometer',
                'type'  => 'single',
                'route' => 'admin.dashboard',
            ],
            [
                'label' => 'Transactions',
                'icon'  => 'receipt',
                'type'  => 'single',
                'route' => 'admin.transaction',
                // The detail, receipt and export screens are this row's pages.
                'active_routes' => ['admin.transaction', 'admin.transaction.*'],
                'permission'    => 'transaction-list',
            ],
        ],
    ],
    [
        'label' => 'Customers',
        'order' => 10,
        'menus' => [
            [
                'label'     => 'User accounts',
                'icon'      => 'users-1',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'         => 'All users',
                        'route'         => 'admin.user.index',
                        'active_routes' => ['admin.user.index', 'admin.user.login', 'admin.user.manage', 'admin.user.mail-send.*', 'admin.user.transaction-stats'],
                        'permission'    => 'user-list',
                    ],
                    [
                        'label'      => 'KYC pending users',
                        'route'      => 'admin.user.kyc-unverified',
                        'permission' => 'user-list',
                        'badge'      => 'users_kyc_pending',
                    ],
                    [
                        'label'      => 'Unverified users',
                        'route'      => 'admin.user.unverified',
                        'permission' => 'user-list',
                    ],
                    [
                        'label'      => 'Suspended users',
                        'route'      => 'admin.user.suspended',
                        'permission' => 'user-list',
                        'badge'      => 'users_suspended',
                    ],
                    [
                        'label'      => 'Active users',
                        'route'      => 'admin.user.active',
                        'permission' => 'user-list',
                    ],
                ],
            ],
            [
                'label'     => 'KYC verification',
                'icon'      => 'kyc',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label' => 'Pending reviews',
                        'route' => 'admin.kyc.pending',
                        /* The review workspace is a page of this queue: it is
                           opened from a row here and its prev/next walk the
                           same filter, so the row stays lit while it is open. */
                        'active_routes' => ['admin.kyc.pending', 'admin.kyc.review'],
                        'permission'    => 'kyc-list',
                        'badge'         => 'kyc_pending',
                    ],
                    [
                        'label'         => 'Verification records',
                        'route'         => 'admin.kyc.index',
                        'active_routes' => ['admin.kyc.index', 'admin.kyc.export'],
                        'permission'    => 'kyc-list',
                    ],
                    [
                        'label'         => 'KYC templates',
                        'route'         => 'admin.kyc.template.index',
                        'active_routes' => ['admin.kyc.template.*'],
                        'permission'    => 'kyc-template-list',
                    ],
                    [
                        'label'      => 'Verification providers',
                        'route'      => 'admin.kyc.providers.index',
                        'permission' => 'kyc-provider-list',
                    ],
                ],
            ],
            [
                'label'     => 'Business profiles',
                'code'      => 'merchant-management',
                'icon'      => 'sidebar-storefront',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'      => 'Pending merchants',
                        'icon'       => 'shop',
                        'route'      => 'admin.merchant.pending',
                        'permission' => 'merchant-list',
                        'badge'      => 'merchants_pending',
                    ],
                    [
                        'label'         => 'Merchants',
                        'icon'          => 'shop-1',
                        'route'         => 'admin.merchant.index',
                        'active_routes' => ['admin.merchant.index', 'admin.merchant.approved', 'admin.merchant.rejected', 'admin.merchant.create', 'admin.merchant.edit', 'admin.merchant.show', 'admin.merchant.review'],
                        'permission'    => 'merchant-list',
                    ],
                    [
                        'label'      => 'Pending agents',
                        'icon'       => 'sidebar-agent',
                        'route'      => 'admin.agent.pending',
                        'permission' => 'agent-list',
                        'badge'      => 'agents_pending',
                    ],
                    [
                        'label'         => 'Agents',
                        'icon'          => 'sidebar-agent',
                        'route'         => 'admin.agent.index',
                        'active_routes' => ['admin.agent.index', 'admin.agent.approved', 'admin.agent.rejected'],
                        'permission'    => 'agent-list',
                    ],
                    [
                        'label'      => 'Commission rules',
                        'icon'       => 'fee',
                        'route'      => 'admin.agent.commission-rules.index',
                        'permission' => 'agent-commission-rules-manage',
                    ],
                ],
            ],
            [
                'label'         => 'Support tickets',
                'code'          => 'support-ticket',
                'active_routes' => ['admin.support-ticket.show'],
                'icon'          => 'message',
                'type'          => 'groups',
                'sub_menus'     => [
                    [
                        'label'      => 'New tickets',
                        'icon'       => 'chat-1',
                        'route'      => 'admin.support-ticket.new',
                        'permission' => 'support-ticket-list',
                        'badge'      => 'tickets_new',
                    ],
                    [
                        'label'      => 'In progress',
                        'icon'       => 'chat-2',
                        'route'      => 'admin.support-ticket.inprogress',
                        'permission' => 'support-ticket-list',
                        'badge'      => 'tickets_in_progress',
                    ],
                    [
                        'label'      => 'Closed tickets',
                        'icon'       => 'chat-3',
                        'route'      => 'admin.support-ticket.close',
                        'permission' => 'support-ticket-list',
                    ],
                    [
                        'label'      => 'Ticket history',
                        'icon'       => 'chat-4',
                        'route'      => 'admin.support-ticket.history',
                        'permission' => 'support-ticket-list',
                    ],
                    [
                        'label'         => 'Ticket categories',
                        'icon'          => 'tags',
                        'route'         => 'admin.support-ticket.category.index',
                        'active_routes' => ['admin.support-ticket.category.*'],
                        'permission'    => 'support-ticket-category-manage',
                    ],
                ],
            ],
        ],
    ],
    [
        'label' => 'Money movement',
        'order' => 20,
        'menus' => [
            [
                'label'       => 'Deposits',
                'code'        => 'deposit-management',
                'icon'        => 'wallet-plus',
                'type'        => 'groups',
                'feature_key' => 'deposit_money',
                'sub_menus'   => [
                    [
                        'label'      => 'Manual requests',
                        'icon'       => 'deposit',
                        'route'      => 'admin.deposit.manual-request',
                        'permission' => 'deposit-list',
                        'badge'      => 'deposits_manual',
                    ],
                    [
                        'label'      => 'Deposit history',
                        'icon'       => 'deposit-1',
                        'route'      => 'admin.deposit.history',
                        'permission' => 'deposit-list',
                    ],
                    [
                        'label'         => 'Automatic methods',
                        'icon'          => 'auto-payment',
                        'route'         => 'admin.deposit.method.index',
                        'active_routes' => ['admin.deposit.method.*'],
                        'params'        => [
                            'type' => 'auto',
                        ],
                        'permission' => 'deposit-method-list',
                    ],
                    [
                        'label'         => 'Manual methods',
                        'icon'          => 'manual-payment',
                        'route'         => 'admin.deposit.method.index',
                        'active_routes' => ['admin.deposit.method.*'],
                        'params'        => [
                            'type' => 'manual',
                        ],
                        'permission' => 'deposit-method-list',
                    ],
                ],
            ],
            [
                'label'       => 'Withdrawals',
                'code'        => 'withdraw-management',
                'icon'        => 'withdraw-1',
                'type'        => 'groups',
                'feature_key' => 'withdraw_money',
                'sub_menus'   => [
                    [
                        'label'      => 'Manual requests',
                        'icon'       => 'withdraw-1',
                        'route'      => 'admin.withdraw.manual-request',
                        'permission' => 'withdraw-list',
                        'badge'      => 'withdrawals_manual',
                    ],
                    [
                        'label'      => 'Scheduled withdrawals',
                        'icon'       => 'schedule',
                        'route'      => 'admin.withdraw.schedule',
                        'permission' => 'withdraw-schedule',
                    ],
                    [
                        'label'      => 'Withdrawal history',
                        'icon'       => 'withdraw-2',
                        'route'      => 'admin.withdraw.history',
                        'permission' => 'withdraw-list',
                    ],
                    [
                        'label'         => 'Automatic methods',
                        'icon'          => 'auto-payment',
                        'route'         => 'admin.withdraw.method.index',
                        'active_routes' => ['admin.withdraw.method.*'],
                        'params'        => [
                            'type' => 'auto',
                        ],
                        'permission' => 'withdraw-method-list',
                    ],
                    [
                        'label'         => 'Manual methods',
                        'icon'          => 'manual-payment',
                        'route'         => 'admin.withdraw.method.index',
                        'active_routes' => ['admin.withdraw.method.*'],
                        'params'        => [
                            'type' => 'manual',
                        ],
                        'permission' => 'withdraw-method-list',
                    ],
                ],
            ],
            [
                'label'       => 'Remittance',
                'code'        => 'remittance-management',
                'icon'        => 'globe',
                'type'        => 'groups',
                'permission'  => 'remittance-corridor-list',
                'feature_key' => 'remittance',
                'sub_menus'   => [
                    [
                        'label'         => 'Transfers',
                        'icon'          => 'send-mail',
                        'route'         => 'admin.remittance.transfers.index',
                        'active_routes' => ['admin.remittance.transfers.*'],
                        'permission'    => 'remittance-transfer-list',
                    ],
                    [
                        'label'         => 'Corridors',
                        'icon'          => 'money-cog',
                        'route'         => 'admin.remittance.corridors.index',
                        'active_routes' => ['admin.remittance.corridors.*'],
                        'permission'    => 'remittance-corridor-list',
                    ],
                ],
            ],
            [
                'label'       => 'P2P marketplace',
                'code'        => 'p2p-management',
                'icon'        => 'p2p_trading',
                'type'        => 'groups',
                'permission'  => 'p2p-manage',
                'feature_key' => 'p2p_marketplace',
                'sub_menus'   => [
                    [
                        'label'         => 'Disputes',
                        'icon'          => 'support',
                        'route'         => 'admin.p2p.disputes.index',
                        'active_routes' => ['admin.p2p.disputes.*'],
                        'permission'    => 'p2p-dispute-manage',
                        'badge'         => 'p2p_disputes',
                    ],
                    [
                        'label'      => 'P2P dashboard',
                        'icon'       => 'transaction-2',
                        'route'      => 'admin.p2p.index',
                        'permission' => 'p2p-manage',
                    ],
                    [
                        'label'         => 'Traders',
                        'icon'          => 'badge-account',
                        'route'         => 'admin.p2p.advertisers.index',
                        'active_routes' => ['admin.p2p.advertisers.*'],
                        'permission'    => 'p2p-manage',
                    ],
                    [
                        'label'         => 'Promotions',
                        'icon'          => 'chart-up',
                        'route'         => 'admin.p2p.promotions.index',
                        'active_routes' => ['admin.p2p.promotions.*', 'admin.p2p.promotion-purchases.*'],
                        'permission'    => 'p2p-manage',
                    ],
                    [
                        'label'         => 'Promotion plans',
                        'icon'          => 'apps',
                        'route'         => 'admin.p2p.promotion-packages.index',
                        'active_routes' => ['admin.p2p.promotion-packages.*'],
                        'permission'    => 'p2p-manage',
                    ],
                    [
                        'label'         => 'Payment accounts',
                        'icon'          => 'credit-card',
                        'route'         => 'admin.p2p.payment-accounts.index',
                        'active_routes' => ['admin.p2p.payment-accounts.*'],
                        'permission'    => 'p2p-manage',
                    ],
                    [
                        'label'         => 'Payment methods',
                        'icon'          => 'payment',
                        'route'         => 'admin.p2p.methods.index',
                        'active_routes' => ['admin.p2p.methods.*'],
                        'permission'    => 'p2p-method-manage',
                    ],
                    [
                        'label'         => 'Settings',
                        'icon'          => 'cil-settings',
                        'route'         => 'admin.p2p.settings.edit',
                        'active_routes' => ['admin.p2p.settings.*', 'admin.p2p.asset-fees.*'],
                        'permission'    => 'p2p-manage',
                    ],
                ],
            ],
            [
                'label'         => 'Payment links',
                'code'          => 'payment-link-management',
                'icon'          => 'link',
                'type'          => 'single',
                'route'         => 'admin.payment-links.index',
                'active_routes' => ['admin.payment-links.*'],
                'permission'    => 'payment-link-list',
                'feature_key'   => 'payment_link',
            ],
            [
                'label'         => 'Mobile recharge',
                'code'          => 'mobile-recharge-management',
                'icon'          => 'mobile-recharge',
                'type'          => 'single',
                'route'         => 'admin.mobile-recharge.index',
                'active_routes' => ['admin.mobile-recharge.*'],
                'permission'    => 'mobile-recharge-list',
                'feature_key'   => 'mobile_recharge',
            ],
            /*
             * No feature_key, on purpose: billers are configured before the
             * module is switched on, and switching it off must never hide
             * pending customer money from the only screens that can refund
             * it. Each sub-item claims its own routes; a group-wide
             * admin.bill-payment.* would overlap them.
             */
            [
                'label'      => 'Bill payments',
                'code'       => 'bill-payment-management',
                'icon'       => 'receipt',
                'type'       => 'groups',
                'permission' => 'bill-payment-list',
                'sub_menus'  => [
                    [
                        'label'         => 'Queue',
                        'icon'          => 'request-1',
                        'route'         => 'admin.bill-payment.queue',
                        'active_routes' => ['admin.bill-payment.queue'],
                        'permission'    => 'bill-payment-list',
                        'badge'         => 'bill_payments_awaiting',
                    ],
                    [
                        'label'         => 'All payments',
                        'icon'          => 'receipt',
                        'route'         => 'admin.bill-payment.index',
                        'active_routes' => ['admin.bill-payment.index', 'admin.bill-payment.show'],
                        'permission'    => 'bill-payment-list',
                    ],
                    [
                        'label'         => 'Billers',
                        'icon'          => 'list-2',
                        'route'         => 'admin.bill-payment.billers.index',
                        'active_routes' => ['admin.bill-payment.billers.*'],
                        'permission'    => 'bill-payment-list',
                    ],
                    [
                        'label'         => 'Categories',
                        'icon'          => 'tags',
                        'route'         => 'admin.bill-payment.categories.index',
                        'active_routes' => ['admin.bill-payment.categories.*'],
                        'permission'    => 'bill-payment-list',
                    ],
                    [
                        'label'         => 'Providers',
                        'icon'          => 'provider',
                        'route'         => 'admin.bill-payment.providers.index',
                        'active_routes' => ['admin.bill-payment.providers.*'],
                        'permission'    => 'bill-payment-manage',
                    ],
                ],
            ],
            /*
             * No feature_key, on purpose: switching the module off must never
             * hide money reserved for a confirmed batch from the only screens
             * that can release it.
             */
            [
                'label'         => 'Mass payments',
                'code'          => 'mass-payment-management',
                'icon'          => 'people',
                'type'          => 'single',
                'route'         => 'admin.mass-payment.index',
                'active_routes' => ['admin.mass-payment.*'],
                'permission'    => 'mass-payment-list',
                'badge'         => 'mass_payments_awaiting',
            ],
        ],
    ],
    [
        'label' => 'Products',
        'order' => 30,
        'menus' => [
            [
                'label'      => 'Virtual cards',
                'code'       => 'virtual-card-management',
                'icon'       => 'virtual-card',
                'type'       => 'groups',
                'permission' => 'virtual-card-list',
                'sub_menus'  => [
                    [
                        'label'      => 'Awaiting requests',
                        'icon'       => 'request-1',
                        'route'      => 'admin.virtual-card.requests.awaiting',
                        'permission' => 'virtual-card-action',
                        'badge'      => 'cards_awaiting',
                    ],
                    [
                        'label'      => 'Cardholders',
                        'icon'       => 'cardHolder',
                        'route'      => 'admin.virtual-card.cardholders.index',
                        'permission' => 'virtual-card-action',
                        'badge'      => 'cardholders_pending',
                    ],
                    [
                        'label'      => 'All cards',
                        'icon'       => 'card',
                        'route'      => 'admin.virtual-card.list',
                        'permission' => 'virtual-card-list',
                    ],
                    [
                        'label'      => 'All requests',
                        'icon'       => 'request-list',
                        'route'      => 'admin.virtual-card.requests.all',
                        'permission' => 'virtual-card-list',
                    ],
                    [
                        'label'         => 'Fee settings',
                        'icon'          => 'fee',
                        'route'         => 'admin.virtual-card.fee-settings.index',
                        'active_routes' => ['admin.virtual-card.fee-settings.*'],
                        'permission'    => 'virtual-card-action',
                    ],
                    [
                        'label'         => 'Provider settings',
                        'icon'          => 'provider',
                        'route'         => 'admin.virtual-card.provider.index',
                        'active_routes' => ['admin.virtual-card.provider.*'],
                        'permission'    => 'virtual-card-provider-manage',
                    ],
                ],
            ],
            [
                'label'       => 'Wallet Earn',
                'code'        => 'wallet-earn-management',
                'icon'        => 'earn',
                'type'        => 'groups',
                'permission'  => 'wallet-earn-list',
                'feature_key' => 'wallet_earn',
                'sub_menus'   => [
                    [
                        'label'         => 'Earn dashboard',
                        'icon'          => 'wallet',
                        'route'         => 'admin.wallet-earn.index',
                        'active_routes' => ['admin.wallet-earn.index', 'admin.wallet-earn.stakes.*'],
                        'permission'    => 'wallet-earn-list',
                    ],
                    [
                        'label'         => 'Plans',
                        'icon'          => 'apps',
                        'route'         => 'admin.wallet-earn.plans.index',
                        'active_routes' => ['admin.wallet-earn.plans.*'],
                        'permission'    => 'wallet-earn-list',
                    ],
                ],
            ],
            [
                'label'      => 'Subscriptions',
                'code'       => 'subscription-management',
                'icon'       => 'layer',
                'type'       => 'groups',
                'permission' => 'subscription-list',
                'sub_menus'  => [
                    [
                        'label'         => 'User plans',
                        'icon'          => 'people',
                        'route'         => 'admin.subscription.user-subscriptions.index',
                        'active_routes' => ['admin.subscription.user-subscriptions.*'],
                        'permission'    => 'subscription-list',
                    ],
                    [
                        'label'      => 'Transactions',
                        'icon'       => 'transaction-2',
                        'route'      => 'admin.subscription.transactions',
                        'permission' => 'subscription-list',
                    ],
                    [
                        'label'         => 'Plans',
                        'icon'          => 'apps',
                        'route'         => 'admin.subscription.plans.index',
                        'active_routes' => ['admin.subscription.plans.*'],
                        'permission'    => 'subscription-list',
                    ],
                ],
            ],
            [
                'label'       => 'Gift cards',
                'code'        => 'gift-card-management',
                'icon'        => 'tags',
                'type'        => 'groups',
                'permission'  => 'gift-card-list',
                'feature_key' => 'gift_cards',
                'sub_menus'   => [
                    [
                        'label'      => 'All gift cards',
                        'icon'       => 'request-list',
                        'route'      => 'admin.gift-cards.index',
                        'permission' => 'gift-card-list',
                    ],
                    [
                        'label'      => 'Templates',
                        'icon'       => 'palette',
                        'route'      => 'admin.gift-card-templates.index',
                        'permission' => 'gift-card-template-list',
                    ],
                ],
            ],
            [
                'label'       => 'Vendor marketplace',
                'code'        => 'marketplace-management',
                'icon'        => 'vendor',
                'type'        => 'groups',
                'permission'  => 'marketplace-manage',
                'feature_key' => 'vendor_marketplace',
                'sub_menus'   => [
                    [
                        'label'      => 'Orders',
                        'icon'       => 'transaction-2',
                        'route'      => 'admin.marketplace.orders.index',
                        'permission' => 'marketplace-order-list',
                    ],
                    [
                        'label'      => 'Vendors',
                        'icon'       => 'badge-account',
                        'route'      => 'admin.marketplace.vendors.index',
                        'permission' => 'marketplace-vendor-list',
                    ],
                    [
                        'label'         => 'Settings',
                        'icon'          => 'cil-settings',
                        'route'         => 'admin.marketplace.settings.edit',
                        'active_routes' => ['admin.marketplace.settings.*'],
                        'permission'    => 'marketplace-manage',
                    ],
                ],
            ],
            [
                'label'         => 'Referral program',
                'icon'          => 'referral',
                'type'          => 'single',
                'route'         => 'admin.referral.index',
                'active_routes' => ['admin.referral.*'],
                'permission'    => 'referral-manage',
            ],
        ],
    ],
    [
        'label' => 'Payments setup',
        'order' => 40,
        'menus' => [
            [
                'label'         => 'Payment gateways',
                'icon'          => 'payment',
                'type'          => 'single',
                'route'         => 'admin.payment.gateway.index',
                'active_routes' => ['admin.payment.gateway.*', 'admin.payment.gateway-currency'],
                'permission'    => 'payment-gateway-list',
                'badge'         => 'gateways_unconfigured',
            ],
            [
                'label'         => 'Currency management',
                'icon'          => 'money-cog',
                'type'          => 'single',
                'route'         => 'admin.currency.index',
                'active_routes' => ['admin.currency.*'],
                'permission'    => 'currency-manage',
            ],
        ],
    ],
    [
        'label' => 'Communication',
        'order' => 50,
        'menus' => [
            [
                'label'     => 'Notifications',
                'icon'      => 'notification',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'      => 'Send notification',
                        'icon'       => 'send-mail',
                        'route'      => 'admin.notifications.notifyToUser',
                        'permission' => 'custom-notify-users',
                    ],
                    [
                        'label'      => 'Notification logs',
                        'icon'       => 'notification',
                        'route'      => 'admin.notifications.index',
                        'permission' => 'notification-list',
                    ],
                    [
                        'label'         => 'Notification templates',
                        'icon'          => 'template',
                        'route'         => 'admin.notifications.template.index',
                        'active_routes' => ['admin.notifications.template.*'],
                        'permission'    => 'notification-template-list',
                    ],
                ],
            ],
            [
                'label'      => 'Email subscribers',
                'icon'       => 'email',
                'type'       => 'single',
                'route'      => 'admin.subscriber.index',
                'permission' => 'subscriber-list',
            ],
        ],
    ],
    [
        'label' => 'Website',
        'order' => 60,
        'menus' => [
            [
                'label'     => 'Site builder',
                'icon'      => 'page',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'      => 'Theme manager',
                        'icon'       => 'quick-style',
                        'route'      => 'admin.theme-manager.index',
                        'permission' => 'theme-manager-view',
                    ],
                    [
                        'label'         => 'Landing page',
                        'icon'          => 'custom-landing',
                        'route'         => 'admin.custom-landing.index',
                        'active_routes' => ['admin.custom-landing.*'],
                        'permission'    => 'custom-landing-list',
                    ],
                    [
                        'label'         => 'All pages',
                        'icon'          => 'page',
                        'route'         => 'admin.page.site.index',
                        'active_routes' => ['admin.page.site.*'],
                        'permission'    => 'page-list',
                    ],
                    [
                        'label'         => 'Page components',
                        'icon'          => 'template',
                        'route'         => 'admin.page.component.index',
                        'active_routes' => ['admin.page.component.*', 'admin.page.component-repeated-content.*'],
                        'permission'    => 'component-list',
                    ],
                    [
                        'label'         => 'Site navigation',
                        'icon'          => 'list-2',
                        'route'         => 'admin.navigation.site.index',
                        'active_routes' => ['admin.navigation.site.*'],
                        'permission'    => 'navigation-manage',
                    ],
                    [
                        'label'         => 'Footer sections',
                        'icon'          => 'footer',
                        'route'         => 'admin.page.footer.section.index',
                        'active_routes' => ['admin.page.footer.*'],
                        'permission'    => 'page-footer-manage',
                    ],
                    [
                        'label'      => 'Style manager',
                        'icon'       => 'quick-style',
                        'route'      => 'admin.app.style-manager',
                        'permission' => 'style-manager',
                    ],
                    [
                        'label'      => 'Media library',
                        'icon'       => 'media',
                        'route'      => 'admin.media.index',
                        'permission' => 'media-list',
                    ],
                ],
            ],
            [
                'label'     => 'SEO & social',
                'icon'      => 'seo',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'         => 'SEO settings',
                        'icon'          => 'seo',
                        'route'         => 'admin.site-seo.index',
                        'active_routes' => ['admin.site-seo.*'],
                        'permission'    => 'seo-manage',
                    ],
                    [
                        'label'         => 'Social links',
                        'icon'          => 'social-link',
                        'route'         => 'admin.social.index',
                        'active_routes' => ['admin.social.*'],
                        'permission'    => 'social-list',
                    ],
                ],
            ],
            [
                'label'     => 'Blog',
                'icon'      => 'blog',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'         => 'Blog posts',
                        'icon'          => 'blog',
                        'route'         => 'admin.blog.post.index',
                        'active_routes' => ['admin.blog.post.*'],
                        'permission'    => 'blog-list',
                    ],
                    [
                        'label'         => 'Blog categories',
                        'icon'          => 'tags',
                        'route'         => 'admin.blog.category.index',
                        'active_routes' => ['admin.blog.category.*'],
                        'permission'    => 'blog-category-list',
                    ],
                ],
            ],
        ],
    ],
    [
        'label' => 'Platform',
        'order' => 70,
        'menus' => [
            [
                'label'     => 'Settings',
                'code'      => 'settings-management',
                'icon'      => 'cil-settings',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'      => 'Site settings',
                        'icon'       => 'site-setting',
                        'route'      => 'admin.settings.site.index',
                        'permission' => 'site-setting-view',
                    ],
                    [
                        'label'                 => 'Feature controls',
                        'icon'                  => 'feature-management',
                        'route'                 => 'admin.features.index',
                        'active_routes'         => ['admin.features.*'],
                        'permission'            => 'feature-list',
                        'show_in_settings_tabs' => false,
                    ],
                    [
                        'label'                 => 'Taxes & levies',
                        'icon'                  => 'receipt',
                        'route'                 => 'admin.tax-rule.index',
                        'active_routes'         => ['admin.tax-rule.*'],
                        'permission'            => 'tax-rule-list',
                        'show_in_settings_tabs' => false,
                    ],
                    [
                        'label'                 => 'System information',
                        'icon'                  => 'cil-server',
                        'route'                 => 'admin.app.info',
                        'permission'            => 'app-info',
                        'show_in_settings_tabs' => false,
                    ],
                    [
                        'label'         => 'Integration center',
                        'icon'          => 'cil-fork',
                        'route'         => 'admin.settings.plugin.index',
                        'active_routes' => ['admin.settings.plugin.*', 'admin.settings.plugin_type'],
                        'permission'    => 'plugins-manage',
                    ],
                    [
                        'label'                 => 'Languages',
                        'icon'                  => 'translate',
                        'route'                 => 'admin.language.index',
                        'active_routes'         => ['admin.language.*'],
                        'permission'            => 'language-list',
                        'show_in_settings_tabs' => false,
                    ],
                    [
                        'label'                 => 'User rankings',
                        'icon'                  => 'ranking',
                        'route'                 => 'admin.ranking.index',
                        'active_routes'         => ['admin.ranking.*'],
                        'permission'            => 'ranking-manage',
                        'feature_key'           => 'user_ranks',
                        'show_in_settings_tabs' => false,
                    ],
                    [
                        'label'                 => 'Background jobs',
                        'icon'                  => 'apps-1',
                        'route'                 => 'admin.background-tasks.index',
                        'active_routes'         => ['admin.background-tasks.*', 'admin.queue.failed'],
                        'permission'            => 'background-task-list',
                        'show_in_settings_tabs' => false,
                    ],
                    [
                        'label'                 => 'Project updater',
                        'icon'                  => 'project-updater',
                        'route'                 => 'admin.app.updater.index',
                        'active_routes'         => ['admin.app.updater.*'],
                        'permission'            => 'project-updater-view',
                        'badge'                 => 'app_version',
                        'show_in_settings_tabs' => false,
                    ],
                ],
            ],
            [
                'label'         => 'Team members',
                'icon'          => 'badge-account',
                'type'          => 'single',
                'route'         => 'admin.staff.index',
                'active_routes' => ['admin.staff.*'],
                'permission'    => 'staff-list',
            ],
            [
                'label'         => 'Roles & permissions',
                'icon'          => 'role',
                'type'          => 'single',
                'route'         => 'admin.role.index',
                'active_routes' => ['admin.role.*'],
                'permission'    => 'role-list',
            ],
            [
                'label'     => 'Error tracking',
                'code'      => 'observability',
                'icon'      => 'observability',
                'type'      => 'groups',
                'sub_menus' => [
                    [
                        'label'         => 'Issues',
                        'route'         => 'admin.observability.issues',
                        'active_routes' => ['admin.observability.issues', 'admin.observability.issues.*', 'admin.observability.codes'],
                        'permission'    => 'observability-list',
                        'badge'         => 'errors_open',
                    ],
                    [
                        'label'      => 'Notification channels',
                        'route'      => 'admin.observability.channels',
                        'permission' => 'observability-manage',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Header menus
    |--------------------------------------------------------------------------
    |
    | The "New" button and the profile menu. AdminShell::actions() drops any
    | item whose route does not exist or whose permission the admin lacks, so a
    | module that was never installed leaves no dead entry behind.
    |
    | Create user and Invite staff open the index page that hosts their modal;
    | neither has a create route of its own.
    |
    */
    'header_actions' => [
        'create' => [
            ['label' => 'Create user', 'route' => 'admin.user.index', 'permission' => 'user-create', 'icon' => 'sidebar-users'],
            ['label'   => 'Manual deposit', 'route' => 'admin.deposit.manual-request', 'permission' => 'deposit-list', 'icon' => 'sidebar-deposit'],
            ['label'   => 'Add currency', 'route' => 'admin.currency.create', 'permission' => 'currency-manage', 'icon' => 'sidebar-currency'],
            ['label'   => 'Broadcast notification', 'route' => 'admin.notifications.notifyToUser', 'permission' => 'custom-notify-users', 'icon' => 'sidebar-send-mail'],
            ['divider' => true],
            ['label'   => 'Invite staff', 'route' => 'admin.staff.index', 'permission' => 'staff-create', 'icon' => 'sidebar-identity'],
        ],

        /*
         * Absorbs the old sidebar_footer strip. Activity log lived only there,
         * so it has to survive the strip being removed.
         */
        'profile' => [
            ['label' => 'My profile', 'route' => 'admin.profile.view', 'icon' => 'user-cog-1', 'note' => 'password & 2FA'],
            ['label'   => 'Activity log', 'route' => 'admin.activity-log', 'permission' => 'user-activity-log', 'icon' => 'history'],
            ['label'   => 'Control panel', 'route' => 'admin.app.control-panel', 'icon' => 'sidebar-system'],
            ['label'   => 'Visit landing page', 'route' => 'home', 'icon' => 'cil-laptop', 'external' => true],
            ['divider' => true],
            ['label'   => 'Clear cache', 'route' => 'admin.app.clear-cache', 'permission' => 'app-optimize', 'icon' => 'sidebar-cache', 'note' => 'maintenance'],
            ['label'   => 'Lock account', 'route' => 'admin.lock', 'icon' => 'cil-lock-locked'],
        ],
    ],

];
