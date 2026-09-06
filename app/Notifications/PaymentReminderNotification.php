<?php

namespace App\Notifications;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Agent $agent,
        protected ?string $customMessage = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("📢 Rappel de paiement - {$this->agent->name}")
            ->greeting('Bonjour,')
            ->line("Un rappel de paiement a été envoyé à l'agent suivant:")
            ->line("**Agent:** {$this->agent->name}")
            ->line("**Téléphone:** {$this->agent->phone}")
            ->line('**Dette en cours:** '.number_format($this->agent->current_debt, 0, ',', ' ').' FCFA')
            ->when($this->customMessage, fn ($mail) => $mail->line("**Message:** {$this->customMessage}"))
            ->action('Voir la fiche agent', url("/admin/agents/{$this->agent->id}"))
            ->line("Le rappel a été enregistré dans l'historique.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'agent_phone' => $this->agent->phone,
            'current_debt' => $this->agent->current_debt,
            'custom_message' => $this->customMessage,
            'type' => 'payment_reminder',
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Génère le message SMS à envoyer à l'agent
     */
    public function toSms(): string
    {
        $debt = number_format($this->agent->current_debt, 0, ',', ' ');

        return $this->customMessage
            ?? "Bonjour {$this->agent->name}, vous avez une dette de {$debt} FCFA en attente. Merci de régulariser votre situation. - Système UV";
    }
}
