<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identité du produit (marque visible)
    |--------------------------------------------------------------------------
    |
    | Source unique du nom, du slogan et du domaine affichés partout (pages, PDF, e-mails, SMS, 2FA…).
    | APP_NAME reste une valeur TECHNIQUE (dossier des sauvegardes, nom du cookie de session, préfixe de cache) :
    | ne pas s'en servir pour afficher la marque. Le nom d'une entreprise cliente (table tenants) est une donnée
    | métier distincte et n'est jamais remplacé par la marque.
    |
    */

    'name' => env('BRAND_NAME', 'UVMASTER'),
    'tagline' => env('BRAND_TAGLINE', 'La gestion des UV simplifiée'),
    'domain' => env('BRAND_DOMAIN', 'uvmaster.ci'),
    'url' => env('BRAND_URL', 'https://uvmaster.ci'),
    'contact_email' => env('BRAND_CONTACT_EMAIL', 'contact@uvmaster.ci'),

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
        'alt' => 'UVMASTER',

        // Logo pour thème sombre
        'dark_path' => 'images/logo-wide-dark.png',
        'dark_url' => '/images/logo-wide-dark.png',

        // Dimensions
        'width' => 393,
        'height' => 92,
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
        'name' => env('COMPANY_NAME', env('BRAND_NAME', 'UVMASTER')),
        'tagline' => env('COMPANY_TAGLINE', env('BRAND_TAGLINE', 'La gestion des UV simplifiée')),
        'address' => env('COMPANY_ADDRESS', ''),
        'city' => env('COMPANY_CITY', ''),
        'country' => env('COMPANY_COUNTRY', 'Côte d\'Ivoire'),
        'phone' => env('COMPANY_PHONE', ''),
        'email' => env('COMPANY_EMAIL', env('BRAND_CONTACT_EMAIL', 'contact@uvmaster.ci')),
        'website' => env('COMPANY_WEBSITE', env('BRAND_DOMAIN', 'uvmaster.ci')),
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
            'text' => 'Document généré automatiquement par UVMASTER',
        ],

        // Filigrane
        'watermark' => [
            'enabled' => true,
            'text' => 'UVMASTER',
            'opacity' => 0.03,
            'rotation' => -45,
        ],
    ],
];
