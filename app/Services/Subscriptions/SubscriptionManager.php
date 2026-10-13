<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Notifications\Subscriptions\SubscriptionExpired;
use App\Notifications\Subscriptions\SubscriptionNotification;
use App\Notifications\Subscriptions\SubscriptionPlanChanged;
use App\Notifications\Subscriptions\SubscriptionReactivated;
use App\Notifications\Subscriptions\SubscriptionSuspended;
use App\Notifications\Subscriptions\TrialStarted;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des abonnements. Chaque opération est transactionnelle, verrouille la ligne du tenant
 * (lockForUpdate) pour sérialiser les opérations concurrentes, et ne supprime ni n'écrase jamais l'historique :
 *  - changement d'offre ou de cycle, nouvelle souscription : l'ancienne ligne passe en `replaced`
 *    (current_marker retiré) et une NOUVELLE ligne devient courante ;
 *  - renouvellement, suspension, réactivation, annulation, prolongation d'essai : la ligne est mise à jour et
 *    l'événement est consigné dans metadata.events.
 * Un tenant n'a jamais deux abonnements courants : current_marker est unique en base.
 *
 * Aucun paiement n'est encaissé ici : l'argent passe par SubscriptionBilling + une passerelle.
 * `tenants.plan` / `trial_ends_at` (obsolètes) sont recopiés en miroir, jamais lus.
 */
class SubscriptionManager
{
    public function __construct(private readonly SubscriptionNotifier $notifier) {}

    public function current(Tenant $tenant): ?TenantSubscription
    {
        return SubscriptionEntitlements::currentSubscription($tenant);
    }

    /** Historique complet, plus récent d'abord. @return Collection<int, TenantSubscription> */
    public function history(Tenant $tenant): Collection
    {
        return TenantContext::withoutTenancy(
            fn () => TenantSubscription::query()->with('plan')->where('tenant_id', $tenant->getKey())->orderByDesc('id')->get()
        );
    }

    // ------------------------------------------------------------------ souscriptions

    /** Démarre l'essai du tenant. Un seul essai par tenant, sauf $allowRepeat (décision explicite du super admin). */
    public function startTrial(Tenant $tenant, SubscriptionPlan $plan, SubscriptionCycle $cycle = SubscriptionCycle::Monthly, ?int $days = null, bool $allowRepeat = false): TenantSubscription
    {
        $this->assertAssignable($plan);
        $days ??= $plan->trial_days;

        if ($days < 1) {
            throw new InvalidSubscriptionTransition("L'offre {$plan->name} n'a pas de période d'essai.");
        }

        $subscription = $this->atomically($tenant, function () use ($tenant, $plan, $cycle, $days, $allowRepeat) {
            if (! $allowRepeat && $this->hadTrial($tenant)) {
                throw new InvalidSubscriptionTransition('Ce tenant a déjà bénéficié d\'une période d\'essai.');
            }

            return $this->replaceCurrent($tenant, $plan, $cycle, SubscriptionStatus::Trialing, [
                'starts_at' => now(),
                'trial_ends_at' => now()->addDays($days),
                'applied_price' => $plan->priceFor($cycle),
            ], 'trial_started', ['days' => $days]);
        });

        $this->notify($subscription, TrialStarted::class, "trial_started:{$subscription->id}");

        return $subscription;
    }

    /** Active un abonnement payant (nouvelle ligne ; l'éventuel essai ou l'ancienne ligne reste dans l'historique). */
    public function activate(Tenant $tenant, SubscriptionPlan $plan, SubscriptionCycle $cycle, ?CarbonInterface $startsAt = null, ?int $appliedPrice = null, ?string $externalReference = null): TenantSubscription
    {
        $this->assertAssignable($plan);

        return $this->atomically($tenant, function () use ($tenant, $plan, $cycle, $startsAt, $appliedPrice, $externalReference) {
            $start = $startsAt ?? now();

            return $this->replaceCurrent($tenant, $plan, $cycle, SubscriptionStatus::Active, [
                'starts_at' => $start,
                'current_period_ends_at' => $cycle->endOfPeriodStartingAt($start),
                'applied_price' => $appliedPrice ?? $plan->priceFor($cycle),
                'external_reference' => $externalReference,
            ], 'activated');
        });
    }

