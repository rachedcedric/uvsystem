<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Subscriptions\PaymentConfirmed;
use App\Notifications\Subscriptions\PaymentFailed;
use App\Notifications\Subscriptions\SubscriptionChangeRequested;
use App\Services\Subscriptions\Payments\ManualSubscriptionPaymentGateway;
use App\Services\Subscriptions\Payments\SubscriptionPaymentGateway;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Relie un paiement à l'abonnement, sans connaître de prestataire : tout passe par SubscriptionPaymentGateway.
 * L'abonnement n'est prolongé QUE lorsque la passerelle a confirmé le paiement ; un paiement en échec ou
 * resté en attente ne change rien à l'abonnement.
 */
class SubscriptionBilling
{
    public function __construct(private readonly SubscriptionManager $manager, private readonly SubscriptionNotifier $notifier) {}

    /** Passerelles disponibles (config/subscriptions.php), « manual » par défaut. */
    public function gateway(?string $name = null): SubscriptionPaymentGateway
    {
        $name ??= ManualSubscriptionPaymentGateway::NAME;
        $class = config("subscriptions.gateways.{$name}") ?? ($name === ManualSubscriptionPaymentGateway::NAME ? ManualSubscriptionPaymentGateway::class : null);

        return $class ? app($class) : throw new InvalidArgumentException("Passerelle de paiement inconnue : {$name}.");
    }

    /** Prix à régler pour la prochaine période de l'abonnement courant (prix actuel de l'offre pour son cycle). */
    public function amountDue(Tenant $tenant): int
    {
        $subscription = $this->manager->current($tenant) ?? throw new InvalidSubscriptionTransition('Aucun abonnement à régler.');

        return $subscription->plan->priceFor($subscription->cycle);
    }

    public function request(Tenant $tenant, ?string $gateway = null): SubscriptionPayment
    {
        $subscription = $this->manager->current($tenant) ?? throw new InvalidSubscriptionTransition('Aucun abonnement à régler.');

        return $this->gateway($gateway)->initiate($subscription, $this->amountDue($tenant), ['purpose' => 'renewal']);
    }

    // ------------------------------------------------------------------ changement d'offre à la demande du tenant

