<?php

namespace App\Policies;

use App\Models\TenantSubscription;
use App\Models\User;

/**
 * Lecture : le super admin de plateforme voit tout ; un master/admin ne voit QUE les abonnements de son
 * propre tenant. Écriture : plateforme uniquement (les tenants ne modifient pas leur abonnement eux-mêmes).
 */
class TenantSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $this->isTenantManager($user);
    }

    public function view(User $user, TenantSubscription $subscription): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->isTenantManager($user) && (int) $subscription->tenant_id === (int) $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, TenantSubscription $subscription): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, TenantSubscription $subscription): bool
    {
        return false; // l'historique n'est jamais supprimé
    }

    private function isTenantManager(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasAnyRole(['master', 'admin']);
    }
}