    public function changePlan(Tenant $tenant, SubscriptionPlan $plan, ?SubscriptionCycle $cycle = null): TenantSubscription
    {
        $this->assertAssignable($plan);

        $subscription = $this->atomically($tenant, function () use ($tenant, $plan, $cycle) {
            $current = $this->requireCurrent($tenant);
            $status = $current->effectiveStatus();
            $cycle ??= $current->cycle;

            if (! in_array($status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
                throw new InvalidSubscriptionTransition('Changement d\'offre impossible : l\'abonnement est '.mb_strtolower($status->label()).' (utilisez « attribuer une offre »).');
            }

            if ($current->subscription_plan_id === $plan->id && $current->cycle === $cycle) {
                throw new InvalidSubscriptionTransition('Le tenant est déjà sur cette offre et ce cycle.');
            }

            $attributes = [
                'starts_at' => now(),
                'applied_price' => $plan->priceFor($cycle),
                'trial_ends_at' => $current->trial_ends_at,
                'auto_renew' => $current->auto_renew,
                'external_reference' => $current->external_reference,
            ];

            if ($status === SubscriptionStatus::Active) {
                // Changement de cycle : nouvelle période complète à partir de maintenant ; sinon l'échéance est conservée.
                $attributes['current_period_ends_at'] = $current->cycle === $cycle
                    ? $current->current_period_ends_at
                    : $cycle->endOfPeriodStartingAt(now());
            }

            return $this->replaceCurrent($tenant, $plan, $cycle, $status, $attributes, 'plan_changed', [
                'quote' => app(SubscriptionProration::class)->quote($current, $plan, $cycle),
                'from_plan_id' => $current->subscription_plan_id,
                'from_cycle' => $current->cycle->value,
                'previous_subscription_id' => $current->id,
            ]);
        });

        $this->notify($subscription, SubscriptionPlanChanged::class, "plan_changed:{$subscription->id}");

        return $subscription;
    }

    public function changeCycle(Tenant $tenant, SubscriptionCycle $cycle): TenantSubscription
    {
        $current = $this->current($tenant) ?? throw new InvalidSubscriptionTransition('Ce tenant n\'a aucun abonnement : attribuez-lui une offre d\'abord.');

        return $this->changePlan($tenant, $current->plan, $cycle);
    }

    // ------------------------------------------------------------------ cycle de vie

    /** Prolonge la période payée d'un cycle (depuis l'échéance si elle n'est pas dépassée, sinon depuis maintenant). */
    public function renew(Tenant $tenant, ?string $externalReference = null): TenantSubscription
    {
        return $this->atomically($tenant, function () use ($tenant, $externalReference) {
            $current = $this->requireCurrent($tenant);
            $status = $current->effectiveStatus();

            if ($status === SubscriptionStatus::Suspended) {
                throw new InvalidSubscriptionTransition('Réactivez l\'abonnement avant de le renouveler.');
            }

            // Fin d'essai : la première période payée devient une nouvelle ligne (l'essai reste dans l'historique).
            if ($status === SubscriptionStatus::Trialing || ($status === SubscriptionStatus::Expired && $current->status === SubscriptionStatus::Trialing)) {
                $start = now();

                return $this->replaceCurrent($tenant, $current->plan, $current->cycle, SubscriptionStatus::Active, [
                    'starts_at' => $start,
                    'current_period_ends_at' => $current->cycle->endOfPeriodStartingAt($start),
                    'applied_price' => $current->plan->priceFor($current->cycle),
                    'external_reference' => $externalReference,
                ], 'activated', ['from_trial' => true]);
            }

            $from = $current->current_period_ends_at?->isFuture() ? $current->current_period_ends_at : now();

            $current->forceFill([
                'status' => SubscriptionStatus::Active,
                'current_period_ends_at' => $current->cycle->endOfPeriodStartingAt($from),
                'cancelled_at' => null,
                'external_reference' => $externalReference ?? $current->external_reference,
            ]);

            return $this->saveWithEvent($current, 'renewed', ['from' => $from->toIso8601String()]);
        });
    }

    public function suspend(Tenant $tenant, ?string $reason = null): TenantSubscription
    {
        $subscription = $this->atomically($tenant, function () use ($tenant, $reason) {
            $current = $this->requireCurrent($tenant);

            if (in_array($current->status, [SubscriptionStatus::Suspended, SubscriptionStatus::Replaced], true)) {
                throw new InvalidSubscriptionTransition('L\'abonnement est déjà suspendu.');
            }

            $current->forceFill(['status' => SubscriptionStatus::Suspended, 'suspended_at' => now()]);

            return $this->saveWithEvent($current, 'suspended', ['reason' => $reason, 'previous_status' => $current->getOriginal('status')->value]);
        });

        $this->notify($subscription, SubscriptionSuspended::class, "suspended:{$subscription->id}:".$subscription->suspended_at->timestamp);

        return $subscription;
    }

    /** Reprend l'abonnement suspendu : le statut est recalculé d'après les dates (jamais « actif » par défaut). */
    public function reactivate(Tenant $tenant): TenantSubscription
    {
        $subscription = $this->atomically($tenant, function () use ($tenant) {
            $current = $this->requireCurrent($tenant);

            if ($current->status !== SubscriptionStatus::Suspended) {
                throw new InvalidSubscriptionTransition('Seul un abonnement suspendu peut être réactivé.');
            }

            $previous = $this->lastEvent($current, 'suspended')['previous_status'] ?? null;
            $wasTrial = $previous === SubscriptionStatus::Trialing->value;

            $status = match (true) {
                $wasTrial => $current->trial_ends_at?->isFuture() ? SubscriptionStatus::Trialing : SubscriptionStatus::Expired,
                $current->current_period_ends_at === null || $current->current_period_ends_at->isFuture() => SubscriptionStatus::Active,
                default => SubscriptionStatus::Expired,
            };

            $current->forceFill(['status' => $status, 'suspended_at' => null]);

            return $this->saveWithEvent($current, 'reactivated', ['status' => $status->value]);
        });

        $this->notify($subscription, SubscriptionReactivated::class, "reactivated:{$subscription->id}:".now()->timestamp);

        return $subscription;
    }

    /** Annule : l'accès court jusqu'à la fin de la période payée (immédiat pour un essai ou si $immediately). */
    public function cancel(Tenant $tenant, bool $immediately = false): TenantSubscription
    {
        return $this->atomically($tenant, function () use ($tenant, $immediately) {
            $current = $this->requireCurrent($tenant);

            if (in_array($current->status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Replaced], true)) {
                throw new InvalidSubscriptionTransition('L\'abonnement est déjà annulé.');
            }

            $attributes = ['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now(), 'auto_renew' => false];

            if ($current->status === SubscriptionStatus::Trialing) {
                $attributes['trial_ends_at'] = now();
            } elseif ($immediately || $current->current_period_ends_at === null || ! $current->current_period_ends_at->isFuture()) {
                $attributes['current_period_ends_at'] = now();
            }

            return $this->saveWithEvent($current->forceFill($attributes), 'cancelled', ['immediately' => $immediately]);
        });
    }

    /** Marque expiré un essai ou un abonnement échu. Idempotent : sans effet s'il ne l'est pas ou l'est déjà. */
    public function expire(TenantSubscription $subscription): bool
    {
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        $expired = $this->atomically($tenant, function () use ($subscription) {
            $current = TenantSubscription::query()->whereKey($subscription->getKey())->first();

            if ($current === null || ! $current->isCurrent()
                || ! in_array($current->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)
                || $current->effectiveStatus() !== SubscriptionStatus::Expired) {
                return null;
            }

            $wasTrial = $current->status === SubscriptionStatus::Trialing;
            $current->forceFill(['status' => SubscriptionStatus::Expired]);
            $this->saveWithEvent($current, 'expired', ['was_trial' => $wasTrial]);

            return $current;
        });

        if ($expired === null) {
            return false;
        }

        $this->notify($expired, SubscriptionExpired::class, "expired:{$expired->id}:".($expired->trial_ends_at?->timestamp ?? $expired->current_period_ends_at?->timestamp));

        return true;
    }

    public function extendTrial(Tenant $tenant, int $days): TenantSubscription
    {
        if ($days < 1) {
            throw new InvalidSubscriptionTransition('La prolongation doit être d\'au moins 1 jour.');
        }

        return $this->atomically($tenant, function () use ($tenant, $days) {
            $current = $this->requireCurrent($tenant);

            // Essai en cours, ou essai échu non converti en abonnement payé.
            $isTrial = $current->status === SubscriptionStatus::Trialing
                || ($current->status === SubscriptionStatus::Expired && $current->trial_ends_at !== null && $current->current_period_ends_at === null);

            if (! $isTrial) {
                throw new InvalidSubscriptionTransition('Seul un essai peut être prolongé.');
            }

            $from = $current->trial_ends_at?->isFuture() ? $current->trial_ends_at : now();
            $current->forceFill(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $from->copy()->addDays($days)]);

            return $this->saveWithEvent($current, 'trial_extended', ['days' => $days]);
        });
    }

