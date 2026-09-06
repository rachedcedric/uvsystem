<?php

namespace App\Policies;

use App\Models\Remboursement;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès aux remboursements.
 * Utilise les permissions Spatie.
 */
class RemboursementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_remboursements');
    }

    public function view(User $user, Remboursement $remboursement): bool
    {
        return $user->can('view_remboursements');
    }

    public function create(User $user): bool
    {
        return $user->can('create_remboursements');
    }

    public function update(User $user, Remboursement $remboursement): bool
    {
        return $user->can('edit_remboursements');
    }

    public function delete(User $user, Remboursement $remboursement): bool
    {
        return $user->can('delete_remboursements');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_remboursements');
    }

    public function restore(User $user, Remboursement $remboursement): bool
    {
        return $user->can('delete_remboursements');
    }

    public function forceDelete(User $user, Remboursement $remboursement): bool
    {
        // Suppression définitive interdite (données financières)
        return false;
    }
}
