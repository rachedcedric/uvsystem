<?php

namespace App\Tenancy;

use Illuminate\Validation\ValidationException;

/**
 * Un enregistrement du tenant courant tente de référencer un parent d'un autre tenant.
 *
 * C'est une ValidationException : Filament et Livewire l'affichent sur le champ fautif, l'API répond
 * 422, un service dans une transaction annule tout. Le message ne révèle rien du parent refusé.
 */
class CrossTenantReference extends ValidationException
{
    public static function forField(string $field, string $parent): static
    {
        return static::withMessages([
            $field => "Référence refusée : cet élément ({$parent}) n'appartient pas à votre société.",
        ]);
    }
}
