<?php

namespace App\Livewire\Mobile\Subscription;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Subscriptions\FeatureCatalog;
use App\Services\Subscriptions\SubscriptionAccessPolicy;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Services\Subscriptions\SubscriptionManager;
use App\Services\Subscriptions\SubscriptionProration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
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
    /** Cycle affiché pour la comparaison des offres (le serveur recalcule tout à la demande). */
    public string $cycle = 'monthly';

    public ?string $message = null;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);
        $this->cycle = SubscriptionEntitlements::currentSubscription(TenantContext::requireId())?->cycle->value ?? 'monthly';
    }

    /** Demande de changement d'offre : le tenant vient de l'utilisateur connecté, le prorata est calculé ici. */
    public function requestChange(int $planId, string $cycle): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);
        $this->reset(['message', 'error']);

        try {
            $request = app(SubscriptionBilling::class)->requestChange(
                Tenant::query()->findOrFail(TenantContext::requireId()),
                SubscriptionPlan::query()->findOrFail($planId),
                SubscriptionCycle::tryFrom($cycle) ?? SubscriptionCycle::Monthly,
                auth()->user(),
            );

            $this->message = $request->status === SubscriptionChangeRequest::APPLIED
                ? 'Votre offre a été changée.'
                : 'Demande enregistrée. Réglez le montant indiqué : le changement s\'appliquera dès confirmation de votre paiement.';
        } catch (InvalidSubscriptionTransition $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancelRequest(): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);

        app(SubscriptionBilling::class)->cancelPendingRequests(Tenant::query()->findOrFail(TenantContext::requireId()));
        $this->message = 'Demande annulée.';
    }

    public function toggleAutoRenew(): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);

        $tenant = Tenant::query()->findOrFail(TenantContext::requireId());
        $current = app(SubscriptionManager::class)->current($tenant);

        if ($current) {
            app(SubscriptionManager::class)->setAutoRenew($tenant, ! $current->auto_renew);
        }
    }

    public function render()
    {
        $tenantId = TenantContext::requireId();
        $entitlements = app(SubscriptionEntitlements::class);
        $subscription = SubscriptionEntitlements::currentSubscription($tenantId);
        $plan = $subscription?->plan;

        $status = $subscription?->effectiveStatus();
        $days = $subscription?->daysRemaining();
        $cycle = SubscriptionCycle::tryFrom($this->cycle) ?? SubscriptionCycle::Monthly;
        $proration = app(SubscriptionProration::class);
        $catalog = SubscriptionPlan::active()->ordered()->get();
        $canBackup = $entitlements->hasFeature($tenantId, 'sauvegardes_dediees') && auth()->user()->hasRole('master');

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
            'catalog' => $catalog,
            'viewCycle' => $cycle,
            'quotes' => $subscription ? $catalog->mapWithKeys(fn (SubscriptionPlan $p) => [$p->id => $proration->quote($subscription, $p, $cycle)])->all() : [],
            'pendingRequest' => SubscriptionChangeRequest::query()->with('plan')->where('status', SubscriptionChangeRequest::PENDING)->latest('id')->first(),
            'pendingPayments' => SubscriptionPayment::query()->where('status', SubscriptionPayment::PENDING)->latest('id')->get(),
            'backups' => $canBackup ? collect(Storage::disk('local')->files(TenantContext::storagePath('backups')))->map(fn ($f) => basename($f))->sortDesc()->values()->all() : [],
        ]);
    }
}
