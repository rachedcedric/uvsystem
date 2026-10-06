<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\User;

/**
 * Droits des écrans de paramétrage : réservés aux administrateurs (ou aux
 * porteurs des permissions système). Plus stricts que Filament, où ces
 * ressources n'ont aucune policy.
 */
final class Settings
{
    public static function allows(User $user, string $ability): bool
    {
        if ($user->hasAnyRole(['master', 'admin'])) {
            return true;
        }

        return $user->can(in_array($ability, ['viewAny', 'view'], true) ? 'view_system_settings' : 'edit_system_settings');
    }
}
