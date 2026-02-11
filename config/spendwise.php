<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Categorization
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'model'                 => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'api_key'               => env('ANTHROPIC_API_KEY'),
        'batch_size'            => 25,
        'rate_limit_ms'         => 500,
        'confidence_thresholds' => [
            'auto_accept'   => 0.85,  // Auto-categorize silently
            'flag_review'   => 0.60,  // Categorize but flag
            'ask_question'  => 0.40,  // Generate question for user
            // Below 0.40 → open-ended question
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plaid Bank Integration
    |--------------------------------------------------------------------------
    */
    'plaid' => [
        'client_id'   => env('PLAID_CLIENT_ID'),
        'secret'      => env('PLAID_SECRET'),
        'env'         => env('PLAID_ENV', 'sandbox'),
        'base_url'    => match (env('PLAID_ENV', 'sandbox')) {
            'production'  => 'https://production.plaid.com',
            'development' => 'https://development.plaid.com',
            default       => 'https://sandbox.plaid.com',
        },
        'products'      => ['transactions'],
        'country_codes' => ['US'],
        'webhook_url'   => env('PLAID_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Intervals
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'bank_transactions_hours' => 4,
        'email_orders_hours'      => 6,
        'subscription_detection'  => 'daily',
        'savings_analysis'        => 'weekly',
        'question_expiry_days'    => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | reCAPTCHA v3
    |--------------------------------------------------------------------------
    */
    'captcha' => [
        'enabled'    => env('RECAPTCHA_SITE_KEY') !== null,
        'site_key'   => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'threshold'  => (float) env('RECAPTCHA_THRESHOLD', 0.5),
        'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    */
    'two_factor' => [
        'enabled'    => env('TWO_FACTOR_ENABLED', true),
        'issuer'     => env('APP_NAME', 'SpendWise'),
        'digits'     => 6,
        'period'     => 30,
        'algorithm'  => 'sha1',
        'recovery_codes' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Export
    |--------------------------------------------------------------------------
    */
    'tax' => [
        'default_bracket' => 22,
        'export_dir'      => 'tax-exports',
    ],

];
