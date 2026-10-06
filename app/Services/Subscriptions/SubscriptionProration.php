<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Carbon\CarbonInterface;

/**
 * Prorata d'un changement d'offre ou de cycle en cours de période payée (montants entiers, FCFA).
 *
 *  - ratio   = temps restant de la période / durée de la période (0 à 1) ;
 *  - crédit  = prix payé de la période en cours × ratio (la part non consommée) ;
 *  - même cycle   : coût = prix de la nouvelle offre × ratio, l'échéance est conservée ;
 *  - cycle changé : coût = prix complet de la nouvelle période, qui démarre maintenant ;
 *  - à régler = max(0, coût − crédit) (une rétrogradation ne génère pas de remboursement automatique).
 * Rien à régler pendant un essai, ni pour un abonnement hérité (prix appliqué 0, sans échéance).
 */
class SubscriptionProration
{
    /** @return array{credit: int, charge: int, amount_due: int, ratio: float, new_period_ends_at: ?string, cycle_changed: bool} */
    public function quote(TenantSubscription $current, SubscriptionPlan $plan, SubscriptionCycle $cycle, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $changedCycle = $current->cycle !== $cycle;

        $none = ['credit' => 0, 'charge' => 0, 'amount_due' => 0, 'ratio' => 0.0, 'new_period_ends_at' => $current->current_period_ends_at?->toIso8601String(), 'cycle_changed' => $changedCycle];

        if ($current->effectiveStatus() !== SubscriptionStatus::Active || $current->current_period_ends_at === null || $current->applied_price <= 0) {
            return $none;
        }

        $end = $current->current_period_ends_at;
        $start = $current->cycle === SubscriptionCycle::Yearly ? $end->copy()->subYearNoOverflow() : $end->copy()->subMonthNoOverflow();
        $total = max(1, $start->diffInSeconds($end, true));
        $ratio = max(0.0, min(1.0, $at->diffInSeconds($end, false) / $total));

        $credit = (int) round($current->applied_price * $ratio);
        $newPrice = $plan->priceFor($cycle);
        $charge = $changedCycle ? $newPrice : (int) round($newPrice * $ratio);

        return [
            'credit' => $credit,
            'charge' => $charge,
            'amount_due' => max(0, $charge - $credit),
            'ratio' => round($ratio, 4),
            'new_period_ends_at' => ($changedCycle ? $cycle->endOfPeriodStartingAt($at) : $end)->toIso8601String(),
            'cycle_changed' => $changedCycle,
        ];
    }
}
