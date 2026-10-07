<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Security\RoleAssignmentGuard;
use Spatie\Permission\Models\Role;

/** Vérifie côté serveur les rôles demandés (même règle que le mobile) : un état de formulaire forgé ne contourne rien. */
trait ChecksRoleAssignment
{
    protected function assertRolesAllowed(): void
    {
        if (! array_key_exists('roles', $this->data ?? [])) {
            return; // champ masqué : Filament n'enregistre aucun rôle
        }

        $names = Role::query()->whereIn('id', (array) $this->data['roles'])->pluck('name')->all();

        RoleAssignmentGuard::assertCanAssign(auth()->user(), $this->record ?? null, $names);
    }
}
