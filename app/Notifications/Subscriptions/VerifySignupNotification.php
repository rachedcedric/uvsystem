<?php

namespace App\Notifications\Subscriptions;

use App\Cms\Content;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de vérification de la souscription publique (envoi synchrone, à une adresse non encore enregistrée).
 * Textes modifiables dans le CMS (repères {company}, {hours}).
 */
class VerifySignupNotification extends Notification
{
    public function __construct(public readonly string $url, private readonly string $company, private readonly int $hours) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = ['company' => $this->company, 'hours' => (string) $this->hours];

        $mail = (new MailMessage)
            ->subject(Content::text('emails.verify_signup.subject', $vars))
            ->greeting(Content::text('emails.common.greeting'));

        foreach (Content::lines('emails.verify_signup.intro', $vars) as $line) {
            $mail->line($line);
        }

        $mail->action(Content::text('emails.verify_signup.button', $vars), $this->url);

        foreach (Content::lines('emails.verify_signup.outro', $vars) as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
