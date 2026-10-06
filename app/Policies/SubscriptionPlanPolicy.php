<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\User;

/**
 * Le catalogue d'offres est global et réservé à la plateforme : User::isSuperAdmin() (rôle ET aucun tenant),
 * jamais le seul nom de rôle. Une offre utilisée ne se supprime pas (désactivation à la place).
 */
class SubscriptionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SubscriptionPlan $plan): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SubscriptionPlan $plan): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SubscriptionPlan $plan): bool
    {
        return $user->isSuperAdmin() && ! $plan->isUsed();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