    /**
     * Renouvellement automatique : avec la passerelle manuelle, aucun prélèvement n'est possible ; l'option fait émettre
     * une facture de renouvellement (paiement en attente + notification) à l'échéance, par subscriptions:process.
     * Une passerelle qui sait prélever pourra s'en servir sans changer ce service.
     */
    public function setAutoRenew(Tenant $tenant, bool $enabled): TenantSubscription
    {
        return $this->atomically($tenant, function () use ($tenant, $enabled) {
            $current = $this->requireCurrent($tenant);
            $current->forceFill(['auto_renew' => $enabled]);

            return $this->saveWithEvent($current, $enabled ? 'auto_renew_on' : 'auto_renew_off');
        });
    }

    // ------------------------------------------------------------------ interne

    private function assertAssignable(SubscriptionPlan $plan): void
    {
        if (! $plan->is_active) {
            throw new InvalidSubscriptionTransition("L'offre {$plan->name} est désactivée : elle ne peut plus être attribuée.");
        }
    }

    private function hadTrial(Tenant $tenant): bool
    {
        return TenantSubscription::query()->where('tenant_id', $tenant->getKey())->whereNotNull('trial_ends_at')->exists();
    }

    private function requireCurrent(Tenant $tenant): TenantSubscription
    {
        return TenantSubscription::query()->with('plan')->whereNotNull('current_marker')->first()
            ?? throw new InvalidSubscriptionTransition('Ce tenant n\'a aucun abonnement : attribuez-lui une offre d\'abord.');
    }

