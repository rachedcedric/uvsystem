<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Log;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * À poser sur tout modèle dont les lignes appartiennent à un tenant.
 *  - lecture : scope global (fail closed) ;
 *  - création : tenant_id vient du contexte serveur, jamais du client ; une valeur
 *    différente fournie par le code est refusée ;
 *  - modification : tenant_id est immuable ;
 *  - parents : toute clé étrangère vers un modèle tenantisé doit désigner un parent du MÊME tenant, à la
 *    création comme à la modification (aucune contrainte composite SQL : le contrôle est applicatif).
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

        $this->assertParentsBelongToTenant(onlyDirty: false);

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty('tenant_id')) {
            throw new LogicException('tenant_id est immuable.');
        }

        $this->assertParentsBelongToTenant(onlyDirty: true);

        return parent::performUpdate($query);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Contrôle applicatif de cohérence parent/enfant (remplace une contrainte composite SQL).
     * Appelé AVANT l'écriture : en cas de parent étranger rien n'est écrit.
     * Les clés absentes en base sont laissées à la contrainte de clé étrangère SQL.
     */
    protected function assertParentsBelongToTenant(bool $onlyDirty): void
    {
        $tenantId = $this->getAttribute('tenant_id');

        if ($tenantId === null) {
            return; // modèles à tenant facultatif (compte plateforme, journal) : aucun parent tenantisé à protéger
        }

        foreach (static::tenantParentReferences() as [$foreignKey, $parentClass, $parentKey]) {
            $value = $this->getAttribute($foreignKey);

            if ($value === null || ($onlyDirty && ! $this->isDirty($foreignKey))) {
                continue;
            }

            // Sans AUCUN scope : on veut le vrai propriétaire du parent, y compris supprimé logiquement.
            $parent = $parentClass::withoutGlobalScopes()->where($parentKey, $value)->first(['tenant_id']);

            if ($parent !== null && (int) $parent->tenant_id !== (int) $tenantId) {
                Log::warning('Référence inter-tenant refusée', [
                    'model' => static::class,
                    'field' => $foreignKey,
                    'parent' => $parentClass,
                    'tenant_id' => (int) $tenantId,
                    'user_id' => auth()->id(),
                ]);

                throw CrossTenantReference::forField($foreignKey, class_basename($parentClass));
            }
        }
    }

    /**
     * Relations belongsTo vers un modèle tenantisé, détectées une fois par classe par réflexion :
     * [clé étrangère, classe du parent, clé du parent]. Les relations par clé fonctionnelle (ex. le code
     * d'un moyen de paiement) sont ignorées : elles ne désignent pas une ligne par identifiant.
     *
     * @return list<array{0: string, 1: class-string, 2: string}>
     */
    protected static function tenantParentReferences(): array
    {
        static $cache = [];

        return $cache[static::class] ??= (function () {
            $model = new static;
            $references = [];

            foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();

                if ($method->isStatic() || $method->getNumberOfParameters() > 0 || ! $type instanceof ReflectionNamedType || $type->getName() !== BelongsTo::class) {
                    continue;
                }

                $relation = $model->{$method->getName()}();
                $related = $relation->getRelated();

                if (! in_array(BelongsToTenant::class, class_uses_recursive($related), true)
                    || $relation->getOwnerKeyName() !== $related->getKeyName()) {
                    continue;
                }

                $references[] = [$relation->getForeignKeyName(), $related::class, $related->getKeyName()];
            }

            return $references;
        })();
    }
}
