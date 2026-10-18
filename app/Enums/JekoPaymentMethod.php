<?php

namespace App\Enums;

enum JekoPaymentMethod: string
{
    case Wave = 'wave';
    case Orange = 'orange';
    case Mtn = 'mtn';
    case Moov = 'moov';
    case Djamo = 'djamo';
    case Jeko = 'jeko';

    public function label(): string
    {
        return match ($this) {
            self::Wave => 'Wave',
            self::Orange => 'Orange Money',
            self::Mtn => 'MTN Money',
            self::Moov => 'Moov Money',
            self::Djamo => 'Djamo',
            self::Jeko => 'Jèko',
        };
    }
}
