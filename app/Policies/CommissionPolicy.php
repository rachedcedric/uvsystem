<?php

namespace App\Policies;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès aux commissions.
 * Utilise les permissions Spatie.
 */
class CommissionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_commissions');
    }

    public function view(User $user, Commission $commission): bool
    {
        return $user->can('view_commissions');
    }

    public function create(User $user): bool
    {
        return $user->can('create_commissions');
    }

    public function update(User $user, Commission $commission): bool
    {
        return $user->can('edit_commissions');
    }

    public function delete(User $user, Commission $commission): bool
    {
        return $user->can('delete_commissions');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_commissions');
    }

    public function restore(User $user, Commission $commission): bool
    {
        return $user->can('delete_commissions');
    }

    public function forceDelete(User $user, Commission $commission): bool
    {
        return false;
    }
}
