<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionAccessLevel;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionRestricted;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * POLITIQUE D'EXPIRATION CENTRALISÉE — l'unique endroit qui décide de ce qu'un état d'abonnement autorise.
 *
 *   tenant suspendu par la plateforme  -> Blocked    (accès coupé, voir Tenant::suspend())
 *   essai/abonnement actif non échu    -> Full
 *   annulé mais période payée en cours -> Full jusqu'à l'échéance
 *   expiré, annulé échu, suspendu      -> Restricted : lecture, exports, page d'abonnement ; AUCUNE écriture
 *                                         métier. Rien n'est supprimé (données, utilisateurs, agents, historiques).
 *   aucune ligne d'abonnement          -> Full (tenant « hérité »), sauf subscriptions.require_subscription.
 *
 * L'écriture est refusée dans les modèles (trait BelongsToTenant) : Filament, Livewire, /m, API, imports
 * et services passent tous par là — un bouton masqué n'est jamais la protection.
 */
class SubscriptionAccessPolicy
{
    /** @var array<int, SubscriptionAccessLevel> mémo par requête, vidé à chaque opération d'abonnement */
    private static array $levels = [];

    /** Profondeur de dérogation « amorçage » (voir withoutRestrictions). */
    private static int $bootstrapping = 0;

    public static function flush(): void
    {
        self::$levels = [];
    }

    /**
     * AMORÇAGE d'un tenant : crée son premier compte (master) alors qu'il n'a pas encore d'abonnement. Réservé au code
     * serveur de confiance (TenantProvisioner) ; ne lève que le refus « accès restreint » sur la création d'utilisateur —
     * les limites de l'offre, elles, continuent de s'appliquer.
     */
    public static function withoutRestrictions(\Closure $callback): mixed
    {
        self::$bootstrapping++;

        try {
            return $callback();
        } finally {
            self::$bootstrapping--;
        }
    }

    public static function isBootstrapping(): bool
    {
        return self::$bootstrapping > 0;
    }

    public static function levelFor(Tenant|int $tenant): SubscriptionAccessLevel
    {
        $id = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        return self::$levels[$id] ??= self::compute($id);
    }

    public static function isRestricted(Tenant|int $tenant): bool
    {
        return self::levelFor($tenant) !== SubscriptionAccessLevel::Full;
    }

    private static function compute(int $tenantId): SubscriptionAccessLevel
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return SubscriptionAccessLevel::Blocked;
        }

        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            return SubscriptionAccessLevel::Blocked;
        }

        $subscription = SubscriptionEntitlements::currentSubscription($tenantId);

        if ($subscription === null) {
            return config('subscriptions.require_subscription')
                ? SubscriptionAccessLevel::Restricted
                : SubscriptionAccessLevel::Full;
        }

        return self::levelForSubscription($subscription);
    }

    public static function levelForSubscription(TenantSubscription $subscription): SubscriptionAccessLevel
    {
        return match ($subscription->effectiveStatus()) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active => SubscriptionAccessLevel::Full,
            // Annulé : l'accès court jusqu'à la fin de la période déjà payée.
            SubscriptionStatus::Cancelled => $subscription->current_period_ends_at?->isFuture()
                ? SubscriptionAccessLevel::Full : SubscriptionAccessLevel::Restricted,
            default => SubscriptionAccessLevel::Restricted,
        };
    }

    /**
     * Refuse l'écriture d'un modèle tenantisé quand le tenant n'a plus le droit d'écrire.
     * Appelé par BelongsToTenant (insert, update, delete).
     *
     * Ne s'applique qu'aux requêtes d'un utilisateur authentifié de CE tenant : le code serveur de
     * confiance (runAs/withoutTenancy : plateforme, commandes, jobs) n'est jamais bridé ici.
     */
    public static function assertWritable(Model $model): void
    {
        if (TenantContext::hasExplicitContext()) {
            return;
        }

        $user = Auth::guard()->user();
        $tenantId = $model->getAttribute('tenant_id');

        if ($user === null || $tenantId === null || $user->tenant_id === null || (int) $user->tenant_id !== (int) $tenantId) {
            return;
        }

        if (self::levelFor((int) $tenantId) !== SubscriptionAccessLevel::Full) {
            throw SubscriptionRestricted::because(self::restrictedMessage());
        }
    }

    public static function restrictedMessage(): string
    {
        return "Votre abonnement n'est plus actif : la consultation et les exports restent disponibles, mais aucune modification n'est possible. Contactez l'administrateur de la plateforme pour le réactiver.";
    }
}
