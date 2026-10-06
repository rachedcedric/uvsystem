<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Notifications\Subscriptions\SubscriptionExpiring;
use App\Notifications\Subscriptions\SubscriptionRenewalDue;
use App\Notifications\Subscriptions\TrialExpiring;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Traitement planifié (commande subscriptions:process) : expire les essais et abonnements échus et envoie les
 * avertissements J-7 / J-3 / J-1. Sûr à relancer : l'expiration est idempotente (sans effet sur une ligne déjà
 * expirée) et chaque avertissement a une clé unique (abonnement + échéance + palier). Une erreur sur un tenant
 * est journalisée et n'empêche pas le traitement des autres.
 */
class SubscriptionLifecycleProcessor
{
    public function __construct(
        private readonly SubscriptionManager $manager,
        private readonly SubscriptionNotifier $notifier,
        private readonly SubscriptionBilling $billing,
    ) {}

    /** @return array{expired: int, warned: int, errors: int, retried: int, notification_failures: int, invoiced: int} */
    public function run(): array
    {
        $report = ['expired' => 0, 'warned' => 0, 'errors' => 0, 'retried' => 0, 'notification_failures' => 0, 'invoiced' => 0];
        $this->notifier->failures = 0;
        $startedAt = now();

        $subscriptions = TenantContext::withoutTenancy(
            fn () => TenantSubscription::query()->with('plan')->whereNotNull('current_marker')->orderBy('id')->get()
        );

        $suspended = Tenant::query()->where('status', Tenant::STATUS_SUSPENDED)->pluck('id')->flip();

        foreach ($subscriptions as $subscription) {
            if ($suspended->has($subscription->tenant_id)) {
                continue; // tenant suspendu : rien n'est envoyé ni modifié
            }

            try {
                $wasPaidPeriod = $subscription->status === SubscriptionStatus::Active && $subscription->auto_renew;

                if ($this->manager->expire($subscription)) {
                    $report['expired']++;
                    // Renouvellement automatique : facture de renouvellement (paiement en attente + notification).
                    $report['invoiced'] += $wasPaidPeriod && $this->invoiceRenewal($subscription) ? 1 : 0;

                    continue;
                }

                if ($this->warn($subscription)) {
                    $report['warned']++;
                }
            } catch (Throwable $e) {
                $report['errors']++;
                Log::error('subscriptions:process — échec sur un abonnement', [
                    'subscription_id' => $subscription->id,
                    'tenant_id' => $subscription->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Reprise des notifications restées en échec lors des passages précédents.
        $report['retried'] = $this->notifier->retryPending($startedAt);
        $report['notification_failures'] = $this->notifier->failures;

        return $report;
    }

    private function invoiceRenewal(TenantSubscription $subscription): bool
    {
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);
        $payment = $this->billing->request($tenant);

        return $this->notifier->send($subscription, SubscriptionRenewalDue::class, "renewal_due:{$subscription->id}:{$subscription->current_period_ends_at->timestamp}",
            SubscriptionNotifier::contextFor($subscription, ['amount' => $payment->amount]));
    }

    private function warn(TenantSubscription $subscription): bool
    {
        // Seuls les essais et abonnements actifs ont une échéance à annoncer (un annulé ou suspendu l'a déjà décidée).
        if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            return false;
        }

        $end = $subscription->endsAt();
        $days = $subscription->daysRemaining();

        if ($end === null || $days === null || $days < 1) {
            return false;
        }

        // Palier le plus proche franchi : J-1, sinon J-3, sinon J-7 (jamais deux paliers à la fois).
        $threshold = collect(config('subscriptions.warning_days', [7, 3, 1]))->sort()->first(fn (int $d) => $days <= $d);

        if ($threshold === null) {
            return false;
        }

        $notification = $subscription->isTrial() ? TrialExpiring::class : SubscriptionExpiring::class;
        $key = "expiring:{$subscription->id}:{$end->format('Y-m-d')}:D{$threshold}";

        return $this->notifier->send($subscription, $notification, $key, SubscriptionNotifier::contextFor($subscription, ['threshold' => $threshold]));
    }
}
