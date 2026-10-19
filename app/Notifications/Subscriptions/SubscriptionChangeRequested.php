<?php

namespace App\Notifications\Subscriptions;

use App\Cms\Content;

class SubscriptionChangeRequested extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_change_requested';
    }

    public static function label(): string
    {
        return 'Demande de changement d\'offre';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Demande de changement d\'offre',
            'lines' => [
                'Une demande de passage à l\'offre {plan} ({cycle}) a été enregistrée.',
                'Montant à régler : {amount}. Le changement s\'applique dès la réception du paiement.',
            ],
        ];
    }

    /** Texte du corps quand il n'y a rien à payer (le changement est appliqué immédiatement). */
    public static function freeLines(): array
    {
        return ['Une demande de passage à l\'offre {plan} ({cycle}) a été enregistrée.', 'Aucun montant à régler : le changement est appliqué immédiatement.'];
    }

    protected function lines(): array
    {
        return (int) ($this->context['amount'] ?? 0) > 0
            ? parent::lines()
            : Content::lines('emails.'.static::type().'.body_free', $this->vars());
    }
}
