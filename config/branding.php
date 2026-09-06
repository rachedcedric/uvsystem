<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Logo Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration du logo de l'entreprise utilisé dans l'application
    |
    */

    'logo' => [
        // Logo principal
        'path' => 'images/logo.png',
        'url' => '/images/logo.png',
        'alt' => 'MODELEC CI Logo',

        // Logo pour thème sombre
        'dark_path' => 'images/logo-dark.png',
        'dark_url' => '/images/logo-dark.png',

        // Dimensions
        'width' => 200,
        'height' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Company Information
    |--------------------------------------------------------------------------
    |
    | Informations de l'entreprise utilisées dans les documents
    |
    */

    'company' => [
        'name' => env('COMPANY_NAME', 'MODELEC CI'),
        'tagline' => env('COMPANY_TAGLINE', 'Système de Gestion UV'),
        'address' => env('COMPANY_ADDRESS', ''),
        'city' => env('COMPANY_CITY', ''),
        'country' => env('COMPANY_COUNTRY', 'Côte d\'Ivoire'),
        'phone' => env('COMPANY_PHONE', ''),
        'email' => env('COMPANY_EMAIL', 'contact@company.com'),
        'website' => env('COMPANY_WEBSITE', 'www.company.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Settings
    |--------------------------------------------------------------------------
    |
    | Configuration des documents PDF générés
    |
    */

    'documents' => [
        // En-tête
        'header' => [
            'show_logo' => true,
            'show_company_info' => true,
            'background_color' => '#3b82f6',
            'text_color' => '#ffffff',
        ],

        // Pied de page
        'footer' => [
            'show_page_number' => true,
            'show_company_info' => true,
            'text' => 'Document généré automatiquement par MODELEC CI',
        ],

        // Filigrane
        'watermark' => [
            'enabled' => true,
            'text' => 'MODELEC CI',
            'opacity' => 0.03,
            'rotation' => -45,
        ],
    ],
];
