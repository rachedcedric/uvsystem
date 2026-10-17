<?php

namespace App\Filament\Pages;

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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

class SubscriptionPage extends Page
{
    use OpensPaymentPage;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Mon abonnement';

    protected static ?string $title = 'Mon abonnement';

    protected static ?string $navigationGroup = 'Mon Compte';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'mon-abonnement';

    protected static string $view = 'filament.pages.subscription-page';

    public string $cycle = 'monthly';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', TenantSubscription::class) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->cycle = SubscriptionEntitlements::currentSubscription(TenantContext::requireId())?->cycle->value
            ?? SubscriptionCycle::Monthly->value;
    }

    public function setCycle(string $cycle): void
    {
        $this->authorizeTenantManager();
        $this->cycle = (SubscriptionCycle::tryFrom($cycle) ?? SubscriptionCycle::Monthly)->value;
    }

    public function requestChange(int $planId): void
    {
        $this->authorizeTenantManager();

        try {
            $request = app(SubscriptionBilling::class)->requestChange(
                $this->tenant(),
                SubscriptionPlan::query()->active()->findOrFail($planId),
                SubscriptionCycle::tryFrom($this->cycle) ?? SubscriptionCycle::Monthly,
                auth()->user(),
            );

            Notification::make()
                ->title($request->status === SubscriptionChangeRequest::APPLIED
                    ? 'Offre mise à jour'
                    : 'Demande enregistrée')
                ->body($request->status === SubscriptionChangeRequest::APPLIED
                    ? 'Votre nouvelle offre est active.'
                    : 'Le changement sera appliqué après confirmation du paiement.')
                ->success()
                ->send();

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
        } catch (InvalidSubscriptionTransition $exception) {
            Notification::make()->title('Changement impossible')->body($exception->getMessage())->danger()->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Paiement indisponible')->body('Le service Jèko ne répond pas. Réessayez dans quelques instants.')->danger()->send();
        }
    }

    public function renew(): void
    {
        $this->authorizeTenantManager();

        try {
            $this->redirectToPayment(app(SubscriptionBilling::class)->request($this->tenant()));
        } catch (InvalidSubscriptionTransition $exception) {
            Notification::make()->title('Renouvellement impossible')->body($exception->getMessage())->danger()->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Paiement indisponible')->body('Le service Jèko ne répond pas. Réessayez dans quelques instants.')->danger()->send();
        }
    }

    public function pay(int $paymentId): void
    {
        $this->authorizeTenantManager();
        $this->redirectToPayment(SubscriptionPayment::query()
            ->where('status', SubscriptionPayment::PENDING)
            ->findOrFail($paymentId));
    }

    public function cancelRequest(): void
    {
        $this->authorizeTenantManager();

        try {
            app(SubscriptionBilling::class)->cancelPendingRequests($this->tenant());
            Notification::make()->title('Demande annulée')->success()->send();
        } catch (InvalidSubscriptionTransition $exception) {
            Notification::make()->title('Annulation impossible')->body($exception->getMessage())->danger()->send();
        }
    }

    public function toggleAutoRenew(): void
    {
        $this->authorizeTenantManager();
        $tenant = $this->tenant();
        $subscription = app(SubscriptionManager::class)->current($tenant);

        if ($subscription === null) {
            Notification::make()->title('Aucun abonnement actif')->danger()->send();

            return;
        }

        app(SubscriptionManager::class)->setAutoRenew($tenant, ! $subscription->auto_renew);
        Notification::make()
            ->title($subscription->auto_renew ? 'Renouvellement automatique désactivé' : 'Renouvellement automatique activé')
            ->success()
            ->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $tenantId = TenantContext::requireId();
        $entitlements = app(SubscriptionEntitlements::class);
        $subscription = SubscriptionEntitlements::currentSubscription($tenantId);
        $plan = $subscription?->plan;
        $status = $subscription?->effectiveStatus();
        $days = $subscription?->daysRemaining();
        $cycle = SubscriptionCycle::tryFrom($this->cycle) ?? SubscriptionCycle::Monthly;
        $catalog = SubscriptionPlan::query()->active()->ordered()->get();
        $storageLimit = $entitlements->getStorageLimitMb();
        $storageUsedMb = round($entitlements->getStorageUsageBytes() / 1048576, 1);

        return [
            'subscription' => $subscription,
            'plan' => $plan,
            'status' => $status,
            'days' => $days === null ? null : max(0, $days),
            'restricted' => SubscriptionAccessPolicy::isRestricted($tenantId),
            'warnExpiry' => $subscription !== null && $days !== null && $days <= 7
                && in_array($status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true),
            'users' => ['used' => $entitlements->getUserUsage(), 'limit' => $entitlements->getUserLimit()],
            'agents' => ['used' => $entitlements->getAgentUsage(), 'limit' => $entitlements->getAgentLimit()],
            'storage' => ['used' => $storageUsedMb, 'limit' => $storageLimit],
            'features' => collect($plan?->resolvedFeatures() ?? [])->map(fn (string $feature) => FeatureCatalog::label($feature))->all(),
            'catalog' => $catalog,
            'viewCycle' => $cycle,
            'quotes' => $subscription
                ? $catalog->mapWithKeys(fn (SubscriptionPlan $offer) => [
                    $offer->id => app(SubscriptionProration::class)->quote($subscription, $offer, $cycle),
                ])->all()
                : [],
            'pendingRequest' => SubscriptionChangeRequest::query()
                ->with('plan')->where('status', SubscriptionChangeRequest::PENDING)->latest('id')->first(),
            'pendingPayments' => SubscriptionPayment::query()
                ->where('status', SubscriptionPayment::PENDING)->latest('id')->get(),
        ];
    }

    private function authorizeTenantManager(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function tenant(): Tenant
    {
        $tenant = auth()->user()?->tenant;

        abort_unless($tenant !== null && (int) $tenant->getKey() === TenantContext::requireId(), 403);

        return $tenant;
    }

    private function redirectToPayment(SubscriptionPayment $payment): void
    {
        $url = app(SubscriptionBilling::class)->checkoutUrl($payment);

        if ($url === null) {
            Notification::make()->title('Paiement hors ligne')->body('Cette demande doit être confirmée par la plateforme.')->warning()->send();

            return;
        }

        $this->openPaymentPage($url);
    }
}
