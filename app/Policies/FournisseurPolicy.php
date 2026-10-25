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
        // « Retour Agent » et les comptes d'agents sont des entrées système (type « Agent ») : ni renommées ni changées de type.
        return $user->can('edit_fournisseurs') && ! $fournisseur->isSystemAgentEntry();
    }

    public function delete(User $user, Fournisseur $fournisseur): bool
    {
        // Les entrées « Agent » portent l'historique et la dette des retours d'UV : jamais supprimées.
        return $user->can('delete_fournisseurs') && ! $fournisseur->isSystemAgentEntry();
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
