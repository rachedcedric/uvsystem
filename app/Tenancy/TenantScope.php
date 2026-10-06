<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Ajoute WHERE tenant_id = <tenant courant> à toute requête Eloquent ; sans tenant : aucun résultat. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::isUnscoped()) {
            return;
        }

        $id = TenantContext::id();

        $id === null
            ? $builder->whereRaw('1 = 0')
            : $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}
