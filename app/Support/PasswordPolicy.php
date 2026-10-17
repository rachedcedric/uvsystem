<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** Règle unique de mot de passe : mobile, panneaux Filament, plateforme, inscription, réinitialisation. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const HINT = '12 caractères minimum, avec majuscule, minuscule, chiffre et symbole';

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers()->symbols();
    }
}
