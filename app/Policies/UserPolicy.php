<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès à la gestion des utilisateurs.
 * Utilise les permissions Spatie.
 */
class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_users');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('view_users');
    }

    public function create(User $user): bool
    {
        return $user->can('create_users');
    }

    public function update(User $user, User $model): bool
    {
        // Un utilisateur peut toujours modifier son propre profil
        if ($user->id === $model->id) {
            return true;
        }

        return $user->can('edit_users');
    }

    public function delete(User $user, User $model): bool
    {
        // Impossible de se supprimer soi-même
        if ($user->id === $model->id) {
            return false;
        }

        return $user->can('delete_users');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_users');
    }

    public function restore(User $user, User $model): bool
    {
        return $user->can('delete_users');
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
