<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController
 *
 * Gère les webhooks entrants des services externes (Resend, Mailtrap, etc.)
 * pour tracker les événements d'envoi d'emails et mettre à jour le système.
 */
class WebhookController extends Controller
{
    /**
     * Traite les webhooks Resend
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resend(Request $request)
    {
        try {
            // Vérifier la signature du webhook
            if (! $this->verifyResendSignature($request)) {
                Log::warning('Resend webhook signature verification failed', [
                    'ip' => $request->ip(),
                    'headers' => array_intersect_key($request->headers->all(), array_flip(['user-agent', 'content-type', 'svix-id', 'svix-timestamp'])),
                ]);

                return response()->json([
                    'error' => 'Invalid signature',
                ], 401);
            }

            // Récupérer les données du webhook
            $payload = $request->all();
            $eventType = $payload['type'] ?? 'unknown';

            // Logger l'événement
            Log::info('Resend webhook received', [
                'type' => $eventType,
                'payload' => $payload,
            ]);

            // Traiter selon le type d'événement
            switch ($eventType) {
                case 'email.sent':
                    $this->handleEmailSent($payload);
                    break;

                case 'email.delivered':
                    $this->handleEmailDelivered($payload);
                    break;

                case 'email.delivery_delayed':
                    $this->handleEmailDelayed($payload);
                    break;

                case 'email.bounced':
                    $this->handleEmailBounced($payload);
                    break;

                case 'email.complained':
                    $this->handleEmailComplained($payload);
                    break;

                case 'email.opened':
                    $this->handleEmailOpened($payload);
                    break;

                case 'email.clicked':
                    $this->handleEmailClicked($payload);
                    break;

                default:
                    Log::info('Resend webhook: Unknown event type', [
                        'type' => $eventType,
                    ]);
            }

            // Enregistrer dans ActivityLog
            activity()
                ->withProperties([
                    'service' => 'resend',
                    'event_type' => $eventType,
                    'email_id' => $payload['data']['email_id'] ?? null,
                    'to' => $payload['data']['to'] ?? null,
                    'subject' => $payload['data']['subject'] ?? null,
                ])
                ->log("Webhook Resend: {$eventType}");

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error processing Resend webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Traite les webhooks Mailtrap
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mailtrap(Request $request)
    {
        try {
            // Vérifier la signature du webhook (Mailtrap)
            if (! $this->verifyMailtrapSignature($request)) {
                Log::warning('Mailtrap webhook signature verification failed', [
                    'ip' => $request->ip(),
                    'headers' => array_intersect_key($request->headers->all(), array_flip(['user-agent', 'content-type', 'svix-id', 'svix-timestamp'])),
                ]);

                return response()->json([
                    'error' => 'Invalid signature',
                ], 401);
            }

            // Récupérer les données du webhook
            $payload = $request->all();
            $eventType = $payload['type'] ?? 'unknown';

            // Logger l'événement
            Log::info('Mailtrap webhook received', [
                'type' => $eventType,
                'payload' => $payload,
            ]);

            // Traiter selon le type d'événement
            switch ($eventType) {
                case 'delivery':
                    $this->handleMailtrapDelivery($payload);
                    break;

                case 'open':
                    $this->handleMailtrapOpen($payload);
                    break;

                case 'click':
                    $this->handleMailtrapClick($payload);
                    break;

                case 'bounce':
                    $this->handleMailtrapBounce($payload);
                    break;

                case 'spam':
                    $this->handleMailtrapSpam($payload);
                    break;

                default:
                    Log::info('Mailtrap webhook: Unknown event type', [
                        'type' => $eventType,
                    ]);
            }

            // Enregistrer dans ActivityLog
            activity()
                ->withProperties([
                    'service' => 'mailtrap',
                    'event_type' => $eventType,
                    'message_id' => $payload['message_id'] ?? null,
                    'to_email' => $payload['to_email'] ?? null,
                ])
                ->log("Webhook Mailtrap: {$eventType}");

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error processing Mailtrap webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Vérifie la signature du webhook Resend
     */
    private function verifyResendSignature(Request $request): bool
    {
        $secret = config('services.resend.webhook_secret');

        // Si pas de secret configuré, on accepte en dev
        if (empty($secret) && app()->environment('local')) {
            return true;
        }

        if (empty($secret)) {
            return false;
        }

        // Récupérer la signature depuis les headers
        $signature = $request->header('svix-signature');
        $timestamp = $request->header('svix-timestamp');
        $id = $request->header('svix-id');

        if (! $signature || ! $timestamp || ! $id) {
            return false;
        }

        // Construire le message signé
        $signedContent = "{$id}.{$timestamp}.{$request->getContent()}";

        // Extraire les signatures (format: v1=xxx,v1=yyy)
        $signatures = [];
        foreach (explode(',', $signature) as $sig) {
            [$version, $hash] = explode('=', trim($sig), 2);
            $signatures[$version][] = $hash;
        }

        // Vérifier la signature v1 (HMAC SHA256)
        if (isset($signatures['v1'])) {
            $expectedSignature = base64_encode(
                hash_hmac('sha256', $signedContent, $secret, true)
            );

            foreach ($signatures['v1'] as $sig) {
                if (hash_equals($expectedSignature, $sig)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Vérifie la signature du webhook Mailtrap
     */
    private function verifyMailtrapSignature(Request $request): bool
    {
        $secret = config('services.mailtrap.webhook_secret');

        // Si pas de secret configuré, on accepte en dev
        if (empty($secret) && app()->environment('local')) {
            return true;
        }

        if (empty($secret)) {
            return false;
        }

        // Mailtrap utilise un header X-Mailtrap-Signature
        $signature = $request->header('X-Mailtrap-Signature');

        if (! $signature) {
            return false;
        }

        // Calculer la signature attendue
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }

    // ==========================================
    // HANDLERS ÉVÉNEMENTS RESEND
    // ==========================================

    /**
     * Email envoyé avec succès
     */
    private function handleEmailSent(array $payload): void
    {
        $emailId = $payload['data']['email_id'] ?? null;
        $to = $payload['data']['to'] ?? [];

        Log::info('Resend: Email sent', [
            'email_id' => $emailId,
            'to' => $to,
        ]);

        // Mettre à jour le cache des statistiques
        $this->incrementEmailStat('sent');
    }

    /**
     * Email délivré
     */
    private function handleEmailDelivered(array $payload): void
    {
        $emailId = $payload['data']['email_id'] ?? null;

        Log::info('Resend: Email delivered', [
            'email_id' => $emailId,
        ]);

        $this->incrementEmailStat('delivered');
    }

    /**
     * Livraison retardée
     */
    private function handleEmailDelayed(array $payload): void
    {
        $emailId = $payload['data']['email_id'] ?? null;
        $reason = $payload['data']['reason'] ?? 'Unknown';

        Log::warning('Resend: Email delayed', [
            'email_id' => $emailId,
            'reason' => $reason,
        ]);

        $this->incrementEmailStat('delayed');
    }

    /**
     * Email rebondi (bounce)
     */
    private function handleEmailBounced(array $payload): void
    {
        $emailId = $payload['data']['email_id'] ?? null;
        $to = $payload['data']['to'] ?? null;
        $reason = $payload['data']['bounce_type'] ?? 'Unknown';

        Log::error('Resend: Email bounced', [
            'email_id' => $emailId,
            'to' => $to,
            'reason' => $reason,
        ]);

        $this->incrementEmailStat('bounced');

        // Marquer l'email comme invalide si c'est un agent
        if ($to) {
            $this->markEmailAsInvalid($to, 'bounce', $reason);
        }

        // Notifier l'admin si bounce hard
        if (strtolower($reason) === 'hard' || str_contains(strtolower($reason), 'permanent')) {
            $this->notifyAdminEmailIssue('hard_bounce', [
                'email' => $to,
                'reason' => $reason,
                'email_id' => $emailId,
            ]);
        }
    }

    /**
     * Plainte spam
     */
    private function handleEmailComplained(array $payload): void
    {
        $emailId = $payload['data']['email_id'] ?? null;
        $to = $payload['data']['to'] ?? null;

        Log::error('Resend: Spam complaint received', [
            'email_id' => $emailId,
            'to' => $to,
        ]);

        $this->incrementEmailStat('complained');

        // Désinscrire automatiquement cet email
        if ($to) {
            $this->markEmailAsInvalid($to, 'complaint', 'Spam complaint');
        }

        // Alerter l'admin immédiatement
        $this->notifyAdminEmailIssue('spam_complaint', [
            'email' => $to,
            'email_id' => $emailId,
        ]);
    }

    /**
     * Email ouvert
     */
    private function handleEmailOpened(array $payload): void
    {
        $emailId = $payload['data']['email_id'] ?? null;

        Log::info('Resend: Email opened', [
            'email_id' => $emailId,
        ]);

        $this->incrementEmailStat('opened');
    }

    /**
     * Lien cliqué
     */
    private function handleEmailClicked(array $payload): void
    {
        $emailId = $payload['data']['email_id'] ?? null;
        $link = $payload['data']['link'] ?? null;

        Log::info('Resend: Link clicked', [
            'email_id' => $emailId,
            'link' => $link,
        ]);

        $this->incrementEmailStat('clicked');
    }

    // ==========================================
    // HANDLERS ÉVÉNEMENTS MAILTRAP
    // ==========================================

    /**
     * Email délivré (Mailtrap)
     */
    private function handleMailtrapDelivery(array $payload): void
    {
        $messageId = $payload['message_id'] ?? null;

        Log::info('Mailtrap: Email delivered', [
            'message_id' => $messageId,
        ]);

        $this->incrementEmailStat('mailtrap_delivered');
    }

    /**
     * Email ouvert (Mailtrap)
     */
    private function handleMailtrapOpen(array $payload): void
    {
        $messageId = $payload['message_id'] ?? null;

        Log::info('Mailtrap: Email opened', [
            'message_id' => $messageId,
        ]);

        $this->incrementEmailStat('mailtrap_opened');
    }

    /**
     * Lien cliqué (Mailtrap)
     */
    private function handleMailtrapClick(array $payload): void
    {
        $messageId = $payload['message_id'] ?? null;
        $url = $payload['url'] ?? null;

        Log::info('Mailtrap: Link clicked', [
            'message_id' => $messageId,
            'url' => $url,
        ]);

        $this->incrementEmailStat('mailtrap_clicked');
    }

    /**
     * Email rebondi (Mailtrap)
     */
    private function handleMailtrapBounce(array $payload): void
    {
        $messageId = $payload['message_id'] ?? null;
        $reason = $payload['reason'] ?? 'Unknown';

        Log::error('Mailtrap: Email bounced', [
            'message_id' => $messageId,
            'reason' => $reason,
        ]);

        $this->incrementEmailStat('mailtrap_bounced');
    }

    /**
     * Marqué comme spam (Mailtrap)
     */
    private function handleMailtrapSpam(array $payload): void
    {
        $messageId = $payload['message_id'] ?? null;

        Log::error('Mailtrap: Marked as spam', [
            'message_id' => $messageId,
        ]);

        $this->incrementEmailStat('mailtrap_spam');
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Incrémenter les statistiques d'emails
     */
    private function incrementEmailStat(string $type): void
    {
        $key = "email_stats:{$type}:".now()->format('Y-m-d');
        Cache::increment($key);

        // Expiration après 90 jours
        Cache::put($key, Cache::get($key, 1), now()->addDays(90));
    }

    /**
     * Marquer un email comme invalide dans la base de données
     */
    private function markEmailAsInvalid(string $email, string $type, string $reason): void
    {
        try {
            // Marquer dans les agents
            \App\Models\Agent::where('email', $email)->update([
                'email_valid' => false,
            ]);

            // Marquer dans les users
            \App\Models\User::where('email', $email)->update([
                'email_verified_at' => null,
            ]);

            // Logger l'action
            activity()
                ->withProperties([
                    'email' => $email,
                    'type' => $type,
                    'reason' => $reason,
                ])
                ->log('email_marked_invalid');

            Log::info('Email marked as invalid', ['email' => $email, 'type' => $type]);
        } catch (\Exception $e) {
            Log::error('Failed to mark email as invalid', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifier l'admin d'un problème d'email
     */
    private function notifyAdminEmailIssue(string $type, array $data): void
    {
        try {
            $securityService = app(\App\Services\SecurityService::class);
            $securityService->sendSecurityAlert('email_issue_'.$type, $data);
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of email issue', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
