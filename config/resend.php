<?php

/*
 * Surcharge de la configuration du paquet resend/resend-laravel (fusionnée avec ses valeurs par défaut).
 *
 * Le paquet enregistre une route POST /resend/webhook qui n'exige AUCUNE signature tant que
 * `resend.webhook.secret` est vide, et l'application n'en a pas besoin : Resend est configuré sur
 * /api/webhooks/resend (signature vérifiée, isolation par tenant). On rend la route du paquet
 * injoignable en la liant à un domaine qui ne peut pas exister.
 */
return [
    'domain' => 'webhook-du-paquet-desactive.invalid',
];
