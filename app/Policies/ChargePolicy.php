<?php

namespace App\Policies;

use App\Models\Charge;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès aux charges d'exploitation.
 *
 * Utilise les permissions Spatie:
 * - view_charges, create_charges, edit_charges, delete_charges, export_charges
 */
class ChargePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_charges');
    }

    public function view(User $user, Charge $charge): bool
    {
        return $user->can('view_charges');
    }

    public function create(User $user): bool
    {
        return $user->can('create_charges');
    }

    public function update(User $user, Charge $charge): bool
    {
        return $user->can('edit_charges');
    }

    public function delete(User $user, Charge $charge): bool
    {
        return $user->can('delete_charges');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_charges');
    }

    public function restore(User $user, Charge $charge): bool
    {
        return $user->can('delete_charges');
    }

    public function forceDelete(User $user, Charge $charge): bool
    {
        return $user->hasRole('master');
    }

    public function export(User $user): bool
    {
        return $user->can('export_charges');
    }
}
