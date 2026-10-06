<?php

namespace App\Policies;

use App\Models\SubscriptionChangeRequest;
use App\Models\User;

/** Les demandes de changement d'offre se traitent depuis la plateforme (super admin) ; jamais supprimées. */
class SubscriptionChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SubscriptionChangeRequest $request): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SubscriptionChangeRequest $request): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SubscriptionChangeRequest $request): bool
    {
        return false;
    }
}
