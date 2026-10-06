<?php

namespace App\Notifications;

use App\Contracts\PushNotification as PushNotificationContract;
use App\Models\Agent;
use App\Notifications\Channels\FirebasePushChannel;
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
class DebtReminderNotification extends Notification implements PushNotificationContract
{
    public function __construct(
        protected Agent $agent,
        protected string $reminderCycle,
        protected float $totalInitial,
        protected float $totalRembourse,
        protected float $resteDu,
        protected string $debtStatusLabel,
    ) {}

    use \App\Notifications\Concerns\RequiresPlanFeature;

    protected function requiredFeature(): string
    {
        return 'notifications_dettes';
    }

    public function via(object $notifiable): array
    {
        return $this->planAllows($notifiable) ? ['database', FirebasePushChannel::class] : [];
    }

    public function toArray(object $notifiable): array
    {
        $reste = number_format($this->resteDu, 0, ',', ' ');

        return [
            // Champs attendus par Filament\Notifications\Notification::fromArray()
            // (vendor/filament/notifications/src/Notification.php) : sans
            // 'format' => 'filament', la cloche du panel (qui filtre les
            // notifications avec `where('data->format', 'filament')`, voir
            // Filament\Notifications\Livewire\DatabaseNotifications::getNotificationsQuery())
            // ignore purement et simplement cette notification, même si elle
            // existe bien en base et s'affiche dans le widget "Notifications
            // Récentes" (qui lit data.title directement, sans ce filtre).
            'format' => 'filament',
            'title' => "⚠️ Dette en cours — Agent : {$this->agent->name} — Reste à payer : {$reste} FCFA",
            'body' => null,
            'icon' => 'heroicon-o-exclamation-triangle',
            'iconColor' => 'warning',
            'color' => 'warning',
            'status' => 'warning',
            'duration' => 'persistent',
            'view' => null,
            'viewData' => [],
            'actions' => [],

            // Champs métier propres, lus directement par
            // NotificationsRecentesWidget (data.title/data.url) et
            // NotificationsPage::lastReminderAt() (data->agent_id).
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

    public function pushPayload(): array
    {
        return [
            'title' => "Dette agent — {$this->agent->name}",
            'body' => number_format($this->resteDu, 0, ',', ' ').' FCFA restant dû',
            'url' => "/m/dettes/agent/{$this->agent->id}",
            'type' => 'agent_debt_reminder',
        ];
    }
}
