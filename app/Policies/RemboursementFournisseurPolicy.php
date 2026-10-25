<?php

namespace App\Policies;

use App\Models\RemboursementFournisseur;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès aux remboursements fournisseurs.
 * Utilise les permissions Spatie. Miroir de RemboursementPolicy.
 */
class RemboursementFournisseurPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_remboursements_fournisseurs');
    }

    public function view(User $user, RemboursementFournisseur $remboursementFournisseur): bool
    {
        return $user->can('view_remboursements_fournisseurs');
    }

    public function create(User $user): bool
    {
        return $user->can('create_remboursements_fournisseurs');
    }

    public function update(User $user, RemboursementFournisseur $remboursementFournisseur): bool
    {
        // Un remboursement annulé est figé : il reste consultable dans l'historique, jamais modifié.
        return $user->can('edit_remboursements_fournisseurs') && ! $remboursementFournisseur->isCancelled();
    }

    /**
     * Annulation (contre-passation logique) : réservée aux comptes explicitement autorisés à supprimer un
     * remboursement fournisseur (master et admin de l'entreprise). Le scope d'entreprise garantit déjà qu'un
     * remboursement d'une autre entreprise est introuvable ; une opération déjà annulée ne l'est jamais deux fois.
     */
    public function cancel(User $user, RemboursementFournisseur $remboursementFournisseur): bool
    {
        return $user->can('delete_remboursements_fournisseurs')
            && ! $user->isReadOnly()
            && ! $remboursementFournisseur->isCancelled();
    }

    public function delete(User $user, RemboursementFournisseur $remboursementFournisseur): bool
    {
        return $user->can('delete_remboursements_fournisseurs');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_remboursements_fournisseurs');
    }

    public function restore(User $user, RemboursementFournisseur $remboursementFournisseur): bool
    {
        return $user->can('delete_remboursements_fournisseurs');
    }

    public function forceDelete(User $user, RemboursementFournisseur $remboursementFournisseur): bool
    {
        // Suppression définitive interdite (données financières)
        return false;
    }
}