    /**
     * Transaction + verrou sur la ligne du tenant + contexte tenant explicite (aucune fuite inter-tenant).
     * Les caches de décision sont vidés et le miroir hérité (tenants.plan) est rafraîchi.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function atomically(Tenant $tenant, Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($tenant, $callback) {
                Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

                return TenantContext::runAs((int) $tenant->getKey(), function () use ($tenant, $callback) {
                    $result = $callback();
                    $this->mirrorLegacyColumns($tenant);

                    return $result;
                });
            });
        } finally {
            SubscriptionAccessPolicy::flush();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $event
     */
    private function replaceCurrent(Tenant $tenant, SubscriptionPlan $plan, SubscriptionCycle $cycle, SubscriptionStatus $status, array $attributes, string $eventType, array $event = []): TenantSubscription
    {
        $old = TenantSubscription::query()->whereNotNull('current_marker')->first();

        // L'ancienne ligne libère le marqueur AVANT l'insertion de la nouvelle (unicité en base).
        $old?->forceFill(['status' => SubscriptionStatus::Replaced, 'ended_at' => now(), 'current_marker' => null])->save();

        $subscription = new TenantSubscription([
            'subscription_plan_id' => $plan->getKey(),
            'cycle' => $cycle,
            'status' => $status,
            'auto_renew' => false,
            ...$attributes,
        ]);
        $subscription->forceFill(['current_marker' => $tenant->getKey()]);

        return $this->saveWithEvent($subscription, $eventType, $event);
    }

    /** @param  array<string, mixed>  $event */
    private function saveWithEvent(TenantSubscription $subscription, string $type, array $event = []): TenantSubscription
    {
        $metadata = $subscription->metadata ?? [];
        $metadata['events'][] = ['type' => $type, 'at' => now()->toIso8601String(), 'by' => auth()->id()] + $event;
        $subscription->metadata = $metadata;
        $subscription->save();

        return $subscription->setRelation('plan', $subscription->plan()->first());
    }

    /** @return array<string, mixed> */
    private function lastEvent(TenantSubscription $subscription, string $type): array
    {
        return collect($subscription->metadata['events'] ?? [])->where('type', $type)->last() ?? [];
    }

    /** Copie en lecture seule vers les colonnes obsolètes (utile à un retour arrière), jamais relue. */
    private function mirrorLegacyColumns(Tenant $tenant): void
    {
        $current = TenantSubscription::query()->with('plan')->whereNotNull('current_marker')->first();

        Tenant::query()->whereKey($tenant->getKey())->update([
            'plan' => $current?->plan?->slug,
            'trial_ends_at' => $current?->status === SubscriptionStatus::Trialing ? $current->trial_ends_at : null,
        ]);
    }

    /** @param  class-string<SubscriptionNotification>  $notification */
    private function notify(TenantSubscription $subscription, string $notification, string $dedupKey): void
    {
        $this->notifier->send($subscription, $notification, $dedupKey, SubscriptionNotifier::contextFor($subscription));
    }
}
