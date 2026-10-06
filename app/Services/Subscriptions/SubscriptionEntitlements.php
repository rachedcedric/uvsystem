<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionAccessLevel;
use App\Exceptions\FeatureNotAvailable;
use App\Exceptions\SubscriptionLimitReached;
use App\Exceptions\SubscriptionRestricted;
use App\Models\Agent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;

/**
 * POINT UNIQUE DE DÉCISION des droits d'abonnement.
 *
 * Convention d'illimité : une limite NULL = illimitée (jamais 0). Voir isUnlimited().
 * Sans argument, tout travaille sur le tenant COURANT (TenantContext) ; un tenant explicite n'est destiné
 * qu'au code de plateforme (tableaux de bord du super admin). Les compteurs sont toujours filtrés par
 * tenant_id : aucune donnée d'un autre tenant n'entre dans un calcul.
 *
 * Un tenant sans ligne d'abonnement est « hérité » : aucune limite (voir SubscriptionAccessPolicy).
 * Une rétrogradation ne supprime rien : les dépassements existants restent, seule la création est refusée.
 */
class SubscriptionEntitlements
{
    public static function isUnlimited(?int $limit): bool
    {
        return $limit === null;
    }

    public static function currentSubscription(Tenant|int $tenant): ?TenantSubscription
    {
        $id = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        return TenantContext::withoutTenancy(
            fn () => TenantSubscription::query()->with('plan')->where('tenant_id', $id)->whereNotNull('current_marker')->first()
        );
    }

    public function plan(Tenant|int|null $tenant = null): ?SubscriptionPlan
    {
        return self::currentSubscription($this->tenantId($tenant))?->plan;
    }

    public function hasFeature(Tenant|int|null $tenant, string $feature): bool
    {
        $plan = $this->plan($tenant);

        // Tenant hérité (sans abonnement) : rien n'est retiré.
        return $plan === null ? ! config('subscriptions.require_subscription') : $plan->hasFeature($feature);
    }

    public function assertFeature(Tenant|int|null $tenant, string $feature): void
    {
        if (! $this->hasFeature($tenant, $feature)) {
            throw FeatureNotAvailable::because("Cette fonctionnalité n'est pas incluse dans votre offre. Passez à une offre supérieure pour l'utiliser.");
        }
    }

    /** Pour les pages et actions : 403 explicite (une exception de validation n'a pas de sens sur un écran entier). */
    public function abortUnlessFeature(string $feature): void
    {
        abort_unless($this->hasFeature(null, $feature), 403, "Cette fonctionnalité n'est pas incluse dans votre offre. Passez à une offre supérieure pour l'utiliser.");
    }

    public function getUserLimit(Tenant|int|null $tenant = null): ?int
    {
        return $this->plan($tenant)?->max_users;
    }

    public function getAgentLimit(Tenant|int|null $tenant = null): ?int
    {
        return $this->plan($tenant)?->max_agents;
    }

    public function getUserUsage(Tenant|int|null $tenant = null): int
    {
        $id = $this->tenantId($tenant);

        return User::withoutGlobalScopes()->where('tenant_id', $id)->count();
    }

    /** Agents actifs (les agents supprimés logiquement ne comptent pas, mais leur restauration est contrôlée). */
    public function getAgentUsage(Tenant|int|null $tenant = null): int
    {
        $id = $this->tenantId($tenant);

        return Agent::withoutGlobalScope(TenantScope::class)->where('tenant_id', $id)->count();
    }

    public function remainingUsers(Tenant|int|null $tenant = null): ?int
    {
        $limit = $this->getUserLimit($tenant);

        return self::isUnlimited($limit) ? null : max(0, $limit - $this->getUserUsage($tenant));
    }

    public function remainingAgents(Tenant|int|null $tenant = null): ?int
    {
        $limit = $this->getAgentLimit($tenant);

        return self::isUnlimited($limit) ? null : max(0, $limit - $this->getAgentUsage($tenant));
    }

    public function canCreateUser(Tenant|int|null $tenant = null): bool
    {
        return $this->userCreationRefusal($tenant) === null;
    }

    public function canCreateAgent(Tenant|int|null $tenant = null): bool
    {
        return $this->agentCreationRefusal($tenant) === null;
    }

    /** Message clair expliquant pourquoi la création est refusée, ou null si elle est permise. */
    public function userCreationRefusal(Tenant|int|null $tenant = null): ?string
    {
        return $this->refusal($tenant, 'utilisateurs', $this->getUserLimit($tenant), fn () => $this->getUserUsage($tenant));
    }

    public function agentCreationRefusal(Tenant|int|null $tenant = null): ?string
    {
        return $this->refusal($tenant, 'agents', $this->getAgentLimit($tenant), fn () => $this->getAgentUsage($tenant));
    }

    public function assertCanCreateUser(Tenant|int|null $tenant = null): void
    {
        $this->throwIfRefused($this->userCreationRefusal($tenant), $tenant);
    }

    public function assertCanCreateAgent(Tenant|int|null $tenant = null): void
    {
        $this->throwIfRefused($this->agentCreationRefusal($tenant), $tenant);
    }

    /** @param  callable(): int  $usage */
    private function refusal(Tenant|int|null $tenant, string $noun, ?int $limit, callable $usage): ?string
    {
        $id = $this->tenantId($tenant);

        if (SubscriptionAccessPolicy::levelFor($id) !== SubscriptionAccessLevel::Full) {
            return SubscriptionAccessPolicy::restrictedMessage();
        }

        if (self::isUnlimited($limit)) {
            return null;
        }

        $used = $usage();

        if ($used >= $limit) {
            $plan = $this->plan($id)?->name ?? 'actuelle';

            return "Limite de {$limit} {$noun} atteinte pour votre offre {$plan} ({$used} utilisés). "
                .'Les données existantes sont conservées ; passez à une offre supérieure pour en ajouter.';
        }

        return null;
    }

    private function throwIfRefused(?string $message, Tenant|int|null $tenant): void
    {
        if ($message === null) {
            return;
        }

        throw SubscriptionAccessPolicy::levelFor($this->tenantId($tenant)) !== SubscriptionAccessLevel::Full
            ? SubscriptionRestricted::because($message)
            : SubscriptionLimitReached::because($message);
    }

    private function tenantId(Tenant|int|null $tenant): int
    {
        if ($tenant instanceof Tenant) {
            return (int) $tenant->getKey();
        }

        return $tenant ?? TenantContext::requireId();
    }
}
