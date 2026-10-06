<?php

namespace App\Enums;

/**
 * Ce que l'abonnement autorise à un tenant (politique centralisée : SubscriptionAccessPolicy).
 *  - Full       : tout.
 *  - Restricted : lecture, exports, accès à l'abonnement ; aucune écriture métier (essai/abonnement
 *                 expiré, annulé ou suspendu). Aucune donnée n'est supprimée.
 *  - Blocked    : tenant suspendu par la plateforme (accès coupé, voir Tenant::suspend()).
 */
enum SubscriptionAccessLevel: string
{
    case Full = 'full';
    case Restricted = 'restricted';
    case Blocked = 'blocked';
}
