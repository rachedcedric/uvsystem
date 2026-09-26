<?php

namespace App\Policies;

use App\Models\CaisseCategorie;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CaisseCategoriePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_caisse');
    }

    public function view(User $user, CaisseCategorie $categorie): bool
    {
        return $user->can('view_caisse');
    }

    public function create(User $user): bool
    {
        return $user->can('create_caisse');
    }

    public function update(User $user, CaisseCategorie $categorie): bool
    {
        return $user->can('edit_caisse');
    }

    public function delete(User $user, CaisseCategorie $categorie): bool
    {
        return $user->can('delete_caisse');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_caisse');
    }

    public function restore(User $user, CaisseCategorie $categorie): bool
    {
        return $user->can('delete_caisse');
    }

    public function forceDelete(User $user, CaisseCategorie $categorie): bool
    {
        // Suppression définitive interdite (données financières)
        return false;
    }
}
