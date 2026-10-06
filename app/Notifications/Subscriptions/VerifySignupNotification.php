<?php

namespace App\Notifications\Subscriptions;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail de vérification de la souscription publique (envoi synchrone, à une adresse non encore enregistrée). */
class VerifySignupNotification extends Notification
{
    public function __construct(public readonly string $url, private readonly string $company, private readonly int $hours) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirmez la création de votre entreprise')
            ->greeting('Bonjour,')
            ->line("Vous avez demandé la création de l'entreprise « {$this->company} ». Confirmez votre adresse e-mail pour démarrer votre essai gratuit.")
            ->action('Confirmer et démarrer mon essai', $this->url)
            ->line("Ce lien est valable {$this->hours} heures et ne fonctionne qu'une fois. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : rien ne sera créé.");
    }
}
