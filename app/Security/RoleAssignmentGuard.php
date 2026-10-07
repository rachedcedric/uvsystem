<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Qui peut attribuer quels rôles, et à qui, DANS une entreprise cliente. Une seule règle, appliquée par tous les
 * écrans (web, mobile) et toujours côté serveur : un champ masqué ou une valeur forgée ne contourne rien.
 *
 *  - master : propriétaire du compte client, attribue tous les rôles de l'entreprise (master compris) ;
 *  - admin  : mêmes droits d'administration, mais ne peut JAMAIS créer ni promouvoir un master (le master reste
 *             distinct de l'admin : lui seul nomme un autre master) ;
 *  - autres rôles : aucune attribution ;
 *  - un rôle de plateforme (super_admin…) n'est attribuable par personne ici ;
 *  - les rôles d'un compte master ne se modifient que par un master (la politique d'accès protège aussi le reste du compte) ;
 *  - l'entreprise garde toujours au moins un master (on ne retire pas le rôle au dernier).
 */
class RoleAssignmentGuard
{
    /** @return list<string> rôles que cet acteur peut attribuer (vide s'il ne peut rien attribuer) */
    public static function assignableFor(?User $actor): array
    {
        if ($actor === null || ! $actor->isAdmin() || ! $actor->can('assign_roles')) {
            return [];
        }

        $roles = User::assignableRoleNames();

        return $actor->isMaster() ? $roles : array_values(array_diff($roles, ['master']));
    }

    public static function canAssignRoles(?User $actor): bool
    {
        return self::assignableFor($actor) !== [];
    }

    /**
     * @param  list<string>  $roles  rôles demandés (noms)
     *
     * @throws ValidationException
     */
    public static function assertCanAssign(?User $actor, ?User $target, array $roles): void
    {
        $allowed = self::assignableFor($actor);

        if ($allowed === []) {
            throw self::refuse('Vous n\'avez pas le droit de modifier les rôles.');
        }

        $unknown = array_diff($roles, User::assignableRoleNames());
        if ($unknown !== []) {
            throw self::refuse('Rôle non autorisé.');
        }

        if (array_diff($roles, $allowed) !== []) {
            throw self::refuse('Seul un master peut attribuer le rôle master.');
        }

        if ($target !== null && $target->exists) {
            $current = $target->getRoleNames()->all();

            // Les rôles d'un compte master ne se modifient que par un master (même « sans rien changer » : un admin n'a pas à y toucher).
            if (in_array('master', $current, true) && ! $actor->isMaster()) {
                throw self::refuse('Seul un master peut modifier les rôles d\'un master.');
            }

            // Jamais d'entreprise sans master.
            if (in_array('master', $current, true) && ! in_array('master', $roles, true) && self::masterCount() <= 1) {
                throw self::refuse('L\'entreprise doit garder au moins un master : nommez d\'abord un autre master.');
            }
        }
    }

    private static function masterCount(): int
    {
        return User::query()->role('master')->count();
    }

    private static function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['roles' => $message]);
    }
}
