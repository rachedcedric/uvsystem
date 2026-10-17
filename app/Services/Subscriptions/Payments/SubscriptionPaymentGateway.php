<?php

namespace App\Services\Subscriptions\Payments;

use App\Models\SubscriptionPayment;
use App\Models\TenantSubscription;

/**
 * Contrat d'un moyen de paiement d'abonnement. La logique métier (SubscriptionBilling, SubscriptionManager)
 * ne dépend que de ce contrat : brancher CinetPay, PayDunya… plus tard = écrire une classe qui l'implémente
 * et la déclarer dans config/subscriptions.php, sans toucher au manager.
 *
 * RÈGLE : un paiement ne devient `confirmed` que sur une CONFIRMATION RÉELLE. Une redirection de retour, un
 * paramètre d'URL ou une donnée du navigateur n'en sont jamais une : $proof doit venir du prestataire
 * (webhook signé, appel d'API de vérification) ou, pour le mode manuel, d'une attestation d'un super admin.
 */
interface SubscriptionPaymentGateway
{
    /** Identifiant stable stocké dans subscription_payments.gateway (ex. « manual »). */
    public function name(): string;

    /** Montant réellement payable après application des contraintes monétaires de la passerelle. */
    public function payableAmount(int $amount): int;

    /**
     * Crée un paiement EN ATTENTE (jamais confirmé).
     *
     * @param  array<string, mixed>  $metadata  ex. ['purpose' => 'renewal'|'plan_change', 'change_request_id' => 12]
     */
    public function initiate(TenantSubscription $subscription, int $amount, array $metadata = []): SubscriptionPayment;

    /**
     * Confirme un paiement en attente d'après une preuve vérifiable.
     *
     * @param  array<string, mixed>  $proof
     *
     * @throws \InvalidArgumentException si la preuve est insuffisante : le paiement reste en attente
     */
    public function confirm(SubscriptionPayment $payment, array $proof): SubscriptionPayment;

    public function fail(SubscriptionPayment $payment, string $reason): SubscriptionPayment;

    /** URL de paiement externe validée par la passerelle, ou null pour un paiement hors ligne. */
    public function checkoutUrl(SubscriptionPayment $payment): ?string;
}
