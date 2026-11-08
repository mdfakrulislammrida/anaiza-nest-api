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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Not read from .env: the address to come back to is worked out in code (IntegrationSetting::callbackUrl), because a missing or
        // mistyped GOOGLE_REDIRECT_URI is what made Google answer "Missing required parameter: redirect_uri". The key stays, empty, because
        // Socialite expects it to exist.
        'redirect' => null,
    ],

    // Meta's Graph API version for the Conversions API (Purchase and the admin's test event). Meta retires a version about two years after its release:
    // raise it here, in the .env, when Events Manager says the version is old.
    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION') ?: 'v25.0',
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => null, // worked out in code, as for Google
    ],

];
