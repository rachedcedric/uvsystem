<?php

namespace App\Livewire\Mobile\Subscription;

use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Services\Subscriptions\FeatureCatalog;
use App\Services\Subscriptions\SubscriptionAccessPolicy;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Abonnement du tenant courant, en lecture seule, pour ses master et admin. Le tenant vient UNIQUEMENT de
 * l'utilisateur connecté (TenantContext) : aucun identifiant n'est lu dans la requête, donc aucune donnée
 * d'une autre société n'est accessible. Reste accessible quand l'abonnement est expiré (c'est le but).
 */
#[Layout('components.layouts.mobile')]
#[Title('Mon abonnement')]
class Show extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);
    }

    public function render()
    {
        $tenantId = TenantContext::requireId();
        $entitlements = app(SubscriptionEntitlements::class);
        $subscription = SubscriptionEntitlements::currentSubscription($tenantId);
        $plan = $subscription?->plan;

        $status = $subscription?->effectiveStatus();
        $days = $subscription?->daysRemaining();

        return view('livewire.mobile.subscription.show', [
            'subscription' => $subscription,
            'plan' => $plan,
            'status' => $status,
            'days' => $days === null ? null : max(0, $days),
            'restricted' => SubscriptionAccessPolicy::isRestricted($tenantId),
            'warnExpiry' => $subscription !== null && $days !== null && $days <= 7 && in_array($status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true),
            'users' => ['used' => $entitlements->getUserUsage(), 'limit' => $entitlements->getUserLimit()],
            'agents' => ['used' => $entitlements->getAgentUsage(), 'limit' => $entitlements->getAgentLimit()],
            'features' => collect($plan?->resolvedFeatures() ?? [])->map(fn (string $f) => FeatureCatalog::label($f))->all(),
            'catalog' => SubscriptionPlan::active()->ordered()->get(),
        ]);
    }
}
