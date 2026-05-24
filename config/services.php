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

    'marketx' => [
        'global_market_feed' => env('MARKETX_GLOBAL_MARKET_FEED'),
        'global_event_feed' => env('MARKETX_GLOBAL_EVENT_FEED'),
        'ai_model' => env('MARKETX_AI_MODEL', 'gpt-4.1-mini'),
        'admin_password_hash' => env('MARKETX_ADMIN_PASSWORD_HASH'),
        'ai_pipeline_enabled' => env('AI_PIPELINE_ENABLED', false),
        'gemini_api_key' => env('GEMINI_API_KEY'),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'groq_api_key' => env('GROQ_API_KEY'),
        'groq_model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),
        'max_event_ai_per_day' => env('MAX_EVENT_AI_PER_DAY', 20),
        'max_stock_ai_per_day' => env('MAX_STOCK_AI_PER_DAY', 50),
        'max_dynamic_ai_per_day' => env('MAX_DYNAMIC_AI_PER_DAY', 20),
    ],

];
