<?php

namespace App\Policies;

use App\Models\Fournisseur;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès aux fournisseurs.
 * Utilise les permissions Spatie.
 */
class FournisseurPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_fournisseurs');
    }

    public function view(User $user, Fournisseur $fournisseur): bool
    {
        return $user->can('view_fournisseurs');
    }

    public function create(User $user): bool
    {
        return $user->can('create_fournisseurs');
    }

    public function update(User $user, Fournisseur $fournisseur): bool
    {
        return $user->can('edit_fournisseurs');
    }

    public function delete(User $user, Fournisseur $fournisseur): bool
    {
        return $user->can('delete_fournisseurs');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_fournisseurs');
    }

    public function restore(User $user, Fournisseur $fournisseur): bool
    {
        return $user->can('delete_fournisseurs');
    }

    public function forceDelete(User $user, Fournisseur $fournisseur): bool
    {
        return false;
    }
}
