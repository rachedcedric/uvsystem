<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Origines autorisées : liste explicite par environnement (CORS_ALLOWED_ORIGINS, séparées par des virgules).
    // L'APK et /master sont same-origin et n'ont pas besoin de CORS ; ceci ne sert qu'aux clients API externes.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'https://uv-system.com,https://app.uv-system.com'
    ))))),

    // Réseau local de développement mobile : uniquement hors production.
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^http://192\.168\.\d+\.\d+:\d+$#', '#^http://localhost:\d+$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
