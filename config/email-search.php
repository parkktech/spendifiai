<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subject Patterns
    |--------------------------------------------------------------------------
    |
    | Keywords searched in email subjects across all providers (Gmail, IMAP,
    | Outlook). Broad coverage catches niche retailers that use non-standard
    | subject lines. Claude handles filtering out false positives.
    |
    */

    'subject_patterns' => [
        'order confirmation',
        'order receipt',
        'your order',
        'purchase confirmation',
        'payment receipt',
        'order shipped',
        'subscription renewal',
        'invoice',
        'your receipt',
        'thank you for your order',
        'order has been placed',
        'order details',
        'new order',
        'shipping confirmation',
        'delivery confirmation',
        'order has shipped',
        'payment confirmation',
        'purchase receipt',
        'order number',
        'thank you for your purchase',
        // Expanded patterns for niche retailers
        'receipt',
        'billing statement',
        'payment processed',
        'transaction confirmation',
        'order summary',
        'purchase summary',
        'renewal confirmation',
        'charge confirmation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender Prefixes
    |--------------------------------------------------------------------------
    |
    | Common email address prefixes used by retailers for order/receipt emails.
    | These are searched via the "from" field across all providers.
    |
    */

    'sender_prefixes' => [
        'orders@',
        'order@',
        'receipt@',
        'receipts@',
        'billing@',
        'invoice@',
        'noreply@',
        'no-reply@',
        'confirmation@',
        'ship-confirm@',
        'payments@',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction-Guided Search
    |--------------------------------------------------------------------------
    |
    | Settings for searching emails based on unreconciled bank transactions.
    | This lets the system find receipts for specific merchants that keyword
    | searches would miss.
    |
    */

    'transaction_guided' => [
        'min_amount' => 10.00,
        'max_merchant_queries' => 20,
        'lookback_days' => 90,
        'high_value_threshold' => 50,
    ],

];
