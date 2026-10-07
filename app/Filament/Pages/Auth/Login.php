<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Connexion de l'espace entreprise. Seuls les LIBELLÉS changent (et le logo, affiché par le thème : voir
 * AdminPanelProvider) : l'authentification, la limitation de tentatives, la validation, la vérification d'accès au
 * panneau (rôle métier dans un tenant actif) et les redirections restent celles de Filament.
 */
class Login extends BaseLogin
{
    public function getHeading(): string|Htmlable
    {
        return 'Bon retour';
    }

    public function getSubHeading(): string|Htmlable|null
    {
        return 'Connectez-vous pour suivre vos ventes, vos dettes et votre caisse.';
    }

    /** Le logo (grand format, fond blanc) est affiché par un hook du thème : on évite le doublon. */
    public function hasLogo(): bool
    {
        return false;
    }
}
