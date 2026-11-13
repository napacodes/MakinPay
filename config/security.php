<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | This file holds security-related configurations for the application.
    | */
    'duplicate_submission_timeout'       => env('DUPLICATE_SUBMISSION_TIMEOUT', 10),
    'secure_response_headers'            => env('SECURE_RESPONSE_HEADERS', true),
    'strict_transport_security'          => env('STRICT_TRANSPORT_SECURITY', true),
    'login_attempt_limit'                => env('LOGIN_ATTEMPT_LIMIT', 5),
    'login_lock_minutes'                 => env('LOGIN_LOCK_MINUTES', 15),
    'wallet_pin_attempt_limit'           => env('WALLET_PIN_ATTEMPT_LIMIT', 5),
    'wallet_pin_lock_minutes'            => env('WALLET_PIN_LOCK_MINUTES', 15),
    'merchant_api_signature_required'    => env('MERCHANT_API_SIGNATURE_REQUIRED', true),
    'merchant_api_timestamp_tolerance'   => env('MERCHANT_API_TIMESTAMP_TOLERANCE', 300),
    'merchant_api_rate_limit_per_minute' => env('MERCHANT_API_RATE_LIMIT_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Automatic IP blocking
    |--------------------------------------------------------------------------
    |
    | `App\Services\Security\IpAbuseGuard` counts refused requests per address
    | and writes the deny list `App\Http\Middleware\BlockIp` enforces. Weights
    | are per refusal; an address reaching the threshold inside the window is
    | blocked for `ip_auto_block_minutes`, doubling on each repeat up to
    | `ip_auto_block_max_minutes`.
    |
    | The block is on the ADDRESS, never on the account: a shared demo login
    | abused from one address keeps working for everybody else.
    |
    */
    'ip_auto_block_enabled'        => env('IP_AUTO_BLOCK_ENABLED', true),
    'ip_auto_block_threshold'      => env('IP_AUTO_BLOCK_THRESHOLD', 12),
    'ip_auto_block_window_minutes' => env('IP_AUTO_BLOCK_WINDOW_MINUTES', 10),
    'ip_auto_block_minutes'        => env('IP_AUTO_BLOCK_MINUTES', 60),
    'ip_auto_block_max_minutes'    => env('IP_AUTO_BLOCK_MAX_MINUTES', 1440),

    /*
     * Addresses never blocked automatically, comma separated. The office and
     * the monitoring probe belong here. A shared exit address (a corporate
     * NAT, a mobile carrier) is better covered by `ip_auto_block_shared_accounts`
     * below, which recognises it without being told.
     */
    'ip_auto_block_allowlist' => env('IP_AUTO_BLOCK_ALLOWLIST', ''),

    /*
     * How many DIFFERENT accounts may have signed in successfully from one
     * address in the last day before the guard treats it as shared and
     * refuses to block it. Without this, a single abuser behind a carrier NAT
     * or a reverse proxy takes every customer sharing that address down too.
     */
    'ip_auto_block_shared_accounts' => env('IP_AUTO_BLOCK_SHARED_ACCOUNTS', 3),
];
