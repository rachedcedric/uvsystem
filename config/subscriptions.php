<?php

return [
    /*
    | Offre attribuée par la migration aux tenants existants dont `tenants.plan` est vide ou inconnu.
    | ENTREPRISE (illimitée) : la migration ne doit jamais bloquer ou limiter une société déjà en place.
    */
    'default_legacy_plan' => env('SUBSCRIPTIONS_LEGACY_PLAN', 'entreprise'),

    /*
    | Un tenant sans aucune ligne d'abonnement est « hérité » : aucune limite, aucune restriction
    | (c'est l'état de toute base non encore migrée). À passer à true quand tous les tenants ont un abonnement :
    | un tenant sans abonnement sera alors en accès restreint.
    */
    'require_subscription' => env('SUBSCRIPTIONS_REQUIRED', false),

    // Jours restants (J-n) auxquels un avertissement d'expiration est envoyé, une seule fois chacun.
    'warning_days' => [7, 3, 1],

    // Essai appliqué à la souscription publique si l'offre n'en définit pas (jamais utilisé en interne).
    'default_trial_days' => 14,

    // Passerelles de paiement : nom => classe implémentant SubscriptionPaymentGateway. Seule « manual » est fournie ;
    // ajouter CinetPay/PayDunya ici plus tard, après configuration explicite.
    'gateways' => [
        'manual' => \App\Services\Subscriptions\Payments\ManualSubscriptionPaymentGateway::class,
    ],

    // Variantes visuelles proposées pour une offre (clé stockée => libellé).
    'visual_variants' => ['default' => 'Standard', 'accent' => 'Mise en avant', 'dark' => 'Sombre'],

    // Lien vers les conditions d'utilisation affiché à la souscription (null = texte seul).
    'terms_url' => env('SUBSCRIPTIONS_TERMS_URL'),
    'terms_version' => '2026-10',
];
