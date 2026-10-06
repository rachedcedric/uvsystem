<?php

namespace App\Services\Subscriptions;

use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Notifications\Subscriptions\PaymentConfirmed;
use App\Notifications\Subscriptions\PaymentFailed;
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

        return $this->gateway($gateway)->initiate($subscription, $this->amountDue($tenant));
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

            $gateway->confirm($fresh, $proof);

            return true;
        });

        $payment->refresh();

        if (! $confirmedNow) {
            return $payment;
        }

        $subscription = $this->manager->renew($tenant, $payment->provider_reference);

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
