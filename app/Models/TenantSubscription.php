<?php

namespace App\Models;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une ligne d'abonnement d'un tenant. Les lignes ne sont JAMAIS supprimées ni réécrites pour changer
 * d'offre ou de cycle : l'ancienne passe en `replaced` et reste consultable (historique).
 * Une seule ligne est « courante » par tenant (current_marker = tenant_id, unique en base).
 * Écriture réservée à SubscriptionManager.
 */
class TenantSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'subscription_plan_id', 'cycle', 'status', 'applied_price', 'starts_at', 'current_period_ends_at',
        'trial_ends_at', 'cancelled_at', 'suspended_at', 'ended_at', 'auto_renew', 'external_reference', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'cycle' => SubscriptionCycle::class,
            'status' => SubscriptionStatus::class,
            'applied_price' => 'integer',
            'starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'suspended_at' => 'datetime',
            'ended_at' => 'datetime',
            'auto_renew' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** Un abonnement ne casse jamais le flux métier d'un utilisateur : ses écritures passent par le manager. */
    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull('current_marker');
    }

    public function isCurrent(): bool
    {
        return $this->getAttribute('current_marker') !== null;
    }

    public function isTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    /** Fin de l'accès « plein » prévu par cette ligne (essai ou période payée), null = sans échéance. */
    public function endsAt(): ?CarbonInterface
    {
        return $this->isTrial() ? $this->trial_ends_at : $this->current_period_ends_at;
    }

    /**
     * Statut tenant compte des dates : un essai ou une période dépassés sont « expirés » même si la
     * commande subscriptions:process n'a pas encore tourné (l'accès ne dépend jamais du cron).
     */
    public function effectiveStatus(): SubscriptionStatus
    {
        return match ($this->status) {
            SubscriptionStatus::Trialing => $this->trial_ends_at !== null && ! $this->trial_ends_at->isFuture()
                ? SubscriptionStatus::Expired : SubscriptionStatus::Trialing,
            SubscriptionStatus::Active => $this->current_period_ends_at !== null && ! $this->current_period_ends_at->isFuture()
                ? SubscriptionStatus::Expired : SubscriptionStatus::Active,
            default => $this->status,
        };
    }

    /** Jours restants (arrondis au supérieur), null si aucune échéance. Négatif = déjà échu. */
    public function daysRemaining(): ?int
    {
        $end = $this->endsAt();

        return $end === null ? null : (int) ceil(now()->floatDiffInDays($end, false));
    }

    /**
     * Revenu récurrent ESTIMÉ (pas un revenu encaissé), sur le prix figé à la souscription :
     * mensuel = prix ; annuel = prix / 12. Seuls les abonnements actifs comptent (ni essais ni expirés).
     */
    public function monthlyRecurringRevenue(): int
    {
        if ($this->effectiveStatus() !== SubscriptionStatus::Active) {
            return 0;
        }

        return (int) round($this->applied_price / $this->cycle->monthsInCycle());
    }

    /** Annuel = prix annuel ; mensuel = prix mensuel × 12. */
    public function annualRecurringRevenue(): int
    {
        if ($this->effectiveStatus() !== SubscriptionStatus::Active) {
            return 0;
        }

        return $this->cycle === SubscriptionCycle::Yearly ? (int) $this->applied_price : (int) $this->applied_price * 12;
    }
}
