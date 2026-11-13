<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    /*
     * StroWallet's API host, for virtual cards and express deposits.
     *
     * Their reference documents the newer NFC endpoints against
     * ziiropay.com, but that host serves only part of the API and none of
     * the legacy paths, while strowallet.com serves everything this
     * integration calls. Move this only once ziiropay.com answers all; see
     * App\Services\Payment\StroWallet\StroWalletEndpoints for the probe that
     * established this. A single installation can override it with a
     * `base_url` key in the gateway's stored credentials instead.
     */
    'strowallet' => [
        'base_url' => env('STROWALLET_BASE_URL', 'https://strowallet.com'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'recaptcha' => [
        'key'    => env('RECAPTCHA_SITE_KEY'),
        'secret' => env('RECAPTCHA_SECRET_KEY'),
        'status' => env('RECAPTCHA_STATUS', false),
    ],

];
