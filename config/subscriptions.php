<?php

use App\Services\Subscriptions\Payments\JekoSubscriptionPaymentGateway;
use App\Services\Subscriptions\Payments\ManualSubscriptionPaymentGateway;

return [
    /*
    | Offre attribuée par la migration aux tenants existants dont `tenants.plan` est vide ou inconnu.
    | ENTREPRISE (illimitée) : la migration ne doit jamais bloquer ou limiter une société déjà en place.
    */
    'default_legacy_plan' => env('SUBSCRIPTIONS_LEGACY_PLAN', 'entreprise'),

    /*
    | POLITIQUE PAR DÉFAUT : FAIL CLOSED. Un tenant sans ligne d'abonnement est en accès RESTREINT (lecture,
    | exports de récupération, aucune écriture métier, aucune création d'utilisateur/agent) et n'a aucune
    | fonctionnalité d'offre. La migration crée un abonnement pour chaque tenant existant, et toute création
    | de tenant (plateforme ou souscription publique) en crée un : « sans abonnement » n'est donc plus un état normal.
    | false = ancien mode « hérité » (illimité), réservé aux bases de test et à une transition explicite.
    */
    'require_subscription' => (bool) env('SUBSCRIPTION_REQUIRED', env('SUBSCRIPTIONS_REQUIRED', true)),

    // Fonctionnalités de récupération de données : toujours permises en accès restreint ou sans abonnement.
    'recovery_features' => ['exports_pdf', 'exports_excel'],

    // Reprise des notifications en échec : nombre maximal de tentatives par notification.
    'notification_max_attempts' => 5,

    // Espace de stockage des justificatifs et rapports (en Mo) : appliqué à l'enregistrement de nouveaux fichiers.
    'storage_disk' => 'local',

    // Sauvegardes dédiées : nombre de sauvegardes conservées par tenant.
    'dedicated_backups_keep' => 7,

    // Souscription publique : protections anti-abus.
    'signup' => [
        'link_ttl_hours' => 24,
        'min_seconds_to_submit' => 3,   // un formulaire envoyé plus vite est un robot
        'max_per_ip_per_day' => 10,     // inscriptions vérifiées par adresse IP et par 24 h (IP partagées des opérateurs mobiles)
        'max_requests_per_ip_per_hour' => 20, // envois du formulaire par adresse IP et par heure
        'max_mails_per_email_per_hour' => 3,
        'disposable_domains' => ['mailinator.com', 'yopmail.com', '10minutemail.com', 'guerrillamail.com', 'tempmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com'],
    ],

    // Jours restants (J-n) auxquels un avertissement d'expiration est envoyé, une seule fois chacun.
    'warning_days' => [7, 3, 1],

    // Essai appliqué à la souscription publique si l'offre n'en définit pas (jamais utilisé en interne).
    'default_trial_days' => 14,

    // Passerelles de paiement : nom => classe implémentant SubscriptionPaymentGateway.
    // Les abonnements se paient uniquement via Jèko ; « manual » ne sert qu'au super admin pour dépanner (jamais par défaut).
    'default_gateway' => env('SUBSCRIPTIONS_PAYMENT_GATEWAY', 'jeko'),

    // Un paiement Jèko resté « en attente » au-delà de cette durée est annulé (rattrapage toutes les 5 min) : une demande
    // abandonnée ne bloque plus le changement d'offre. Si Jèko répond « payé » entre-temps, le paiement est confirmé, jamais annulé.
    'pending_payment_ttl_hours' => (int) env('SUBSCRIPTIONS_PENDING_PAYMENT_TTL_HOURS', 48),

    'gateways' => [
        'jeko' => JekoSubscriptionPaymentGateway::class,
        'manual' => ManualSubscriptionPaymentGateway::class,
    ],

    // Variantes visuelles proposées pour une offre (clé stockée => libellé).
    'visual_variants' => ['default' => 'Standard', 'accent' => 'Mise en avant', 'dark' => 'Sombre'],

    // Lien vers les conditions d'utilisation affiché à la souscription (null = texte seul).
    'terms_url' => env('SUBSCRIPTIONS_TERMS_URL'),
    'terms_version' => '2026-10',
];
