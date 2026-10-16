<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\User;
use App\Services\SecurityService;
use App\Services\Webhooks\SvixSignatureVerifier;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
     * @return JsonResponse
     */
    public function resend(Request $request)
    {
        $secret = (string) config('services.resend.webhook_secret');

        // Authenticité : volontairement HORS du try/catch général — un en-tête hostile ne doit jamais produire un 500.
        $failure = $this->resendSignatureFailure($request, $secret);

        if ($failure !== null) {
            $messageId = (string) $request->header('svix-id');
            $timestamp = (string) $request->header('svix-timestamp');

            // Jamais la signature, le secret ni le corps : seulement le motif et des identifiants au format contrôlé.
            Log::log($failure === SvixSignatureVerifier::INVALID_SECRET ? 'critical' : 'warning', 'Resend webhook signature verification failed', [
                'reason' => $failure,
                'ip' => $request->ip(),
                'svix_id' => preg_match('/\A[A-Za-z0-9_\-]{1,128}\z/', $messageId) === 1 ? $messageId : null,
                'svix_timestamp' => preg_match('/\A\d{1,12}\z/', $timestamp) === 1 ? $timestamp : null,
            ]);

            return response()->json([
                'error' => 'Invalid signature',
            ], 401);
        }

        // Anti-rejeu : uniquement pour une requête réellement signée (en local sans secret, aucun svix-id n'est garanti).
        $messageId = $secret !== '' ? (string) $request->header('svix-id') : null;
        $lease = null;

        try {
            if ($messageId !== null) {
                [$state, $lease] = $this->claimResendEvent($messageId);

                if ($state === 'done') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Webhook already processed',
                        'duplicate' => true,
                    ], 200);
                }

                if ($state === 'processing') {
                    // Non-2xx : Resend réessaiera plus tard (le premier appel a peut-être échoué).
                    return response()->json([
                        'error' => 'Event is already being processed',
                    ], 409);
                }
            }

            // Les données viennent du corps SIGNÉ uniquement : la query string n'est pas couverte par la signature.
            $payload = $request->json()->all();
            $eventType = is_string($payload['type'] ?? null) ? $payload['type'] : 'unknown';

            // Journaliser l'événement sans son contenu (destinataires, sujet, liens...).
            Log::info('Resend webhook received', [
                'type' => $eventType,
                'email_id' => $payload['data']['email_id'] ?? null,
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

            // Enregistrer dans ActivityLog (ni adresses ni sujet : seulement le nombre de destinataires)
            activity()
                ->withProperties([
                    'service' => 'resend',
                    'event_type' => $eventType,
                    'email_id' => $payload['data']['email_id'] ?? null,
                    'recipient_count' => $this->recipientCount($payload['data']['to'] ?? null),
                ])
                ->log("Webhook Resend: {$eventType}");

            if ($messageId !== null) {
                $this->completeResendEvent($messageId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully',
            ], 200);

        } catch (\Throwable $e) {
            // Traitement échoué : on libère le bail pour que la relance de Resend (même svix-id) soit acceptée.
            if ($messageId !== null && $lease !== null) {
                $this->releaseResendEvent($messageId, $lease);
            }

            Log::error('Error processing Resend webhook', $this->safeErrorContext($e));

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Traite les webhooks Mailtrap
     *
     * @return JsonResponse
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
     * Vérifie la signature Svix du webhook Resend (secret décodé, horodatage borné, en-têtes tolérés mal formés).
     *
     * @return string|null null si la requête est acceptée, sinon le motif du refus
     */
    private function resendSignatureFailure(Request $request, string $secret): ?string
    {
        // Si pas de secret configuré, on accepte en dev
        if ($secret === '') {
            return app()->environment('local') ? null : 'secret_not_configured';
        }

        $verifier = new SvixSignatureVerifier(
            $secret,
            max(0, (int) config('services.resend.webhook_tolerance', SvixSignatureVerifier::DEFAULT_TOLERANCE)),
        );

        return $verifier->failureReason(
            $request->header('svix-id'),
            $request->header('svix-timestamp'),
            $request->header('svix-signature'),
            $request->getContent(),
        );
    }

    // ==========================================
    // ANTI-REJEU (RESEND / SVIX)
    // ==========================================
    //
    // Principe : après vérification de la signature, chaque svix-id est « réservé » dans le cache avec un
    // bail court (`processing:<jeton>`), puis marqué `done` (longue durée) une fois le traitement terminé.
    //  - déjà `done`                 → 200 sans retraitement (rejeu, doublon de livraison) ;
    //  - bail actif d'un autre appel → 409 : Resend réessaiera, rien n'est perdu si le premier appel échoue ;
    //  - échec du traitement         → le bail est libéré (seulement s'il porte NOTRE jeton) : la relance passe ;
    //  - processus tué en cours      → le bail expire seul (webhook_lease), la relance suivante passe.
    //
    // Garanties : la réservation est atomique (Cache::add : insertOrIgnore sur le magasin « database », SETNX sur
    // Redis) donc deux appels simultanés ne traitent jamais le même événement ; un événement terminé n'est plus
    // retraité pendant webhook_dedup_ttl (≥ 2 × la tolérance de date, donc la capture d'une requête signée ne peut
    // pas être rejouée tant que sa signature est encore valable). Seules des requêtes authentiques créent des entrées.
    //
    // Limites assumées :
    //  - effets « au moins une fois » : un échec APRÈS certains effets (compteur de stats, alerte admin, journal
    //    d'activité) fait rejouer ces effets à la relance (marquer une adresse invalide est idempotent) ;
    //  - les erreurs déjà absorbées par les handlers (ex. échec en base de markOneEmailAsInvalid, journalisées)
    //    comptent comme traitées, comme avant ce correctif ;
    //  - si le traitement dépasse webhook_lease, une relance peut le refaire en parallèle (le jeton évite au moins
    //    qu'un appel en échec libère le bail d'un autre) ;
    //  - cache indisponible → 500 (fail closed, Resend réessaiera) ; cache vidé → un doublon ancien peut repasser ;
    //  - magasin de cache « file » : réservation non strictement atomique (la production utilise « database ») ;
    //  - la clé est globale (pas de TenantContext::cacheKey) : le webhook n'a pas de contexte d'entreprise et le
    //    svix-id est unique à l'échelle de la plateforme Resend.

    private function resendEventKey(string $messageId): string
    {
        return 'webhook:resend:'.$messageId;
    }

    /**
     * @return array{0: string, 1: string|null} [état, jeton du bail] ; état : claimed | done | processing
     */
    private function claimResendEvent(string $messageId): array
    {
        $key = $this->resendEventKey($messageId);
        $lease = 'processing:'.bin2hex(random_bytes(8));

        if (Cache::add($key, $lease, max(1, (int) config('services.resend.webhook_lease', 120)))) {
            return ['claimed', $lease];
        }

        return [Cache::get($key) === 'done' ? 'done' : 'processing', null];
    }

    private function completeResendEvent(string $messageId): void
    {
        // Jamais inférieure à 2 × la tolérance : sinon la fenêtre de validité de la signature dépasserait la mémoire.
        $ttl = max(
            (int) config('services.resend.webhook_dedup_ttl', 86400),
            2 * (int) config('services.resend.webhook_tolerance', SvixSignatureVerifier::DEFAULT_TOLERANCE),
        );

        try {
            Cache::put($this->resendEventKey($messageId), 'done', $ttl);
        } catch (\Throwable $e) {
            // L'événement EST traité : répondre 500 ferait rejouer ses effets. On journalise et on répond 200.
            Log::warning('Resend webhook: marqueur de traitement non enregistré', $this->safeErrorContext($e) + ['svix_id' => $messageId]);
        }
    }

    private function releaseResendEvent(string $messageId, string $lease): void
    {
        try {
            $key = $this->resendEventKey($messageId);

            // Ne libère que NOTRE bail : si le nôtre a expiré, un autre appel a pu prendre le relais.
            if (Cache::get($key) === $lease) {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            Log::warning('Resend webhook: bail non libéré (il expirera seul)', $this->safeErrorContext($e) + ['svix_id' => $messageId]);
        }
    }

    /** Nombre de destinataires d'un événement : on ne journalise jamais les adresses. */
    private function recipientCount(mixed $recipients): int
    {
        return match (true) {
            is_array($recipients) => count(array_filter($recipients, fn ($email) => is_string($email) && $email !== '')),
            is_string($recipients) && $recipients !== '' => 1,
            default => 0,
        };
    }

    /** Masque toute adresse e-mail dans un texte (les messages d'exception SQL embarquent les valeurs liées). */
    private function redactEmails(string $text): string
    {
        return preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[adresse masquée]', $text) ?? '[message illisible]';
    }

    /**
     * Contexte de journal d'une exception, sans adresse e-mail. La trace est volontairement omise : avec
     * zend.exception_ignore_args désactivé, elle reproduit le début des arguments (donc des adresses).
     *
     * @return array<string, string>
     */
    private function safeErrorContext(\Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'at' => basename($e->getFile()).':'.$e->getLine(),
            'error' => $this->redactEmails($e->getMessage()),
        ];
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
            'recipient_count' => $this->recipientCount($to),
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
            'recipient_count' => $this->recipientCount($to),
            'reason' => $reason,
        ]);

        $this->incrementEmailStat('bounced');

        // Marquer l'email comme invalide si c'est un agent
        if ($to) {
            $this->markEmailAsInvalid($to, 'bounce', $reason);
        }

        // Notifier l'admin si bounce hard
        if (strtolower($reason) === 'hard' || str_contains(strtolower($reason), 'permanent')) {
            // Pas d'adresse : sendSecurityAlert recopie ces données dans les journaux.
            $this->notifyAdminEmailIssue('hard_bounce', [
                'recipient_count' => $this->recipientCount($to),
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
            'recipient_count' => $this->recipientCount($to),
        ]);

        $this->incrementEmailStat('complained');

        // Désinscrire automatiquement cet email
        if ($to) {
            $this->markEmailAsInvalid($to, 'complaint', 'Spam complaint');
        }

        // Alerter l'admin immédiatement
        $this->notifyAdminEmailIssue('spam_complaint', [
            'recipient_count' => $this->recipientCount($to),
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

        // Le lien cliqué fait partie du contenu du courriel : il n'est pas journalisé.
        Log::info('Resend: Link clicked', [
            'email_id' => $emailId,
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
     * Marquer un email comme invalide dans la base de données.
     *
     * Le webhook n'a pas d'utilisateur donc pas de tenant : on cherche l'adresse HORS contexte
     * UNIQUEMENT pour identifier le(s) tenant(s) propriétaire(s), puis chaque modification est faite
     * dans le contexte de ce tenant (jamais une mise à jour globale). Une adresse d'agent partagée par
     * plusieurs tenants est ambiguë : elle n'est pas modifiée (journalisée).
     */
    private function markEmailAsInvalid(string|array $recipients, string $type, string $reason): void
    {
        // Resend envoie `to` sous forme de liste ; on accepte aussi une chaîne seule.
        foreach (array_filter((array) $recipients, fn ($email) => is_string($email) && $email !== '') as $email) {
            $this->markOneEmailAsInvalid($email, $type, $reason);
        }
    }

    private function markOneEmailAsInvalid(string $email, string $type, string $reason): void
    {
        try {
            // Agents
            $agentTenants = TenantContext::withoutTenancy(
                fn () => Agent::query()->where('email', $email)->pluck('tenant_id')->unique()->values()
            );

            if ($agentTenants->count() > 1) {
                Log::warning('Email webhook : adresse d\'agent partagée entre plusieurs tenants, non modifiée', [
                    'tenants' => $agentTenants->count(), 'type' => $type,
                ]);
            } elseif ($agentTenants->count() === 1 && Schema::hasColumn('agents', 'email_valid')) { // colonne créée par la migration 2026_10_08 ; le test évite une erreur si le code est déployé avant elle
                TenantContext::runAs((int) $agentTenants->first(), fn () => Agent::query()->where('email', $email)->update(['email_valid' => false]));
            }

            // Users : l'e-mail est unique sur la plateforme, donc un seul propriétaire possible.
            $user = TenantContext::withoutTenancy(fn () => User::query()->where('email', $email)->first(['id', 'tenant_id']));

            if ($user !== null) {
                $mark = fn () => User::query()->whereKey($user->id)->update(['email_verified_at' => null]);

                $user->tenant_id !== null
                    ? TenantContext::runAs((int) $user->tenant_id, $mark)
                    : TenantContext::withoutTenancy($mark); // compte plateforme (sans tenant)
            }

            // Logger l'action
            activity()
                ->withProperties([
                    'email' => $email,
                    'type' => $type,
                    'reason' => $reason,
                ])
                ->log('email_marked_invalid');

            Log::info('Email marked as invalid', ['type' => $type]);
        } catch (\Exception $e) {
            // Ni l'adresse ni le message brut (les exceptions SQL embarquent les valeurs liées).
            Log::error('Failed to mark email as invalid', ['type' => $type] + $this->safeErrorContext($e));
        }
    }

    /**
     * Notifier l'admin d'un problème d'email
     */
    private function notifyAdminEmailIssue(string $type, array $data): void
    {
        try {
            $securityService = app(SecurityService::class);
            $securityService->sendSecurityAlert('email_issue_'.$type, $data);
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of email issue', ['type' => $type] + $this->safeErrorContext($e));
        }
    }
}
