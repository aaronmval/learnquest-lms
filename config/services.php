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

    'routeway' => [
        'api_key' => env('LLAMA_API_KEY'),
        'base_url' => env('LLAMA_API_URL', 'https://api.routeway.ai/v1'),
        'model' => env('LLAMA_MODEL', 'llama-3.3-70b-instruct'),
        'timeout' => env('LLAMA_API_TIMEOUT', 60),
        // Retries only apply to transient failures (502/503/504, connection
        // errors) — never to 4xx (bad request/auth), since retrying those
        // can't succeed.
        'max_retries' => env('LLAMA_MAX_RETRIES', 2),
        'retry_delay_ms' => env('LLAMA_RETRY_DELAY_MS', 500),
        // Same Routeway account/key, different model — tried only after the
        // primary model exhausts its retries. Leave blank to disable. Uses
        // the paid deepseek-v4-flash, not the ":free" tier — the free tier
        // was observed returning degenerate, repeating output.
        'fallback_model' => env('LLAMA_FALLBACK_MODEL', 'deepseek-v4-flash'),
    ],

];
