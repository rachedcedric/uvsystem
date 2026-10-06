<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use LogicException;

/**
 * À poser sur tout modèle dont les lignes appartiennent à un tenant.
 *  - lecture : scope global (fail closed) ;
 *  - création : tenant_id vient du contexte serveur, jamais du client ; une valeur
 *    différente fournie par le code est refusée ;
 *  - modification : tenant_id est immuable.
 * `tenant_id` ne doit figurer dans aucun $fillable.
 *
 * Les contrôles sont placés dans performInsert()/performUpdate() et non dans des événements
 * « creating » : ainsi withoutEvents() / saveQuietly() ne permettent pas de les contourner.
 * (Les insert() de masse du query builder ne passent par aucun modèle : la colonne NOT NULL
 * en base les fait échouer — fail closed.)
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(static::tenantScope());
    }

    protected static function tenantScope(): Scope
    {
        return new TenantScope;
    }

    /** Les modèles dont tenant_id est nullable (User, Activity) redéfinissent ceci. */
    protected static function tenantIdRequired(): bool
    {
        return true;
    }

    protected function performInsert(Builder $query)
    {
        $current = TenantContext::id();
        $given = $this->getAttribute('tenant_id');

        if ($current !== null) {
            if ($given !== null && (int) $given !== $current) {
                throw new LogicException('Écriture inter-tenant refusée.');
            }
            $this->setAttribute('tenant_id', $current);
        } elseif ($given === null && static::tenantIdRequired()) {
            throw new TenantContextMissing('Création refusée : aucun tenant courant pour '.static::class.'.');
        }

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty('tenant_id')) {
            throw new LogicException('tenant_id est immuable.');
        }

        return parent::performUpdate($query);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
