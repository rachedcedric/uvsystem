<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Refus lié à l'abonnement (limite atteinte, accès restreint, fonctionnalité absente de l'offre).
 * C'est une ValidationException : Filament, Livewire et l'API la restituent comme une erreur
 * lisible (422) plutôt qu'une erreur serveur ; les écrans mobiles l'affichent dans le formulaire.
 */
class SubscriptionException extends ValidationException
{
    public static function because(string $message, string $key = 'subscription'): static
    {
        return static::withMessages([$key => [$message]]);
    }
}
