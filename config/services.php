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

    'resend' => [
        'key' => env('RESEND_KEY'),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        // Écart maximal (secondes, passé ou futur) entre svix-timestamp et l'heure du serveur.
        'webhook_tolerance' => (int) env('RESEND_WEBHOOK_TOLERANCE', 300),
        // Durée de mémorisation d'un événement traité (anti-rejeu). Jamais inférieure à 2 × la tolérance.
        'webhook_dedup_ttl' => (int) env('RESEND_WEBHOOK_DEDUP_TTL', 86400),
        // Durée du bail posé pendant le traitement : si le processus meurt, l'événement redevient réessayable après ce délai.
        'webhook_lease' => (int) env('RESEND_WEBHOOK_LEASE', 120),
    ],

    'jeko' => [
        'base_url' => env('JEKO_BASE_URL', 'https://api.jeko.africa'),
        'api_key' => env('JEKO_API_KEY'),
        'api_key_id' => env('JEKO_API_KEY_ID'),
        'store_id' => env('JEKO_STORE_ID'),
        'webhook_secret' => env('JEKO_WEBHOOK_SECRET'),
        'redirect_hosts' => ['pay.jeko.africa', 'link.jeko.africa'],
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio SMS Service
    |--------------------------------------------------------------------------
    */
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Settings
    |--------------------------------------------------------------------------
    */
    'sms' => [
        'enabled' => env('SMS_ENABLED', false),
        'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '+225'),
    ],

];
