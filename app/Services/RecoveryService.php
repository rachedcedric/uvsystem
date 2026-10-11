<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\User;
use App\Notifications\DebtAlertNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class RecoveryService
{
    protected SmsService $sms;

    protected NotificationService $notifications;

    // Seuils de recouvrement (configurables)
    protected array $thresholds = [
        'warning' => 70,      // 70% : Avertissement
        'alert' => 80,        // 80% : Alerte
        'critical' => 90,     // 90% : Critique
        'blocked' => 100,     // 100% : Blocage
    ];

    // Délais entre les rappels (en jours)
    protected array $reminderDelays = [
        'first' => 3,         // 1er rappel après 3 jours
        'second' => 7,        // 2ème rappel après 7 jours
        'third' => 14,        // 3ème rappel après 14 jours
        'final' => 21,        // Rappel final après 21 jours
    ];

    public function __construct()
    {
        $this->sms = app(SmsService::class);
        $this->notifications = app(NotificationService::class);
    }

    /**
     * Exécuter le processus de recouvrement complet
     */
    public function processAllAgents(): array
    {
        $results = [
            'total' => 0,
            'warnings_sent' => 0,
            'alerts_sent' => 0,
            'critical_alerts' => 0,
            'agents_blocked' => 0,
            'sms_sent' => 0,
            'errors' => [],
        ];

        $agents = Agent::where('current_debt', '>', 0)
            ->where('status', '!=', 'blocked')
            ->get();

        $results['total'] = $agents->count();

        foreach ($agents as $agent) {
            try {
                $action = $this->processAgent($agent);

                match ($action) {
                    'warning' => $results['warnings_sent']++,
                    'alert' => $results['alerts_sent']++,
                    'critical' => $results['critical_alerts']++,
                    'blocked' => $results['agents_blocked']++,
                    default => null,
                };

                if (in_array($action, ['alert', 'critical', 'blocked'])) {
                    $results['sms_sent']++;
                }
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('Recovery process error', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Logger l'activité
        activity()
            ->withProperties($results)
            ->log('Processus de recouvrement exécuté');

        return $results;
    }

    /**
     * Traiter un agent individuel
     */
    public function processAgent(Agent $agent): ?string
    {
        if ($agent->debt_limit <= 0) {
            return null;
        }

        $ratio = ($agent->current_debt / $agent->debt_limit) * 100;
        $action = null;

        // Déterminer l'action selon le ratio
        if ($ratio >= $this->thresholds['blocked']) {
            $action = $this->handleBlockedLevel($agent, $ratio);
        } elseif ($ratio >= $this->thresholds['critical']) {
            $action = $this->handleCriticalLevel($agent, $ratio);
        } elseif ($ratio >= $this->thresholds['alert']) {
            $action = $this->handleAlertLevel($agent, $ratio);
        } elseif ($ratio >= $this->thresholds['warning']) {
            $action = $this->handleWarningLevel($agent, $ratio);
        }

        return $action;
    }

    /**
     * Niveau Warning (70-79%)
     */
    protected function handleWarningLevel(Agent $agent, float $ratio): string
    {
        $cacheKey = "recovery_warning_{$agent->id}";

        // Vérifier si un warning a déjà été envoyé récemment
        if (Cache::has($cacheKey)) {
            return 'skip';
        }

        // Envoyer notification email uniquement
        $this->sendEmailNotification($agent, 'warning', $ratio);

        // Cache pour éviter spam (24h)
        Cache::put($cacheKey, true, now()->addHours(24));

        return 'warning';
    }

    /**
     * Niveau Alert (80-89%)
     */
    protected function handleAlertLevel(Agent $agent, float $ratio): string
    {
        $cacheKey = "recovery_alert_{$agent->id}";
        $reminderCount = Cache::get("recovery_reminder_count_{$agent->id}", 0);

        // Vérifier le délai entre les rappels
        $delay = $this->getReminderDelay($reminderCount);
        if (Cache::has($cacheKey)) {
            return 'skip';
        }

        // Envoyer notification email
        $this->sendEmailNotification($agent, 'warning', $ratio);

        // Envoyer SMS si 2ème rappel ou plus
        if ($reminderCount >= 1) {
            $this->sendSmsReminder($agent, $ratio);
        }

        // Incrémenter le compteur
        Cache::put("recovery_reminder_count_{$agent->id}", $reminderCount + 1, now()->addDays(30));
        Cache::put($cacheKey, true, now()->addDays($delay));

        return 'alert';
    }

    /**
     * Niveau Critical (90-99%)
     */
    protected function handleCriticalLevel(Agent $agent, float $ratio): string
    {
        $cacheKey = "recovery_critical_{$agent->id}";

        if (Cache::has($cacheKey)) {
            return 'skip';
        }

        // Envoyer notification email urgente
        $this->sendEmailNotification($agent, 'critical', $ratio);

        // Envoyer SMS immédiatement
        $this->sendSmsReminder($agent, $ratio, true);

        // Notifier les administrateurs
        $this->notifyAdministrators($agent, $ratio);

        // Cache (12h pour les critiques)
        Cache::put($cacheKey, true, now()->addHours(12));

        return 'critical';
    }

    /**
     * Niveau Blocked (100%+)
     */
    protected function handleBlockedLevel(Agent $agent, float $ratio): string
    {
        // Bloquer l'agent
        if ($agent->status !== 'blocked') {
            $agent->update(['status' => 'blocked']);

            // Notification de blocage
            $this->sendBlockedNotification($agent);

            // SMS de blocage
            $this->sms->send($agent->phone,
                config('branding.name').": COMPTE BLOQUÉ\n".
                "Votre compte a été bloqué pour dépassement de plafond.\n".
                'Dette: '.number_format($agent->current_debt, 0, ',', ' ')." FCFA\n".
                "Veuillez contacter l'administration."
            );

            // Notifier les administrateurs
            $this->notifyAdministrators($agent, $ratio, true);

            activity()
                ->performedOn($agent)
                ->withProperties([
                    'ratio' => $ratio,
                    'debt' => $agent->current_debt,
                    'limit' => $agent->debt_limit,
                ])
                ->log('Agent bloqué automatiquement');

            return 'blocked';
        }

        return 'already_blocked';
    }

    /**
     * Débloquer un agent après remboursement
     */
    public function checkUnblock(Agent $agent): bool
    {
        if ($agent->status !== 'blocked') {
            return false;
        }

        $ratio = $agent->debt_limit > 0
            ? ($agent->current_debt / $agent->debt_limit) * 100
            : 0;

        // Débloquer si ratio < 90%
        if ($ratio < 90) {
            $agent->update(['status' => 'active']);

            // Notification de déblocage
            $this->sms->send($agent->phone,
                config('branding.name').": COMPTE RÉACTIVÉ\n".
                "Votre compte a été réactivé suite à votre remboursement.\n".
                'Merci pour votre paiement!'
            );

            // Réinitialiser les compteurs
            Cache::forget("recovery_reminder_count_{$agent->id}");
            Cache::forget("recovery_critical_{$agent->id}");
            Cache::forget("recovery_alert_{$agent->id}");
            Cache::forget("recovery_warning_{$agent->id}");

            activity()
                ->performedOn($agent)
                ->withProperties(['new_ratio' => $ratio])
                ->log('Agent débloqué automatiquement');

            return true;
        }

        return false;
    }

    /**
     * Obtenir le délai entre les rappels
     */
    protected function getReminderDelay(int $reminderCount): int
    {
        return match ($reminderCount) {
            0 => $this->reminderDelays['first'],
            1 => $this->reminderDelays['second'],
            2 => $this->reminderDelays['third'],
            default => $this->reminderDelays['final'],
        };
    }

    /**
     * Envoyer notification email
     */
    protected function sendEmailNotification(Agent $agent, string $type, float $ratio): void
    {
        // Récupérer les admins à notifier
        $admins = User::role(['master', 'admin'])->get();

        Notification::send($admins, new DebtAlertNotification($agent, $type, $ratio));
    }

    /**
     * Envoyer SMS de rappel
     */
    protected function sendSmsReminder(Agent $agent, float $ratio, bool $urgent = false): void
    {
        if (! $this->sms->isEnabled()) {
            return;
        }

        $prefix = $urgent ? '⚠️ URGENT - ' : '';

        $message = "{$prefix}".config('branding.name').": Rappel paiement\n";
        $message .= 'Dette: '.number_format($agent->current_debt, 0, ',', ' ')." FCFA\n";
        $message .= 'Ratio: '.round($ratio, 1)."% du plafond\n";

        if ($urgent) {
            $message .= 'Action immédiate requise!';
        } else {
            $message .= 'Merci de régulariser votre situation.';
        }

        $this->sms->sendToAgent($agent, $message);
    }

    /**
     * Notifier les administrateurs
     */
    protected function notifyAdministrators(Agent $agent, float $ratio, bool $blocked = false): void
    {
        $admins = User::role(['master', 'admin'])->get();

        $type = $blocked ? 'blocked' : 'critical';

        Notification::send($admins, new DebtAlertNotification($agent, $type, $ratio));
    }

    /**
     * Envoyer notification de blocage
     */
    protected function sendBlockedNotification(Agent $agent): void
    {
        $admins = User::role(['master', 'admin'])->get();

        Notification::send($admins, new DebtAlertNotification($agent, 'blocked'));
    }

    /**
     * Obtenir les statistiques de recouvrement
     */
    public function getStats(): array
    {
        $agents = Agent::where('current_debt', '>', 0)->get();

        $stats = [
            'total_agents_endettes' => $agents->count(),
            'total_dette' => $agents->sum('current_debt'),
            'warning_count' => 0,
            'alert_count' => 0,
            'critical_count' => 0,
            'blocked_count' => Agent::where('status', 'blocked')->count(),
        ];

        foreach ($agents as $agent) {
            if ($agent->debt_limit <= 0) {
                continue;
            }

            $ratio = ($agent->current_debt / $agent->debt_limit) * 100;

            if ($ratio >= $this->thresholds['critical']) {
                $stats['critical_count']++;
            } elseif ($ratio >= $this->thresholds['alert']) {
                $stats['alert_count']++;
            } elseif ($ratio >= $this->thresholds['warning']) {
                $stats['warning_count']++;
            }
        }

        return $stats;
    }
}
