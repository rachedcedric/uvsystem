<?php

namespace App\Notifications\Subscriptions;

use App\Cms\Content;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base des notifications d'abonnement : canaux mail + base (déjà utilisés par le projet). Ne contient que des
 * valeurs simples (pas de modèle). Volontairement NON mises en file : l'envoi est synchrone et piloté par
 * SubscriptionNotifier (anti-doublon, échec conservé et repris), pour qu'un échec ne soit jamais invisible.
 *
 * Les textes (objet et corps) sont ceux du CMS (/platform > CMS > E-mails et messages) ; les valeurs par défaut
 * sont définies ici dans defaults(), avec des repères {plan}, {cycle}, {date}, {days}, {amount}.
 */
abstract class SubscriptionNotification extends Notification
{
    /** @param  array<string, scalar|null>  $context  tenant, offre, échéance, jours… */
    public function __construct(public array $context = []) {}

    abstract public static function type(): string;

    /** Nom lisible dans le CMS. */
    abstract public static function label(): string;

    /** @return array{subject: string, lines: list<string>} textes par défaut, avec repères */
    abstract public static function defaults(): array;

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject())->greeting(Content::text('emails.common.greeting'));

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action(Content::text('emails.common.action'), url('/m/abonnement'));
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

    protected function subject(): string
    {
        return Content::text('emails.'.static::type().'.subject', $this->vars());
    }

    /** @return list<string> */
    protected function lines(): array
    {
        return Content::lines('emails.'.static::type().'.body', $this->vars());
    }

    /** @return array<string, string> repères utilisables dans les textes */
    protected function vars(): array
    {
        return [
            'plan' => (string) ($this->context['plan'] ?? 'votre offre'),
            'cycle' => (string) ($this->context['cycle'] ?? ''),
            'date' => (string) ($this->context['ends_at'] ?? ''),
            'days' => (string) (int) ($this->context['days'] ?? 0),
            'amount' => number_format((int) ($this->context['amount'] ?? 0), 0, ',', ' ').' FCFA',
        ];
    }
}
