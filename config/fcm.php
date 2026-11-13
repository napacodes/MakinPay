<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | Addresses the Android app. Left empty the channel is simply not offered,
    | exactly as web push is when VAPID is unset - an installation that has no
    | mobile app must not have notifications fail on its account.
    |
    | The credentials are a Google service account. `credentials_path` points
    | at the JSON file Firebase issues; `credentials` takes the same JSON
    | inline for hosts where a file is awkward to place. Neither is ever
    | logged.
    |
    */

    'project_id' => env('FCM_PROJECT_ID'),

    'credentials_path' => env('FCM_CREDENTIALS_PATH'),

    'credentials' => env('FCM_CREDENTIALS_JSON'),

    /*
     * How long an access token is reused for. Google issues them for an hour;
     * this stops a minute short so a token is never presented in the second it
     * expires.
     */
    'token_ttl_seconds' => 3540,

    /*
     * The one place the send endpoint is written down.
     */
    'endpoint' => 'https://fcm.googleapis.com/v1/projects/:project/messages:send',

    'oauth_endpoint' => 'https://oauth2.googleapis.com/token',

    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',

    'timeout_seconds' => 10,
];
