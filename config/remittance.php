<?php

return [
    'fx' => [
        'endpoint'          => 'https://api.frankfurter.dev/v1/latest',
        'timeout_seconds'   => 5,
        'retry_attempts'    => 2,
        'retry_delay_ms'    => 250,
        'max_cache_minutes' => 60,
    ],

    'gateways' => [
        'manual'         => 'Manual (admin approval)',
        'bitnob'         => 'Bitnob (automated payout)',
        'stripe_connect' => 'Stripe Connect (bank payout)',
    ],

    'dispatch_states' => [
        'not_started'         => 'Not started',
        'scheduled'           => 'Scheduled',
        'attempting'          => 'Attempting',
        'ambiguous'           => 'Needs reconciliation',
        'confirmed'           => 'Provider confirmed',
        'definitively_failed' => 'Definitively failed',
    ],

    'reconciliation' => [
        'poll_after_minutes'   => (int) env('REMITTANCE_RECONCILE_AFTER_MINUTES', 5),
        'stuck_after_minutes'  => (int) env('REMITTANCE_STUCK_AFTER_MINUTES', 30),
        'alert_repeat_minutes' => (int) env('REMITTANCE_ALERT_REPEAT_MINUTES', 360),
    ],

    'compliance' => [
        'driver'                       => env('REMITTANCE_COMPLIANCE_DRIVER', 'local'),
        'require_remote_in_production' => true,
        'blocked_countries'            => ['KP', 'IR', 'SY'],
        'remote'                       => [
            'endpoint'                => env('REMITTANCE_COMPLIANCE_ENDPOINT'),
            'health_endpoint'         => env('REMITTANCE_COMPLIANCE_HEALTH_ENDPOINT'),
            'token'                   => env('REMITTANCE_COMPLIANCE_TOKEN'),
            'connect_timeout_seconds' => 3,
            'timeout_seconds'         => 8,
            'retry_attempts'          => 2,
            'retry_delay_ms'          => 250,
        ],
        'tiers' => [
            0 => ['per_transaction' => '1000.00', 'daily' => '1000.00', 'monthly' => '3000.00', 'daily_count' => 3, 'monthly_count' => 10],
            1 => ['per_transaction' => '5000.00', 'daily' => '10000.00', 'monthly' => '25000.00', 'daily_count' => 10, 'monthly_count' => 40],
            2 => ['per_transaction' => '10000.00', 'daily' => '25000.00', 'monthly' => '75000.00', 'daily_count' => 20, 'monthly_count' => 80],
            3 => ['per_transaction' => '25000.00', 'daily' => '50000.00', 'monthly' => '150000.00', 'daily_count' => 30, 'monthly_count' => 120],
        ],
    ],
];
