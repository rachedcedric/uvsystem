<?php

namespace App\Notifications\Subscriptions;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base des notifications d'abonnement : canaux mail + base (déjà utilisés par le projet). Ne contient que des
 * valeurs simples (pas de modèle). Volontairement NON mises en file : l'envoi est synchrone et piloté par
 * SubscriptionNotifier (anti-doublon, échec conservé et repris), pour qu'un échec ne soit jamais invisible.
 */
abstract class SubscriptionNotification extends Notification
{
    /** @param  array<string, scalar|null>  $context  tenant, offre, échéance, jours… */
    public function __construct(public array $context = []) {}

    abstract public static function type(): string;

    abstract protected function subject(): string;

    /** @return list<string> */
    abstract protected function lines(): array;

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject())->greeting('Bonjour,');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action('Voir mon abonnement', url('/m/abonnement'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->subject(),
            'body' => implode(' ', $this->lines()),
            'message' => implode(' ', $this->lines()),
            'url' => url('/m/abonnement'),
            'type' => static::type(),
        ] + $this->context;
    }

    protected function plan(): string
    {
        return (string) ($this->context['plan'] ?? 'votre offre');
    }

    protected function date(): string
    {
        return (string) ($this->context['ends_at'] ?? '');
    }

    protected function days(): int
    {
        return (int) ($this->context['days'] ?? 0);
    }

    protected function amount(): string
    {
        return number_format((int) ($this->context['amount'] ?? 0), 0, ',', ' ').' FCFA';
    }

    protected function cycle(): string
    {
        return (string) ($this->context['cycle'] ?? '');
    }
}
