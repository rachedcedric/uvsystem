<?php

namespace App\Livewire\Mobile\Subscription;

use App\Enums\JekoPaymentMethod;
use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Livewire\Concerns\OpensPaymentPage;
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
use Throwable;

/**
 * Abonnement du tenant courant, en lecture seule, pour ses master et admin. Le tenant vient UNIQUEMENT de
 * l'utilisateur connecté (TenantContext) : aucun identifiant n'est lu dans la requête, donc aucune donnée
 * d'une autre société n'est accessible. Reste accessible quand l'abonnement est expiré (c'est le but).
 */
#[Layout('components.layouts.mobile')]
#[Title('Mon abonnement')]
class Show extends Component
{
    use OpensPaymentPage;

    /** Cycle affiché pour la comparaison des offres (le serveur recalcule tout à la demande). */
    public string $cycle = 'monthly';

    public ?string $paymentMethod = null;

    public ?string $message = null;

    public ?string $error = null;

    /**
     * Messages ponctuels : ils vivent le temps d'une réponse. Sans cela, « Demande enregistrée, réglez le montant » resterait
     * affiché après un rafraîchissement automatique alors que la demande a pu être annulée entre-temps (paiement expiré).
     */
    public function hydrate(): void
    {
        $this->message = null;
        $this->error = null;
    }

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
            $tenant = Tenant::query()->findOrFail(TenantContext::requireId());
            $plan = SubscriptionPlan::query()->findOrFail($planId);
            $selectedCycle = SubscriptionCycle::tryFrom($cycle) ?? SubscriptionCycle::Monthly;
            $current = app(SubscriptionManager::class)->current($tenant);
            $amountDue = $current === null ? 0 : app(SubscriptionProration::class)->quote($current, $plan, $selectedCycle)['amount_due'];

            $request = app(SubscriptionBilling::class)->requestChange(
                $tenant,
                $plan,
                $selectedCycle,
                auth()->user(),
                null,
                $this->paymentMethodFor($amountDue),
            );

            $this->message = $request->status === SubscriptionChangeRequest::APPLIED
                ? 'Votre offre a été changée.'
                : 'Demande enregistrée. Réglez le montant indiqué : le changement s\'appliquera dès confirmation de votre paiement.';

            if ($request->status === SubscriptionChangeRequest::PENDING) {
                $payment = SubscriptionPayment::query()
                    ->where('status', SubscriptionPayment::PENDING)
                    ->latest('id')
                    ->get()
                    ->first(fn (SubscriptionPayment $item) => ($item->metadata['change_request_id'] ?? null) === $request->id);

                if ($payment !== null) {
                    $this->redirectToPayment($payment);
                }
            }
        } catch (InvalidSubscriptionTransition $e) {
            $this->fail($e->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('Le service Jèko ne répond pas. Réessayez dans quelques instants.');
        }
    }

    public function renew(): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);
        $this->reset(['message', 'error']);

        try {
            $tenant = Tenant::query()->findOrFail(TenantContext::requireId());
            $this->redirectToPayment(app(SubscriptionBilling::class)->request(
                $tenant,
                null,
                $this->paymentMethodFor(app(SubscriptionBilling::class)->amountDue($tenant)),
            ));
        } catch (InvalidSubscriptionTransition $exception) {
            $this->fail($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('Le service Jèko ne répond pas. Réessayez dans quelques instants.');
        }
    }

    public function pay(int $paymentId): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);
        $this->redirectToPayment(SubscriptionPayment::query()
            ->where('status', SubscriptionPayment::PENDING)
            ->findOrFail($paymentId));
    }

    public function cancelRequest(): void
    {
        abort_unless(auth()->user()?->can('viewAny', TenantSubscription::class), 403);
        $this->reset(['message', 'error']);

        try {
            app(SubscriptionBilling::class)->cancelPendingRequests(Tenant::query()->findOrFail(TenantContext::requireId()));
            $this->message = 'Demande annulée.';
            $this->dispatch('m-toast', message: 'Demande annulée.');
        } catch (InvalidSubscriptionTransition $exception) {
            $this->fail($exception->getMessage());
        }
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
            'paymentMethods' => collect(JekoPaymentMethod::cases())->mapWithKeys(fn (JekoPaymentMethod $method) => [$method->value => $method->label()])->all(),
            'backups' => $canBackup ? collect(Storage::disk('local')->files(TenantContext::storagePath('backups')))->map(fn ($f) => basename($f))->sortDesc()->values()->all() : [],
        ]);
    }

    /** Erreur affichée dans l'encadré (persistant) ET en toast : le bouton touché peut être très loin de l'encadré. */
    private function fail(string $message): void
    {
        $this->error = $message;
        $this->dispatch('m-toast', message: $message, type: 'error');
    }

    private function paymentMethodFor(int $amount): ?string
    {
        if ($amount <= 0 || config('subscriptions.default_gateway') !== 'jeko') {
            return null;
        }

        return JekoPaymentMethod::tryFrom((string) $this->paymentMethod)?->value
            ?? throw new InvalidSubscriptionTransition('Choisissez votre moyen de paiement.');
    }

    private function redirectToPayment(SubscriptionPayment $payment): void
    {
        $url = app(SubscriptionBilling::class)->checkoutUrl($payment);

        if ($url === null) {
            return;
        }

        $this->openPaymentPage($url);
    }
}
