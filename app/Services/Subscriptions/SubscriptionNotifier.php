<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionNotificationLog;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Notifications\Subscriptions\SubscriptionNotification;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Envoi des notifications d'abonnement aux masters du tenant.
 *  - anti-doublon : une ligne de journal à clé UNIQUE est réservée AVANT l'envoi ; une seconde tentative
 *    (cron exécuté deux fois, double clic) échoue sur la clé et n'envoie rien ;
 *  - un échec d'envoi ne change JAMAIS l'état métier : il est journalisé, la réservation est levée
 *    (le prochain passage pourra réessayer) et la méthode renvoie false — elle ne lève jamais.
 * Limite connue : les notifications sont mises en file ; un échec survenant plus tard dans le worker n'est
 * vu que par la table des jobs échoués, pas ici.
 */
class SubscriptionNotifier
{
    /**
     * @param  class-string<SubscriptionNotification>  $notification
     * @param  array<string, scalar|null>  $context
     * @return bool true si une notification a été envoyée
     */
    public function send(TenantSubscription $subscription, string $notification, string $dedupKey, array $context = []): bool
    {
        $tenantId = (int) $subscription->tenant_id;

        try {
            return TenantContext::runAs($tenantId, function () use ($subscription, $notification, $dedupKey, $context, $tenantId) {
                $recipients = TenantContext::withoutTenancy(
                    fn () => User::query()->where('tenant_id', $tenantId)->role('master')->get()
                );

                if ($recipients->isEmpty()) {
                    Log::warning('Notification d\'abonnement sans destinataire (aucun master)', ['tenant_id' => $tenantId, 'type' => $notification::type()]);

                    return false;
                }

                try {
                    $log = SubscriptionNotificationLog::create([
                        'tenant_subscription_id' => $subscription->getKey(),
                        'type' => $notification::type(),
                        'dedup_key' => $dedupKey,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return false; // déjà envoyée
                }

                try {
                    Notification::send($recipients, new $notification($context));
                } catch (Throwable $e) {
                    $log->delete();
                    throw $e;
                }

                $log->forceFill(['sent_at' => now()])->save();

                return true;
            });
        } catch (Throwable $e) {
            Log::error('Échec d\'envoi de notification d\'abonnement', [
                'tenant_id' => $tenantId,
                'subscription_id' => $subscription->getKey(),
                'type' => $notification::type(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
