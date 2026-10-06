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

    // "Continue with Google" on the login page. Leave the client id blank to
    // turn Google sign-in off (the button is hidden and the routes 404).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
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
        // the paid deepseek-v4-flash; its ":free" tier is kept for the last
        // resort below.
        'fallback_model' => env('LLAMA_FALLBACK_MODEL', 'deepseek-v4-flash'),
        // Last resort after the primary and fallback models both fail. The
        // free tier was once seen giving degenerate, repeating output, so it
        // stays strictly last and callers still validate structured replies.
        // It is rate-limited, so no retries by default. Leave blank to disable.
        'last_resort_model' => env('LLAMA_LAST_RESORT_MODEL', 'deepseek-v4-flash:free'),
        'last_resort_timeout' => env('LLAMA_LAST_RESORT_TIMEOUT', 45),
        'last_resort_max_retries' => env('LLAMA_LAST_RESORT_MAX_RETRIES', 0),
        // Per-feature limits below are sized so the worst case — every model
        // timing out in turn (Llama, then DeepSeek, then DeepSeek free) —
        // stays under PHP's 120s max_execution_time for web requests.
        //
        // Professor dashboard's class analysis, which a professor waits on: a
        // slow Llama hands over after a few seconds. Worst case ~35s.
        'class_insights' => [
            'timeout' => env('LLAMA_INSIGHTS_TIMEOUT', 8),
            'max_retries' => env('LLAMA_INSIGHTS_MAX_RETRIES', 0),
            'fallback_timeout' => env('LLAMA_INSIGHTS_FALLBACK_TIMEOUT', 15),
            'fallback_max_retries' => env('LLAMA_INSIGHTS_FALLBACK_MAX_RETRIES', 0),
            'last_resort_timeout' => env('LLAMA_INSIGHTS_LAST_RESORT_TIMEOUT', 12),
        ],
        // Professor QuestAI Coach chat: the professor waits on the reply, so
        // each model gets one attempt. Worst case ~100s.
        'questai_coach' => [
            'timeout' => env('LLAMA_COACH_TIMEOUT', 25),
            'max_retries' => env('LLAMA_COACH_MAX_RETRIES', 0),
            'fallback_timeout' => env('LLAMA_COACH_FALLBACK_TIMEOUT', 40),
            'fallback_max_retries' => env('LLAMA_COACH_FALLBACK_MAX_RETRIES', 0),
            'last_resort_timeout' => env('LLAMA_COACH_LAST_RESORT_TIMEOUT', 35),
        ],
        // AI quiz generation: one attempt per model for each batch (batches
        // run in parallel). Worst case ~110s.
        'quiz' => [
            'timeout' => env('LLAMA_QUIZ_TIMEOUT', 35),
            'max_retries' => env('LLAMA_QUIZ_MAX_RETRIES', 0),
            'fallback_timeout' => env('LLAMA_QUIZ_FALLBACK_TIMEOUT', 40),
            'fallback_max_retries' => env('LLAMA_QUIZ_FALLBACK_MAX_RETRIES', 0),
            'last_resort_timeout' => env('LLAMA_QUIZ_LAST_RESORT_TIMEOUT', 35),
        ],
        // AI competency suggestions (from a subject's uploaded modules): one
        // attempt per model, leaving room for PDF text extraction. Worst
        // case ~95s plus extraction.
        'competency_suggestions' => [
            'timeout' => env('LLAMA_COMPETENCY_TIMEOUT', 30),
            'max_retries' => env('LLAMA_COMPETENCY_MAX_RETRIES', 0),
            'fallback_timeout' => env('LLAMA_COMPETENCY_FALLBACK_TIMEOUT', 35),
            'fallback_max_retries' => env('LLAMA_COMPETENCY_FALLBACK_MAX_RETRIES', 0),
            'last_resort_timeout' => env('LLAMA_COMPETENCY_LAST_RESORT_TIMEOUT', 30),
        ],
        // QuestAI Coach slide decks are written in parallel parts of up to 5
        // slides; each part gets one attempt per model. Worst case ~105s.
        'slide_deck' => [
            'max_tokens' => env('LLAMA_SLIDE_DECK_MAX_TOKENS', 2500),
            'timeout' => env('LLAMA_SLIDE_DECK_TIMEOUT', 30),
            'fallback_timeout' => env('LLAMA_SLIDE_DECK_FALLBACK_TIMEOUT', 40),
            'last_resort_timeout' => env('LLAMA_SLIDE_DECK_LAST_RESORT_TIMEOUT', 35),
        ],
    ],

];