    /**
     * Demande de changement d'offre faite par un master/admin du tenant. Le prorata est calculé par le serveur ;
     * s'il n'y a rien à payer (rétrogradation, essai) le changement est appliqué tout de suite, sinon un paiement
     * EN ATTENTE du montant dû est créé et le changement s'applique à sa confirmation réelle (passerelle ou super admin).
     * Une seule demande en attente par tenant : la précédente est annulée.
     */
    public function requestChange(Tenant $tenant, SubscriptionPlan $plan, SubscriptionCycle $cycle, ?User $by = null): SubscriptionChangeRequest
    {
        if (! $plan->is_active) {
            throw new InvalidSubscriptionTransition("L'offre {$plan->name} n'est plus disponible.");
        }

        $current = $this->manager->current($tenant) ?? throw new InvalidSubscriptionTransition('Aucun abonnement : contactez la plateforme.');

        if (! in_array($current->effectiveStatus(), [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            throw new InvalidSubscriptionTransition('Votre abonnement n\'est plus actif : réglez son renouvellement pour le réactiver, puis changez d\'offre.');
        }

        if ($current->subscription_plan_id === $plan->id && $current->cycle === $cycle) {
            throw new InvalidSubscriptionTransition('Vous êtes déjà sur cette offre et ce cycle.');
        }

        $quote = app(SubscriptionProration::class)->quote($current, $plan, $cycle);

        $this->cancelPendingRequests($tenant);

        $request = TenantContext::runAs((int) $tenant->getKey(), function () use ($current, $plan, $cycle, $by, $quote) {
            return SubscriptionChangeRequest::create([
                'tenant_subscription_id' => $current->getKey(),
                'requested_plan_id' => $plan->getKey(),
                'requested_cycle' => $cycle,
                'status' => SubscriptionChangeRequest::PENDING,
                'requested_by' => $by?->getKey(),
                'quote' => $quote,
            ]);
        });

        if ($quote['amount_due'] === 0) {
            return $this->applyChange($request);
        }

        $this->gateway()->initiate($current, $quote['amount_due'], ['purpose' => 'plan_change', 'change_request_id' => $request->id]);
        $this->notifier->send($current, SubscriptionChangeRequested::class, "change_requested:{$request->id}",
            SubscriptionNotifier::contextFor($current, ['plan' => $plan->name, 'cycle' => $cycle->label(), 'amount' => $quote['amount_due']]));

        return $request;
    }

    /**
     * Annule les demandes en attente du tenant ET leurs paiements en attente : un paiement ne doit jamais pouvoir être
     * confirmé pour une demande qui n'est plus valable (l'argent serait encaissé sans que rien ne s'applique).
     */
    public function cancelPendingRequests(Tenant $tenant): void
    {
        TenantContext::runAs((int) $tenant->getKey(), function () {
            SubscriptionChangeRequest::query()->where('status', SubscriptionChangeRequest::PENDING)->get()->each(function (SubscriptionChangeRequest $old) {
                $old->forceFill(['status' => SubscriptionChangeRequest::CANCELLED, 'resolved_at' => now()])->save();
                $this->cancelPendingPaymentsOf($old);
            });
        });
    }

    private function cancelPendingPaymentsOf(SubscriptionChangeRequest $request): void
    {
        SubscriptionPayment::query()->where('status', SubscriptionPayment::PENDING)->get()
            ->filter(fn (SubscriptionPayment $p) => ($p->metadata['change_request_id'] ?? null) === $request->id)
            ->each(fn (SubscriptionPayment $p) => $p->forceFill(['status' => SubscriptionPayment::CANCELLED])->save());
    }

    /** Applique une demande en attente (paiement confirmé ou rien à payer). Idempotent. */
    public function applyChange(SubscriptionChangeRequest $request): SubscriptionChangeRequest
    {
        $tenant = Tenant::query()->findOrFail($request->tenant_id);

        $fresh = TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::query()->with('plan')->find($request->getKey()));

        if ($fresh->status !== SubscriptionChangeRequest::PENDING) {
            return $fresh;
        }

        $this->manager->changePlan($tenant, $fresh->plan, $fresh->requested_cycle);

        TenantContext::runAs((int) $tenant->getKey(), fn () => $fresh->forceFill(['status' => SubscriptionChangeRequest::APPLIED, 'resolved_at' => now()])->save());

        return $fresh;
    }

    public function rejectChange(SubscriptionChangeRequest $request, User $admin, ?string $note = null): SubscriptionChangeRequest
    {
        abort_unless($admin->isSuperAdmin(), 403);

        if ($request->status !== SubscriptionChangeRequest::PENDING) {
            throw new InvalidSubscriptionTransition('Cette demande est déjà traitée.');
        }

        TenantContext::runAs((int) $request->tenant_id, function () use ($request, $admin, $note) {
            $request->forceFill(['status' => SubscriptionChangeRequest::REJECTED, 'resolved_by' => $admin->getKey(), 'resolved_at' => now(), 'note' => $note])->save();

            // Le paiement en attente lié à la demande est annulé : plus rien à encaisser.
            $this->cancelPendingPaymentsOf($request);
        });

        return $request->refresh();
    }

    /**
     * Confirme un paiement d'après la preuve fournie, puis renouvelle l'abonnement. Idempotent : confirmer
     * deux fois le même paiement ne prolonge l'abonnement qu'une fois.
     *
     * @param  array<string, mixed>  $proof
     */
    public function confirm(SubscriptionPayment $payment, array $proof): SubscriptionPayment
    {
        $gateway = $this->gateway($payment->gateway);
        $tenant = Tenant::query()->findOrFail($payment->tenant_id);

        $confirmedNow = DB::transaction(function () use ($payment, $proof, $gateway) {
            $fresh = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->lockForUpdate()->find($payment->getKey()));

            if ($fresh->status === SubscriptionPayment::CONFIRMED) {
                return false;
            }

            if ($fresh->status !== SubscriptionPayment::PENDING) {
                throw new InvalidSubscriptionTransition('Seul un paiement en attente peut être confirmé.');
            }

            // Un paiement de changement d'offre n'est confirmable que si sa demande est encore valable.
            if (($fresh->metadata['purpose'] ?? 'renewal') === 'plan_change') {
                $request = TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::query()->find($fresh->metadata['change_request_id'] ?? 0));

                if ($request === null || $request->status !== SubscriptionChangeRequest::PENDING) {
                    throw new InvalidSubscriptionTransition('La demande de changement liée à ce paiement n\'est plus valable.');
                }
            }

            $gateway->confirm($fresh, $proof);

            return true;
        });

        $payment->refresh();

        if (! $confirmedNow) {
            return $payment;
        }

        // Un paiement de changement d'offre applique la demande ; un paiement de renouvellement prolonge la période.
        if (($payment->metadata['purpose'] ?? 'renewal') === 'plan_change') {
            $request = TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::query()->findOrFail($payment->metadata['change_request_id']));
            $this->applyChange($request);
            $subscription = $this->manager->current($tenant);
        } else {
            $subscription = $this->manager->renew($tenant, $payment->provider_reference);
        }

        $this->notifier->send($subscription, PaymentConfirmed::class, "payment_confirmed:{$payment->id}",
            SubscriptionNotifier::contextFor($subscription, ['amount' => $payment->amount]));

        return $payment;
    }

    /** Marque un paiement en échec : l'abonnement n'est PAS modifié. */
    public function fail(SubscriptionPayment $payment, string $reason): SubscriptionPayment
    {
        if ($payment->status !== SubscriptionPayment::PENDING) {
            throw new InvalidSubscriptionTransition('Seul un paiement en attente peut échouer.');
        }

        $this->gateway($payment->gateway)->fail($payment, $reason);

        $tenant = Tenant::query()->findOrFail($payment->tenant_id);
        if ($subscription = $this->manager->current($tenant)) {
            $this->notifier->send($subscription, PaymentFailed::class, "payment_failed:{$payment->id}",
                SubscriptionNotifier::contextFor($subscription, ['amount' => $payment->amount]));
        }

        return $payment->refresh();
    }
}
