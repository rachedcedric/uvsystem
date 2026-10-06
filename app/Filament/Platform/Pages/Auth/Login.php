<?php

namespace App\Filament\Platform\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Connexion de la console plateforme. Seuls les LIBELLÉS changent : l'authentification, la limitation de tentatives,
 * la validation et les redirections restent celles de Filament (aucune logique d'accès n'est redéfinie ici).
 * La mise en forme est dans public/css/filament-platform.css.
 */
class Login extends BaseLogin
{
    public function getHeading(): string|Htmlable
    {
        return 'Console d\'administration';
    }

    public function getSubHeading(): string|Htmlable|null
    {
        return 'Accès réservé aux administrateurs de la plateforme.';
    }
}
