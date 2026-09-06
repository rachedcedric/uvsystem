<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UvTransaction;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy pour contrôler l'accès aux transactions UV.
 * Utilise les permissions Spatie.
 */
class UvTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_uv_transactions');
    }

    public function view(User $user, UvTransaction $transaction): bool
    {
        return $user->can('view_uv_transactions');
    }

    public function create(User $user): bool
    {
        return $user->can('create_uv_transactions');
    }

    public function update(User $user, UvTransaction $transaction): bool
    {
        return $user->can('edit_uv_transactions');
    }

    public function delete(User $user, UvTransaction $transaction): bool
    {
        return $user->can('delete_uv_transactions');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_uv_transactions');
    }

    public function restore(User $user, UvTransaction $transaction): bool
    {
        return $user->can('delete_uv_transactions');
    }

    public function forceDelete(User $user, UvTransaction $transaction): bool
    {
        // Suppression définitive interdite (données financières)
        return false;
    }
}
