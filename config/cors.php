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

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'admin/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',      // Frontend Vite
        'http://localhost:5173',      // Frontend Vite alt
        'http://localhost:5174',      // Frontend Vite alt
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5173',
        'http://localhost:8081',      // Expo Mobile
        'http://localhost:19006',     // Expo Web
        'exp://localhost:8081',       // Expo Dev
        'https://uv-system.com',      // Production
        'https://app.uv-system.com',  // Production frontend
    ],

    'allowed_origins_patterns' => [
        '#^http://192\.168\.\d+\.\d+:\d+$#', // Local network for mobile dev
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
