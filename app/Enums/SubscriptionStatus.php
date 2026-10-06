<?php

namespace App\Enums;

/**
 * État d'une ligne d'abonnement. `Replaced` marque une ligne historique remplacée par un changement
 * d'offre ou de cycle : elle reste consultable, jamais supprimée ni écrasée.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Essai',
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Expired => 'Expiré',
            self::Cancelled => 'Annulé',
            self::Replaced => 'Remplacé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Trialing => 'info',
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Expired, self::Cancelled => 'danger',
            self::Replaced => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
