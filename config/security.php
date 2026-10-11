<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Configuration - UVMASTER
    |--------------------------------------------------------------------------
    |
    | Configuration de sécurité renforcée pour système de gestion financière
    |
    */

    // ==============================================
    // AUTHENTIFICATION & SESSIONS
    // ==============================================
    'authentication' => [
        // Verrouillage de compte après X tentatives échouées
        'max_login_attempts' => env('SECURITY_MAX_LOGIN_ATTEMPTS', 3),
        'lockout_duration' => env('SECURITY_LOCKOUT_DURATION', 15), // minutes

        // Expiration de session (minutes)
        'session_lifetime' => env('SECURITY_SESSION_LIFETIME', 30),
        'session_idle_timeout' => env('SECURITY_SESSION_IDLE_TIMEOUT', 15),

        // Authentification à deux facteurs (2FA)
        '2fa_enabled' => env('SECURITY_2FA_ENABLED', true),
        '2fa_required_for_roles' => ['master', 'admin'],

        // Date (AAAA-MM-JJ) à partir de laquelle les rôles ci-dessus DOIVENT avoir activé la 2FA pour
        // continuer. Avant cette date (ou si vide) : simple bandeau de rappel, personne n'est bloqué.
        // Le super admin de plateforme est toujours soumis à la 2FA, sans délai.
        '2fa_enforce_from' => env('SECURITY_2FA_ENFORCE_FROM'),

        // Durée pendant laquelle un appareil « de confiance » ne redemande pas le code (jours).
        '2fa_trusted_device_days' => (int) env('SECURITY_2FA_TRUSTED_DEVICE_DAYS', 30),

        // Rotation des tokens
        'token_rotation_enabled' => true,
        'token_lifetime' => 60, // minutes
    ],

    // ==============================================
    // TRANSACTIONS FINANCIÈRES
    // ==============================================
    'transactions' => [
        // Signature numérique des transactions
        'signature_enabled' => env('SECURITY_TRANSACTION_SIGNATURE', true),
        'signature_algorithm' => 'sha256',

        // Double validation pour montants élevés
        'dual_validation' => [
            'enabled' => env('SECURITY_DUAL_VALIDATION', true),
            'threshold' => env('SECURITY_DUAL_VALIDATION_THRESHOLD', 1000000), // FCFA
            'required_roles' => ['master', 'admin'],
        ],

        // Vérification d'intégrité
        'integrity_check' => [
            'enabled' => true,
            'check_interval' => 3600, // secondes (1 heure)
        ],

        // Limite de transaction par jour
        'daily_limits' => [
            'agent' => env('SECURITY_AGENT_DAILY_LIMIT', 10000000), // 10M FCFA
            'master' => null, // pas de limite
        ],
    ],

    // ==============================================
    // AUDIT & LOGGING
    // ==============================================
    'audit' => [
        // Niveau d'audit
        'level' => env('SECURITY_AUDIT_LEVEL', 'strict'), // strict, normal, minimal

        // Actions critiques à auditer
        'critical_actions' => [
            'uv_distribution',
            'remboursement',
            'agent_creation',
            'agent_deletion',
            'solde_modification',
            'role_assignment',
            'settings_change',
        ],

        // Rétention des logs (jours)
        'log_retention' => env('SECURITY_LOG_RETENTION', 365),

        // Notification des actions suspectes
        'suspicious_activity_notification' => true,
        'notification_emails' => explode(',', env('SECURITY_NOTIFICATION_EMAILS', '')),
    ],

    // ==============================================
    // CHIFFREMENT
    // ==============================================
    'encryption' => [
        // Chiffrement des données sensibles
        'enabled' => env('SECURITY_ENCRYPTION_ENABLED', true),

        // Champs à chiffrer en base de données
        'encrypted_fields' => [
            'agents' => ['telephone', 'adresse'],
            'fournisseurs' => ['telephone', 'email'],
            'users' => ['email', 'telephone'],
        ],

        // Algorithme de chiffrement
        'algorithm' => 'AES-256-CBC',
    ],

    // ==============================================
    // PROTECTION API
    // ==============================================
    'api' => [
        // Rate limiting strict
        'rate_limit' => [
            'enabled' => true,
            'default' => env('SECURITY_API_RATE_LIMIT', 60), // requêtes par minute
            'authenticated' => env('SECURITY_API_RATE_LIMIT_AUTH', 120),
            'financial_operations' => 30, // opérations financières
        ],

        // IP Whitelisting
        'ip_whitelist' => [
            'enabled' => env('SECURITY_IP_WHITELIST_ENABLED', false),
            'ips' => explode(',', env('SECURITY_WHITELISTED_IPS', '')),
        ],

        // API Key rotation
        'api_key_rotation' => [
            'enabled' => true,
            'rotation_period' => 30, // jours
        ],
    ],

    // ==============================================
    // PROTECTION CONTRE LES FRAUDES
    // ==============================================
    'fraud_detection' => [
        'enabled' => env('SECURITY_FRAUD_DETECTION', true),

        // Détection de patterns suspects
        'suspicious_patterns' => [
            'multiple_transactions_short_time' => [
                'enabled' => true,
                'threshold' => 5, // transactions
                'timeframe' => 300, // secondes (5 minutes)
            ],
            'unusual_amount' => [
                'enabled' => true,
                'deviation_factor' => 3, // 3x la moyenne habituelle
            ],
            'off_hours_transactions' => [
                'enabled' => true,
                'allowed_hours' => [6, 22], // 6h-22h
            ],
        ],

        // Actions automatiques
        'auto_block' => [
            'enabled' => true,
            'block_duration' => 60, // minutes
            'notify_admin' => true,
        ],
    ],

    // ==============================================
    // SAUVEGARDE & RÉCUPÉRATION
    // ==============================================
    'backup' => [
        // Sauvegarde automatique
        'enabled' => env('SECURITY_BACKUP_ENABLED', true),
        'frequency' => 'daily', // daily, weekly, monthly
        'retention' => 30, // jours

        // Chiffrement des sauvegardes
        'encrypt_backups' => true,
        'backup_password' => env('BACKUP_ARCHIVE_PASSWORD'),

        // Vérification d'intégrité
        'verify_integrity' => true,
    ],

    // ==============================================
    // VALIDATION DES DONNÉES
    // ==============================================
    'validation' => [
        // Validation stricte des montants
        'amount_validation' => [
            'max_digits' => 15,
            'allow_negative' => false,
            'allow_decimal' => true,
            'decimal_places' => 2,
        ],

        // Validation des numéros de téléphone
        'phone_validation' => [
            'format' => env('SECURITY_PHONE_FORMAT', '/^[0-9]{8,15}$/'),
            'country_code_required' => false,
        ],

        // Sanitization XSS
        'xss_protection' => true,
        'sql_injection_protection' => true,
    ],

    // ==============================================
    // CONFORMITÉ & RGPD
    // ==============================================
    'compliance' => [
        // Consentement utilisateur
        'require_consent' => true,

        // Droit à l'oubli
        'right_to_be_forgotten' => true,
        'anonymization_delay' => 30, // jours après demande

        // Export des données
        'data_export_enabled' => true,
        'export_format' => 'json', // json, csv, pdf
    ],

    // ==============================================
    // ALERTES DE SÉCURITÉ
    // ==============================================
    'alerts' => [
        // Types d'alertes
        'types' => [
            'failed_login' => [
                'enabled' => true,
                'threshold' => 3,
                'action' => 'lock_and_notify',
            ],
            'unauthorized_access' => [
                'enabled' => true,
                'action' => 'log_and_notify',
            ],
            'suspicious_transaction' => [
                'enabled' => true,
                'action' => 'block_and_notify',
            ],
            'data_breach_attempt' => [
                'enabled' => true,
                'action' => 'block_and_alert',
            ],
        ],

        // Canaux de notification
        'notification_channels' => ['mail', 'sms', 'slack'],
    ],

    // ==============================================
    // RESTRICTIONS D'ACCÈS
    // ==============================================
    'access_control' => [
        // Restrictions horaires
        'time_restrictions' => [
            'enabled' => env('SECURITY_TIME_RESTRICTIONS', false),
            'allowed_hours' => [6, 22], // 6h-22h
            'exceptions' => ['master'], // rôles exemptés
        ],

        // Restrictions géographiques
        'geo_restrictions' => [
            'enabled' => env('SECURITY_GEO_RESTRICTIONS', false),
            'allowed_countries' => explode(',', env('SECURITY_ALLOWED_COUNTRIES', 'CI,SN,BF')),
        ],

        // Device fingerprinting
        'device_tracking' => [
            'enabled' => true,
            'require_verification_new_device' => true,
        ],
    ],

    // ==============================================
    // MOTS DE PASSE
    // ==============================================
    'password' => [
        // Politique de mot de passe
        'min_length' => env('SECURITY_PASSWORD_MIN_LENGTH', 12),
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special_chars' => true,
        'special_chars' => '!@#$%^&*()-_=+[]{}|;:,.<>?',

        // Expiration et historique
        'expires_in_days' => env('SECURITY_PASSWORD_EXPIRY', 90),
        'history_count' => 5, // ne pas réutiliser les 5 derniers mots de passe

        // Complexité
        'prevent_common_passwords' => true,
        'prevent_personal_info' => true,
    ],

    // ==============================================
    // MAINTENANCE & MONITORING
    // ==============================================
    'monitoring' => [
        // Health checks
        'health_check_enabled' => true,
        'health_check_interval' => 300, // secondes (5 minutes)

        // Performance monitoring
        'performance_monitoring' => true,
        'slow_query_threshold' => 1000, // millisecondes

        // Security scanning
        'vulnerability_scan' => [
            'enabled' => true,
            'frequency' => 'weekly',
        ],
    ],
];
