<?php

namespace App\Policies;

use App\Models\Approvisionnement;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès aux approvisionnements.
 * Utilise les permissions Spatie.
 */
class ApprovisionnementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_approvisionnements');
    }

    public function view(User $user, Approvisionnement $approvisionnement): bool
    {
        return $user->can('view_approvisionnements');
    }

    public function create(User $user): bool
    {
        return $user->can('create_approvisionnements');
    }

    public function update(User $user, Approvisionnement $approvisionnement): bool
    {
        return $user->can('edit_approvisionnements');
    }

    public function delete(User $user, Approvisionnement $approvisionnement): bool
    {
        return $user->can('delete_approvisionnements');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_approvisionnements');
    }

    public function restore(User $user, Approvisionnement $approvisionnement): bool
    {
        return $user->can('delete_approvisionnements');
    }

    public function forceDelete(User $user, Approvisionnement $approvisionnement): bool
    {
        return false;
    }
}
