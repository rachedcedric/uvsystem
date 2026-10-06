<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionNotificationLog;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Notifications\Subscriptions\SubscriptionNotification;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Envoi des notifications d'abonnement aux masters du tenant.
 *
 *  - ENVOI SYNCHRONE (les notifications d'abonnement ne sont pas mises en file) : un échec est vu ici même,
 *    jamais perdu dans un worker.
 *  - Une ligne de journal à clé UNIQUE (type + abonnement + échéance) est créée avant l'envoi ; elle est
 *    « réclamée » par une mise à jour atomique : deux processus simultanés ne peuvent pas envoyer deux fois.
 *  - Un échec est CONSERVÉ (failed_at, last_error, attempts) et repris par retryPending() lors du prochain
 *    passage de subscriptions:process, jusqu'à notification_max_attempts ; il ne change jamais l'état métier.
 *  - Aucune méthode ne lève : l'état métier ne dépend jamais d'un envoi.
 */
class SubscriptionNotifier
{
    /** Échecs d'envoi constatés pendant la vie de cet objet (rapport de subscriptions:process). */
    public int $failures = 0;

    /**
     * @param  class-string<SubscriptionNotification>  $notification
     * @param  array<string, scalar|null>  $context
     * @return bool true si une notification a été envoyée maintenant
     */
    public function send(TenantSubscription $subscription, string $notification, string $dedupKey, array $context = []): bool
    {
        $tenantId = (int) $subscription->tenant_id;

        try {
            return TenantContext::runAs($tenantId, function () use ($subscription, $notification, $dedupKey, $context) {
                $log = SubscriptionNotificationLog::query()->where('dedup_key', $dedupKey)->first();

                if ($log === null) {
                    try {
                        $log = SubscriptionNotificationLog::create([
                            'tenant_subscription_id' => $subscription->getKey(),
                            'type' => $notification::type(),
                            'dedup_key' => $dedupKey,
                            'notification' => $notification,
                            'payload' => $context,
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        $log = SubscriptionNotificationLog::query()->where('dedup_key', $dedupKey)->firstOrFail();
                    }
                }

                return $this->deliver($log);
            });
        } catch (Throwable $e) {
            $this->failures++;
            Log::error('Échec d\'envoi de notification d\'abonnement', [
                'tenant_id' => $tenantId, 'subscription_id' => $subscription->getKey(), 'type' => $notification::type(), 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reprend les notifications échouées lors d'un passage ANTÉRIEUR à $before (appelé par subscriptions:process : un échec
     * constaté pendant le passage en cours n'est pas retenté dans la foulée). @return int nombre envoyées
     */
    public function retryPending(?\DateTimeInterface $before = null): int
    {
        $max = (int) config('subscriptions.notification_max_attempts', 5);

        $pending = TenantContext::withoutTenancy(fn () => SubscriptionNotificationLog::query()
            ->whereNull('sent_at')->whereNotNull('failed_at')->when($before, fn ($q) => $q->where('failed_at', '<', $before))->whereNotNull('notification')->where('attempts', '<', $max)->orderBy('id')->get());

        $sent = 0;
        foreach ($pending as $log) {
            try {
                $sent += TenantContext::runAs((int) $log->tenant_id, fn () => $this->deliver($log)) ? 1 : 0;
            } catch (Throwable $e) {
                $this->failures++;
                Log::error('Reprise de notification d\'abonnement en échec', ['log_id' => $log->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    private function deliver(SubscriptionNotificationLog $log): bool
    {
        $max = (int) config('subscriptions.notification_max_attempts', 5);

        if ($log->sent_at !== null || $log->attempts >= $max || ! is_a($log->notification, SubscriptionNotification::class, true)) {
            return false;
        }

        // Réclamation atomique : une seule exécution gagne (UPDATE conditionnel), l'autre reçoit 0 ligne.
        $claimed = SubscriptionNotificationLog::query()->whereKey($log->getKey())
            ->whereNull('sent_at')->where('attempts', '<', $max)
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(5)))
            ->update(['claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed === 0) {
            return false;
        }

        $recipients = TenantContext::withoutTenancy(fn () => User::query()->where('tenant_id', $log->tenant_id)->role('master')->get());

        if ($recipients->isEmpty()) {
            SubscriptionNotificationLog::query()->whereKey($log->getKey())->update(['claimed_at' => null, 'attempts' => DB::raw('attempts - 1')]);
            Log::warning('Notification d\'abonnement sans destinataire (aucun master)', ['tenant_id' => $log->tenant_id, 'type' => $log->type]);

            return false;
        }

        try {
            Notification::send($recipients, new ($log->notification)($log->payload ?? []));
        } catch (Throwable $e) {
            $this->failures++;
            SubscriptionNotificationLog::query()->whereKey($log->getKey())->update(['claimed_at' => null, 'failed_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::error('Échec d\'envoi de notification d\'abonnement', ['tenant_id' => $log->tenant_id, 'type' => $log->type, 'attempt' => $log->attempts + 1, 'error' => $e->getMessage()]);

            return false;
        }

        SubscriptionNotificationLog::query()->whereKey($log->getKey())->update(['sent_at' => now(), 'failed_at' => null, 'last_error' => null]);

        return true;
    }

    /** @return array<string, scalar|null> */
    public static function contextFor(TenantSubscription $subscription, array $extra = []): array
    {
        $subscription->loadMissing('plan');
        $end = $subscription->endsAt();

        return [
            'tenant_subscription_id' => $subscription->getKey(),
            'plan' => $subscription->plan?->name,
            'cycle' => $subscription->cycle->label(),
            'ends_at' => $end?->format('d/m/Y'),
            'days' => $subscription->daysRemaining(),
        ] + $extra;
    }
}
