<?php

namespace App\Support\Mobile\Crud;

use App\Models\User;

/** Liste des ressources mobiles génériques, indexées par slug. */
class Registry
{
    /**
     * Un master n'a que des agents : SousDistributeurs et AgentsPartenaires restent dans le code (et leurs données en base)
     * mais ne sont plus proposés ni accessibles.
     *
     * @return list<class-string<MobileResource>>
     */
    public static function all(): array
    {
        return [
            Resources\Agents::class,
            Resources\Fournisseurs::class,
            Resources\Paliers::class,
            Resources\ModesPaiement::class,
            Resources\CategoriesCharges::class,
            Resources\CategoriesCaisse::class,
            Resources\Utilisateurs::class,
            Resources\Ventes::class,
            Resources\Remboursements::class,
            Resources\Approvisionnements::class,
            Resources\Charges::class,
            Resources\MouvementsCaisse::class,
        ];
    }

    /** @return class-string<MobileResource>|null */
    public static function find(string $slug): ?string
    {
        foreach (self::all() as $resource) {
            if ($resource::slug() === $slug) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Ressources visibles par l'utilisateur, regroupées pour le menu « Plus ».
     *
     * @return array<string, list<class-string<MobileResource>>>
     */
    public static function menuFor(?User $user): array
    {
        $groups = [];

        foreach (self::all() as $resource) {
            if ($resource::slug() === 'agents' || $resource::hidden()) {
                continue; // écrans dédiés (onglet Agents, listes natives)
            }
            if ($user && $resource::can($user, 'viewAny')) {
                $groups[$resource::group()][] = $resource;
            }
        }

        return $groups;
    }
}
