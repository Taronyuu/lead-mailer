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

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'mistral' => [
        'api_key' => env('MISTRAL_API_KEY'),
        'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
    ],

    'firecrawl' => [
        'base_url' => env('FIRECRAWL_BASE_URL', 'https://crawl.meerdevelopment.nl/firecrawl/v1'),
        'username' => env('FIRECRAWL_USERNAME', 'admin'),
        'password' => env('FIRECRAWL_PASSWORD', 'mounted-fascism-outsell-equivocal-spokesman-scarf'),
        'timeout' => env('FIRECRAWL_TIMEOUT', 300),
        'poll_interval' => env('FIRECRAWL_POLL_INTERVAL', 10),
    ],

];
