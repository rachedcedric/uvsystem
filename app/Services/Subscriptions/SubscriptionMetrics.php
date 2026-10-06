<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Indicateurs du tableau de bord plateforme. Tout vient de TenantSubscription / SubscriptionPlan (jamais de
 * tenants.plan), sur la ligne COURANTE de chaque tenant et d'après les dates (statut effectif).
 *
 * MRR / ARR sont des revenus récurrents ESTIMÉS, pas des revenus encaissés : seuls les abonnements actifs
 * comptent (ni essais, ni expirés, ni suspendus), au prix figé à la souscription.
 *   mensuel : MRR = prix mensuel,       ARR = prix mensuel × 12
 *   annuel  : MRR = prix annuel / 12,   ARR = prix annuel
 * Les abonnements hérités (prix 0) ne pèsent donc pas. Le calcul est fait en mémoire : prévu pour des
 * centaines de tenants, à passer en agrégat SQL au-delà de quelques milliers.
 */
class SubscriptionMetrics
{
    /** @return array<string, mixed> */
    public function snapshot(int $expiringWithinDays = 7): array
    {
        $subscriptions = TenantContext::withoutTenancy(
            fn () => TenantSubscription::query()->with('plan')->whereNotNull('current_marker')->get()
        );

        $byStatus = $subscriptions->countBy(fn (TenantSubscription $s) => $s->effectiveStatus()->value);
        $limit = now()->addDays($expiringWithinDays);

        $suspendedTenantIds = Tenant::query()->where('status', Tenant::STATUS_SUSPENDED)->pluck('id');
        $suspended = $suspendedTenantIds
            ->merge($subscriptions->where('status', SubscriptionStatus::Suspended)->pluck('tenant_id'))
            ->unique()->count();

        return [
            'tenants' => Tenant::query()->count(),
            'without_subscription' => Tenant::query()->count() - $subscriptions->count(),
            'trialing' => $byStatus[SubscriptionStatus::Trialing->value] ?? 0,
            'active' => $byStatus[SubscriptionStatus::Active->value] ?? 0,
            'expired' => $byStatus[SubscriptionStatus::Expired->value] ?? 0,
            'cancelled' => $byStatus[SubscriptionStatus::Cancelled->value] ?? 0,
            'suspended' => $suspended,
            'mrr' => (int) $subscriptions->sum(fn (TenantSubscription $s) => $s->monthlyRecurringRevenue()),
            'arr' => (int) $subscriptions->sum(fn (TenantSubscription $s) => $s->annualRecurringRevenue()),
            'by_plan' => $this->byPlan($subscriptions),
            'expiring_soon' => $subscriptions->filter(fn (TenantSubscription $s) => $s->effectiveStatus() === SubscriptionStatus::Active
                && $s->current_period_ends_at !== null && $s->current_period_ends_at->lte($limit))->count(),
            'trials_expiring_soon' => $subscriptions->filter(fn (TenantSubscription $s) => $s->effectiveStatus() === SubscriptionStatus::Trialing
                && $s->trial_ends_at !== null && $s->trial_ends_at->lte($limit))->count(),
        ];
    }

    /** @return array<string, array{count: int, mrr: int}> nom d'offre => répartition */
    private function byPlan(Collection $subscriptions): array
    {
        return $subscriptions
            ->groupBy(fn (TenantSubscription $s) => $s->plan?->name ?? 'Inconnue')
            ->map(fn (Collection $group) => [
                'count' => $group->count(),
                'mrr' => (int) $group->sum(fn (TenantSubscription $s) => $s->monthlyRecurringRevenue()),
            ])
            ->all();
    }
}
