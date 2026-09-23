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

    'email_webhook' => [
        'provider' => env('MAIL_WEBHOOK_PROVIDER', 'resend'),

        'resend' => [
            'api_key' => env('RESEND_KEY'),
            'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
            'key' => env('RESEND_KEY'), // Compatibility with Resend SDK
        ],

        'mailgun' => [
            'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
        ],

        'sendgrid' => [
            'webhook_public_key' => env('SENDGRID_WEBHOOK_PUBLIC_KEY'),
        ],
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
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
        // Note: set GOOGLE_REDIRECT_URI to the full absolute URL in production
        // e.g. https://yourdomain.com/auth/google/callback
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI', '/auth/github/callback'),
        // Note: set GITHUB_REDIRECT_URI to the full absolute URL in production
        // e.g. https://yourdomain.com/auth/github/callback
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

];
