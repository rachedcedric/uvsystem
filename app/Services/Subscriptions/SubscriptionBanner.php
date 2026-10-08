<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\User;

/**
 * Avertissement d'abonnement affiché en haut de chaque écran de l'entreprise (panneau web et mobile) : compte en
 * consultation seule, ou échéance à moins de 7 jours. Ne lit qu'un état déjà décidé ailleurs
 * (SubscriptionAccessPolicy) : il n'ouvre ni ne ferme aucun accès.
 */
class SubscriptionBanner
{
    public const WARN_DAYS = 7;

    /** @return array{level: 'danger'|'warning', title: string, message: string, manageable: bool}|null */
    public static function for(?User $user): ?array
    {
        if ($user === null || $user->tenant_id === null || $user->isSuperAdmin()) {
            return null;
        }

        $tenantId = (int) $user->tenant_id;
        $manageable = $user->hasAnyRole(['master', 'admin']);

        if (SubscriptionAccessPolicy::isRestricted($tenantId)) {
            return [
                'level' => 'danger',
                'title' => 'Compte en consultation seule.',
                'message' => 'Vos données et exports restent disponibles, mais les modifications sont bloquées jusqu’à la réactivation.',
                'manageable' => $manageable,
            ];
        }

        $subscription = SubscriptionEntitlements::currentSubscription($tenantId);
        $days = $subscription?->daysRemaining();

        if ($subscription !== null && $days !== null && $days <= self::WARN_DAYS
            && in_array($subscription->effectiveStatus(), [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            $days = max(0, $days);

            return [
                'level' => 'warning',
                'title' => 'Échéance proche.',
                'message' => $days === 0
                    ? 'Votre abonnement expire aujourd’hui.'
                    : 'Votre abonnement expire dans '.$days.' jour'.($days > 1 ? 's' : '').'.',
                'manageable' => $manageable,
            ];
        }

        return null;
    }
}
