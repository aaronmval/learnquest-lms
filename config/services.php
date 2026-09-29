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
        // Tighter limits for the professor dashboard's class analysis, which
        // a professor waits on: a slow Llama hands over to the fallback model
        // (DeepSeek) after a few seconds instead of after the full default
        // timeout x retries. Seconds; retries as above.
        'class_insights' => [
            'timeout' => env('LLAMA_INSIGHTS_TIMEOUT', 8),
            'max_retries' => env('LLAMA_INSIGHTS_MAX_RETRIES', 0),
            'fallback_timeout' => env('LLAMA_INSIGHTS_FALLBACK_TIMEOUT', 15),
            'fallback_max_retries' => env('LLAMA_INSIGHTS_FALLBACK_MAX_RETRIES', 0),
        ],
        // Professor QuestAI Coach chat: the professor waits on the reply, so a
        // slow or failing Llama hands over to the fallback model (DeepSeek)
        // after one attempt instead of the full default timeout x retries.
        'questai_coach' => [
            'timeout' => env('LLAMA_COACH_TIMEOUT', 25),
            'max_retries' => env('LLAMA_COACH_MAX_RETRIES', 0),
            'fallback_timeout' => env('LLAMA_COACH_FALLBACK_TIMEOUT', 45),
            'fallback_max_retries' => env('LLAMA_COACH_FALLBACK_MAX_RETRIES', 0),
        ],
        // AI quiz generation: one Llama attempt per batch, then the fallback
        // model, so the worst case stays well under PHP's 120s web limit.
        'quiz' => [
            'timeout' => env('LLAMA_QUIZ_TIMEOUT', 40),
            'max_retries' => env('LLAMA_QUIZ_MAX_RETRIES', 0),
            'fallback_timeout' => env('LLAMA_QUIZ_FALLBACK_TIMEOUT', 60),
            'fallback_max_retries' => env('LLAMA_QUIZ_FALLBACK_MAX_RETRIES', 0),
        ],
        // QuestAI Coach slide decks are written in parallel parts of up to 5
        // slides. Each part gets one Llama attempt, then one fallback attempt,
        // so the worst case (timeout + fallback_timeout) stays well under
        // PHP's 120s max_execution_time for web requests.
        'slide_deck' => [
            'max_tokens' => env('LLAMA_SLIDE_DECK_MAX_TOKENS', 2500),
            'timeout' => env('LLAMA_SLIDE_DECK_TIMEOUT', 35),
            'fallback_timeout' => env('LLAMA_SLIDE_DECK_FALLBACK_TIMEOUT', 60),
        ],
    ],

];
