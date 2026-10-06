<?php

namespace App\Services\Subscriptions\Payments;

use App\Models\SubscriptionPayment;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Paiement hors ligne (espèces, virement, Mobile Money encaissé à la main) : la « confirmation réelle » est
 * l'attestation d'un super admin de plateforme, qui doit saisir la référence de l'encaissement.
 * Sans super admin identifié ET sans référence, le paiement reste en attente.
 */
class ManualSubscriptionPaymentGateway implements SubscriptionPaymentGateway
{
    public const NAME = 'manual';

    public function name(): string
    {
        return self::NAME;
    }

    public function initiate(TenantSubscription $subscription, int $amount): SubscriptionPayment
    {
        return TenantContext::runAs((int) $subscription->tenant_id, fn () => SubscriptionPayment::create([
            'tenant_subscription_id' => $subscription->getKey(),
            'amount' => $amount,
            'currency' => 'XOF',
            'status' => SubscriptionPayment::PENDING,
            'gateway' => self::NAME,
        ]));
    }

    /** @param  array{reference?: string, confirmed_by?: User}  $proof */
    public function confirm(SubscriptionPayment $payment, array $proof): SubscriptionPayment
    {
        $admin = $proof['confirmed_by'] ?? null;
        $reference = trim((string) ($proof['reference'] ?? ''));

        if (! $admin instanceof User || ! $admin->isSuperAdmin()) {
            throw new InvalidArgumentException('Seul un super admin de la plateforme peut attester un paiement manuel.');
        }

        if ($reference === '') {
            throw new InvalidArgumentException('La référence de l\'encaissement est obligatoire.');
        }

        return TenantContext::runAs((int) $payment->tenant_id, function () use ($payment, $admin, $reference) {
            $payment->forceFill([
                'status' => SubscriptionPayment::CONFIRMED,
                'provider_reference' => $reference,
                'confirmed_at' => now(),
                'confirmed_by' => $admin->getKey(),
                'failure_reason' => null,
            ])->save();

            return $payment;
        });
    }

    public function fail(SubscriptionPayment $payment, string $reason): SubscriptionPayment
    {
        return TenantContext::runAs((int) $payment->tenant_id, function () use ($payment, $reason) {
            $payment->forceFill(['status' => SubscriptionPayment::FAILED, 'failure_reason' => $reason])->save();

            return $payment;
        });
    }
}
