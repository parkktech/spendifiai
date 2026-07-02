<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
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

    /*
    |--------------------------------------------------------------------------
    | Google OAuth (Socialite) + Gmail API
    |--------------------------------------------------------------------------
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft OAuth (Outlook / Hotmail / Live / MSN)
    |--------------------------------------------------------------------------
    */
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI', '/api/v1/email/callback/outlook'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plaid
    |--------------------------------------------------------------------------
    */
    'plaid' => [
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
        'env' => env('PLAID_ENV', 'sandbox'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic Claude API
    |--------------------------------------------------------------------------
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),

        // D17 AI Cost Discipline — per-call-site model resolution (additive).
        // Each Claude call site resolves its purpose-specific key first, falling
        // back to the global `model` when unset. Narration/wording default to the
        // cheaper Haiku tier; extraction stays on the global (Sonnet) tier;
        // categorization moves to Haiku behind the confidence-routing safety net (D17.2).
        'model_narration' => env('ANTHROPIC_MODEL_NARRATION', 'claude-haiku-4-5'),
        'model_wording' => env('ANTHROPIC_MODEL_WORDING', 'claude-haiku-4-5'),
        'model_extraction' => env('ANTHROPIC_MODEL_EXTRACTION', env('ANTHROPIC_MODEL', 'claude-sonnet-4-6')),
        'model_categorization' => env('ANTHROPIC_MODEL_CATEGORIZATION', 'claude-haiku-4-5'),

        // D17 per-purpose daily budget caps (call counters live in the Cache).
        // A null/absent cap is treated as uncapped (PHP_INT_MAX) by the budget
        // helper, so throughput is unchanged until a cap is explicitly configured.
        'daily_budget_narration' => env('CLAUDE_DAILY_BUDGET_NARRATION', 200),
        'daily_budget_wording' => env('CLAUDE_DAILY_BUDGET_WORDING', 100),
        'daily_budget_categorization' => env('CLAUDE_DAILY_BUDGET_CATEGORIZATION'), // null => uncapped
    ],

];
