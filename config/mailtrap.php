<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mailtrap API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Mailtrap Email API v2
    | Documentation: https://api-docs.mailtrap.io/
    |
    */

    // API Token (required for all requests)
    'api_token' => env('MAILTRAP_API_TOKEN', ''),

    // Mailtrap Account ID
    'account_id' => env('MAILTRAP_ACCOUNT_ID', ''),

    // Sending Configuration
    'sending' => [
        // Inbox ID for Email Sending API
        'inbox_id' => env('MAILTRAP_INBOX_ID', ''),

        // Default sender email (must be verified in Mailtrap)
        'from_email' => env('MAILTRAP_FROM_EMAIL', env('MAIL_FROM_ADDRESS', 'noreply@example.com')),

        // Default sender name
        'from_name' => env('MAILTRAP_FROM_NAME', env('MAIL_FROM_NAME', config('app.name'))),

        // Enable/disable sandbox mode (testing without real sending)
        'sandbox' => env('MAILTRAP_SANDBOX', true),
    ],

    // Testing Configuration (Email Sandbox)
    'testing' => [
        // Enable testing mode
        'enabled' => env('MAILTRAP_TESTING_ENABLED', true),

        // Testing inbox ID (different from sending inbox)
        'inbox_id' => env('MAILTRAP_TESTING_INBOX_ID', ''),
    ],

    // Template Configuration
    'templates' => [
        // Default template UUID (optional)
        'default_uuid' => env('MAILTRAP_DEFAULT_TEMPLATE_UUID', ''),

        // Available templates (UUID => name mapping)
        'available' => [
            // Example: 'welcome' => 'uuid-here',
            // Example: 'password_reset' => 'uuid-here',
        ],
    ],

    // Bulk Sending Configuration
    'bulk' => [
        // Enable bulk sending
        'enabled' => env('MAILTRAP_BULK_ENABLED', false),

        // Max recipients per batch
        'max_recipients' => env('MAILTRAP_BULK_MAX_RECIPIENTS', 500),
    ],

    // Rate Limiting
    'rate_limit' => [
        // Requests per second
        'requests_per_second' => env('MAILTRAP_RATE_LIMIT_RPS', 10),

        // Emails per hour
        'emails_per_hour' => env('MAILTRAP_RATE_LIMIT_EPH', 1000),
    ],

    // Webhook Configuration (for email events)
    'webhooks' => [
        // Enable webhooks
        'enabled' => env('MAILTRAP_WEBHOOKS_ENABLED', false),

        // Webhook secret for signature verification
        'secret' => env('MAILTRAP_WEBHOOK_SECRET', ''),

        // Events to subscribe to
        'events' => [
            'delivery',
            'soft_bounce',
            'hard_bounce',
            'spam',
            'open',
            'click',
            'unsubscribe',
        ],
    ],

    // Retry Configuration
    'retry' => [
        // Number of retry attempts for failed requests
        'max_attempts' => env('MAILTRAP_RETRY_MAX_ATTEMPTS', 3),

        // Delay between retries (in seconds)
        'delay' => env('MAILTRAP_RETRY_DELAY', 1),
    ],

    // Logging
    'logging' => [
        // Log all API requests
        'enabled' => env('MAILTRAP_LOGGING_ENABLED', true),

        // Log channel to use
        'channel' => env('MAILTRAP_LOGGING_CHANNEL', 'stack'),
    ],
];
