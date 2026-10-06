<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scope du modèle User : un utilisateur de tenant ne voit que les comptes de son tenant ;
 * le super admin de plateforme les voit tous ; pendant l'authentification (login, session,
 * token) aucun filtre ne s'applique, sans quoi personne ne pourrait se connecter.
 */
class UserTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Pas d'appel à TenantContext::isUnscoped()/id() ici : ils interrogent le guard, qui
        // charge l'utilisateur par une requête User => boucle infinie.
        [$filter, $id] = TenantContext::userScope();

        if (! $filter) {
            return;
        }

        $id === null
            ? $builder->whereRaw('1 = 0')
            : $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}
