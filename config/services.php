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

    'aio' => [
        'base_url' => env('AIO_BASE_URL', 'https://app.aio.tech'),
        'token' => env('AIO_TOKEN'),
        'rate_limit_per_minute' => (int) env('AIO_RATE_LIMIT_PER_MINUTE', 60),
        'concurrency_limit' => (int) env('AIO_CONCURRENCY_LIMIT', 3),
        'heavy_budget_seconds' => (int) env('AIO_HEAVY_BUDGET_SECONDS', 20),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
    ],

    'telegram' => [
        'allowed_user_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEGRAM_ALLOWED_USER_IDS', ''))
        ))),
        'allowed_usernames' => array_values(array_filter(array_map(
            fn ($u) => strtolower(ltrim(trim($u), '@')),
            explode(',', (string) env('TELEGRAM_ALLOWED_USERNAMES', ''))
        ))),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

];
