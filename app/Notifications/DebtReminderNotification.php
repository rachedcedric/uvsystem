<?php

namespace App\Notifications;

use App\Models\Agent;
use Illuminate\Notifications\Notification;

/**
 * Rappel automatique (toutes les 2h) pour un agent ayant une dette non
 * soldée. Stockée en base uniquement (widget "🔔 Notifications Récentes" et
 * centre de notifications) : contrairement à DebtAlertNotification /
 * PaymentReminderNotification, volontairement sans canal mail pour éviter
 * une notification email toutes les 2h par agent endetté.
 *
 * Le tableau retourné par toArray() suit exactement la convention déjà
 * utilisée par les autres notifications de l'app (title/body/url au premier
 * niveau) : c'est ce que lisent NotificationsRecentesWidget et
 * NotificationsPage pour l'affichage et le clic.
 */
class DebtReminderNotification extends Notification
{
    public function __construct(
        protected Agent $agent,
        protected string $reminderCycle,
        protected float $totalInitial,
        protected float $totalRembourse,
        protected float $resteDu,
        protected string $debtStatusLabel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $reste = number_format($this->resteDu, 0, ',', ' ');

        return [
            'title' => "⚠️ Dette en cours — Agent : {$this->agent->name} — Reste à payer : {$reste} FCFA",
            'body' => sprintf(
                "Dette initiale : %s FCFA\nDéjà remboursé : %s FCFA\nReste à payer : %s FCFA\nStatut : %s",
                number_format($this->totalInitial, 0, ',', ' '),
                number_format($this->totalRembourse, 0, ',', ' '),
                $reste,
                $this->debtStatusLabel,
            ),
            'url' => "/admin/remboursements/create?agent_id={$this->agent->id}",
            'type' => 'warning',
            'notification_kind' => 'debt_reminder',
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'reminder_cycle' => $this->reminderCycle,
            'total_initial' => $this->totalInitial,
            'total_rembourse' => $this->totalRembourse,
            'reste_du' => $this->resteDu,
            'debt_status_label' => $this->debtStatusLabel,
        ];
    }
}
