<?php

namespace App\Notifications;

use App\Contracts\PushNotification as PushNotificationContract;
use App\Models\Agent;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\Concerns\RequiresPlanFeature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DebtAlertNotification extends Notification implements PushNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Agent $agent,
        protected string $alertType = 'warning', // warning, critical, reminder
        protected ?float $debtRatio = null,
    ) {
        $this->debtRatio = $debtRatio ?? ($agent->debt_limit > 0
            ? ($agent->current_debt / $agent->debt_limit * 100)
            : 0);
    }

    use RequiresPlanFeature;

    protected function requiredFeature(): string
    {
        return 'notifications_dettes';
    }

    public function via(object $notifiable): array
    {
        return $this->planAllows($notifiable) ? ['mail', 'database', FirebasePushChannel::class] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->alertType) {
            'critical' => "🚨 ALERTE CRITIQUE - Dette agent {$this->agent->name}",
            'reminder' => "📢 Rappel de paiement - {$this->agent->name}",
            default => "⚠️ Alerte dette - Agent {$this->agent->name}",
        };

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Bonjour,')
            ->line($this->getAlertMessage());

        if ($this->alertType === 'critical') {
            $message->error();
        }

        return $message
            ->line("**Agent:** {$this->agent->name}")
            ->line("**Téléphone:** {$this->agent->phone}")
            ->line('**Dette actuelle:** '.number_format($this->agent->current_debt, 0, ',', ' ').' FCFA')
            ->line('**Plafond autorisé:** '.number_format($this->agent->debt_limit, 0, ',', ' ').' FCFA')
            ->line("**Ratio d'utilisation:** ".number_format($this->debtRatio, 1).'%')
            ->action('Voir la fiche agent', url("/master/agents/{$this->agent->id}"))
            ->line("Merci d'utiliser ".config('branding.name').'.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->pushPayload()['title'],
            'body' => $this->getAlertMessage(),
            'url' => $this->pushPayload()['url'],
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'agent_phone' => $this->agent->phone,
            'current_debt' => $this->agent->current_debt,
            'debt_limit' => $this->agent->debt_limit,
            'debt_ratio' => $this->debtRatio,
            'alert_type' => $this->alertType,
            'message' => $this->getAlertMessage(),
        ];
    }

    public function pushPayload(): array
    {
        $severity = $this->alertType === 'critical' ? 'Dette critique' : 'Alerte dette';

        return [
            'title' => "{$severity} — {$this->agent->name}",
            'body' => 'Dette : '.number_format((float) $this->agent->current_debt, 0, ',', ' ')." FCFA ({$this->debtRatio}% du plafond)",
            'url' => "/m/dettes/agent/{$this->agent->id}",
            'type' => 'agent_debt_alert',
        ];
    }

    protected function getAlertMessage(): string
    {
        return match ($this->alertType) {
            'critical' => "L'agent {$this->agent->name} a atteint un niveau de dette CRITIQUE ({$this->debtRatio}% du plafond). Action immédiate requise.",
            'reminder' => "Rappel de paiement envoyé à l'agent {$this->agent->name}. Dette en cours: ".number_format($this->agent->current_debt, 0, ',', ' ').' FCFA',
            default => "L'agent {$this->agent->name} a atteint {$this->debtRatio}% de son plafond de dette. Surveillance recommandée.",
        };
    }
}
