<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use App\Models\Agent;
use App\Models\User;
use App\Notifications\DebtAlertNotification;
use App\Notifications\DebtReminderNotification;
use App\Notifications\PaymentReminderNotification;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    protected float $warningThreshold = 70.0;  // 70%

    protected float $criticalThreshold = 90.0; // 90%

    /**
     * Envoie une alerte de dette si le seuil est atteint
     */
    public function checkAndSendDebtAlert(Agent $agent): ?string
    {
        if ($agent->debt_limit <= 0) {
            return null;
        }

        $ratio = ($agent->current_debt / $agent->debt_limit) * 100;

        if ($ratio >= $this->criticalThreshold) {
            $this->sendDebtAlert($agent, 'critical', $ratio);

            return 'critical';
        }

        if ($ratio >= $this->warningThreshold) {
            $this->sendDebtAlert($agent, 'warning', $ratio);

            return 'warning';
        }

        return null;
    }

    /**
     * Envoie une alerte de dette aux admins
     */
    public function sendDebtAlert(Agent $agent, string $alertType, float $ratio): void
    {
        $admins = $this->getAdminUsers();

        if ($admins->isEmpty()) {
            Log::warning("DebtAlert: Aucun admin trouvé pour recevoir l'alerte", [
                'agent_id' => $agent->id,
                'alert_type' => $alertType,
            ]);

            return;
        }

        Notification::send(
            $admins,
            new DebtAlertNotification($agent, $alertType, $ratio)
        );

        Log::info('DebtAlert envoyée', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'alert_type' => $alertType,
            'ratio' => $ratio,
            'admins_notified' => $admins->count(),
        ]);
    }

    /**
     * Envoie un rappel de paiement
     */
    public function sendPaymentReminder(Agent $agent, ?string $customMessage = null): bool
    {
        if ($agent->current_debt <= 0) {
            return false;
        }

        $admins = $this->getAdminUsers();

        // Notifie les admins qu'un rappel a été envoyé
        if ($admins->isNotEmpty()) {
            Notification::send(
                $admins,
                new PaymentReminderNotification($agent, $customMessage)
            );
        }

        // Log l'envoi du rappel
        activity()
            ->performedOn($agent)
            ->withProperties([
                'action' => 'payment_reminder_sent',
                'current_debt' => $agent->current_debt,
                'message' => $customMessage,
            ])
            ->log("Rappel de paiement envoyé à {$agent->name}");

        Log::info('PaymentReminder envoyé', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'current_debt' => $agent->current_debt,
        ]);

        return true;
    }

    /**
     * Envoie des rappels en masse aux agents avec dette
     */
    public function sendBulkPaymentReminders(float $minDebtRatio = 50.0): array
    {
        $agents = Agent::query()
            ->where('status', 'active')
            ->where('current_debt', '>', 0)
            ->whereRaw('debt_limit > 0')
            ->whereRaw('(current_debt / debt_limit * 100) >= ?', [$minDebtRatio])
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($agents as $agent) {
            try {
                $this->sendPaymentReminder($agent);
                $sent++;
            } catch (\Exception $e) {
                Log::error('Erreur envoi rappel', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return [
            'total' => $agents->count(),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /**
     * Vérifie tous les agents et envoie les alertes nécessaires
     */
    public function checkAllAgentsDebt(): array
    {
        $agents = Agent::query()
            ->where('status', 'active')
            ->where('debt_limit', '>', 0)
            ->where('current_debt', '>', 0)
            ->get();

        $alerts = ['critical' => 0, 'warning' => 0, 'ok' => 0];

        foreach ($agents as $agent) {
            $result = $this->checkAndSendDebtAlert($agent);

            match ($result) {
                'critical' => $alerts['critical']++,
                'warning' => $alerts['warning']++,
                default => $alerts['ok']++,
            };
        }

        return $alerts;
    }

    /**
     * Rappel automatique (toutes les 2h, voir uv:send-debt-reminders) pour
     * chaque agent ayant une dette non soldée — indépendamment de tout seuil
     * de plafond (contrairement à checkAllAgentsDebt(), qui ne concerne que
     * les ratios warning/critical). Un seul rappel par agent et par cycle de
     * 2h : la dette étant un solde global (pas de dettes individualisées
     * dans ce projet), un agent avec plusieurs ventes impayées reçoit un
     * rappel unique portant le montant total restant dû.
     *
     * @return array{eligible: int, sent: int, skipped_duplicate: int}
     */
    public function checkAndSendDebtReminders(?Carbon $at = null): array
    {
        $at ??= now();
        $cycleKey = $this->getDebtReminderCycleKey($at);

        // Source de vérité unique : les mêmes critères (agent actif, dette
        // > 0) que partout ailleurs dans l'application (pages Dettes,
        // Remboursements, Dashboard).
        $agents = Agent::active()->withDebt()->get();

        $recipients = $this->getDebtReminderRecipients();

        if ($recipients->isEmpty()) {
            Log::warning('DebtReminder: aucun destinataire (rôle master/admin) trouvé, aucun rappel envoyé');

            return ['eligible' => $agents->count(), 'sent' => 0, 'skipped_duplicate' => 0];
        }

        $sent = 0;
        $skipped = 0;

        foreach ($agents as $agent) {
            if ($this->debtReminderAlreadySentForCycle($agent, $cycleKey)) {
                $skipped++;

                continue;
            }

            $this->sendDebtReminder($agent, $cycleKey, $recipients);
            $sent++;
        }

        return [
            'eligible' => $agents->count(),
            'sent' => $sent,
            'skipped_duplicate' => $skipped,
        ];
    }

    /**
     * Filet de sécurité pour les hébergements où le cron Laravel n'est pas
     * actif : le premier accès admin de chaque cycle lance les rappels.
     * Le scheduler reste la voie principale et la déduplication en base
     * garantit qu'un agent ne reçoit jamais deux rappels dans le même cycle.
     */
    public function checkAndSendDebtRemindersOncePerCycle(?Carbon $at = null): ?array
    {
        $at ??= now();
        $cycleKey = $this->getDebtReminderCycleKey($at);
        $cacheKey = TenantContext::cacheKey("debt-reminders-admin-fallback:{$cycleKey}");
        $expiresAt = $at->copy()->startOfHour()->addHours($at->hour % 2 === 0 ? 2 : 1)->addMinutes(5);

        if (! Cache::add($cacheKey, true, $expiresAt)) {
            return null;
        }

        try {
            return $this->checkAndSendDebtReminders($at);
        } catch (\Throwable $exception) {
            Cache::forget($cacheKey);

            throw $exception;
        }
    }

    /**
     * Clé du cycle de 2h en cours (ex: "2026-08-25-14" pour la tranche
     * 14h-16h), alignée sur les horaires de Schedule::everyTwoHours().
     */
    protected function getDebtReminderCycleKey(Carbon $at): string
    {
        $cycleStartHour = $at->hour - ($at->hour % 2);

        return $at->format('Y-m-d').'-'.str_pad((string) $cycleStartHour, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Un rappel a-t-il déjà été créé pour cet agent sur ce cycle ? Empêche
     * tout doublon, y compris si la commande est relancée manuellement dans
     * la même fenêtre de 2h.
     */
    protected function debtReminderAlreadySentForCycle(Agent $agent, string $cycleKey): bool
    {
        return DatabaseNotification::query()
            ->where('type', DebtReminderNotification::class)
            ->where('data->notification_kind', 'debt_reminder')
            ->where('data->agent_id', $agent->id)
            ->where('data->reminder_cycle', $cycleKey)
            ->exists();
    }

    protected function sendDebtReminder(Agent $agent, string $cycleKey, $recipients): void
    {
        $solde = $agent->solde;
        $totalInitial = $solde ? (float) $solde->total_uv_distribues : (float) $agent->current_debt;
        $totalRembourse = $solde ? (float) $solde->total_rembourse : 0.0;
        $resteDu = (float) $agent->current_debt;

        $statusLabel = $totalRembourse > 0 ? 'Partiellement remboursée' : 'En cours';

        Notification::send(
            $recipients,
            new DebtReminderNotification($agent, $cycleKey, $totalInitial, $totalRembourse, $resteDu, $statusLabel)
        );

        Log::info('DebtReminder envoyé', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'reste_du' => $resteDu,
            'cycle' => $cycleKey,
            'recipients' => $recipients->count(),
        ]);
    }

    /**
     * Destinataires des rappels de dette : les utilisateurs habilités à
     * traiter un remboursement (mêmes rôles que canAccessPanel() côté
     * gestion financière), pas tous les comptes du système.
     */
    protected function getDebtReminderRecipients()
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['master', 'admin']))->get();
    }

    /**
     * Récupère les utilisateurs admin
     */
    protected function getAdminUsers()
    {
        return User::whereHas('roles', fn ($query) => $query->whereIn('name', ['master', 'admin']))->get();
    }

    /**
     * Configure les seuils d'alerte
     */
    public function setThresholds(float $warning, float $critical): self
    {
        $this->warningThreshold = $warning;
        $this->criticalThreshold = $critical;

        return $this;
    }
}
