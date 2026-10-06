<?php

namespace App\Models;

use App\Enums\SubscriptionCycle;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Catalogue d'offres : GLOBAL (aucun tenant_id), géré uniquement depuis /platform.
 * Montants en FCFA entiers. Un maximum NULL = illimité (jamais 0).
 */
class SubscriptionPlan extends Model
{
    /** Préfixe des fonctionnalités « inclut toutes celles de l'offre {slug} ». */
    public const INCLUDES_PREFIX = 'toutes_les_fonctions_';

    protected $fillable = [
        'name', 'slug', 'description', 'monthly_price', 'yearly_price', 'trial_days',
        'max_users', 'max_agents', 'storage_limit_mb', 'features', 'display_order',
        'visual_variant', 'is_active', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'trial_days' => 'integer',
            'max_users' => 'integer',
            'max_agents' => 'integer',
            'storage_limit_mb' => 'integer',
            'features' => 'array',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Une offre utilisée par un abonnement (même historique) ne se supprime jamais : on la désactive.
        static::deleting(function (SubscriptionPlan $plan) {
            if ($plan->isUsed()) {
                throw new LogicException('Cette offre est utilisée par des abonnements : désactivez-la au lieu de la supprimer.');
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    /** Toutes les lignes d'abonnement de TOUS les tenants (réservé au code plateforme). */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function subscriptionsCount(): int
    {
        return TenantContext::withoutTenancy(fn () => TenantSubscription::query()->where('subscription_plan_id', $this->getKey())->count());
    }

    public function isUsed(): bool
    {
        return $this->exists && $this->subscriptionsCount() > 0;
    }

    public function priceFor(SubscriptionCycle $cycle): int
    {
        return $cycle === SubscriptionCycle::Yearly ? (int) $this->yearly_price : (int) $this->monthly_price;
    }

    /**
     * Fonctionnalités effectives : celles de l'offre + celles des offres qu'elle « inclut »
     * (marqueur toutes_les_fonctions_{slug}). Les marqueurs eux-mêmes sont retirés du résultat.
     *
     * @param  list<string>  $seen  slugs déjà visités (protège d'une inclusion circulaire)
     * @return list<string>
     */
    public function resolvedFeatures(array $seen = []): array
    {
        $seen[] = $this->slug;
        $resolved = [];

        foreach ((array) $this->features as $feature) {
            if (! str_starts_with((string) $feature, self::INCLUDES_PREFIX)) {
                $resolved[] = (string) $feature;

                continue;
            }

            $slug = substr((string) $feature, strlen(self::INCLUDES_PREFIX));
            if (in_array($slug, $seen, true)) {
                continue;
            }

            $included = static::query()->where('slug', $slug)->first();
            if ($included) {
                $resolved = [...$resolved, ...$included->resolvedFeatures($seen)];
            }
        }

        return array_values(array_unique($resolved));
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->resolvedFeatures(), true);
    }
}
