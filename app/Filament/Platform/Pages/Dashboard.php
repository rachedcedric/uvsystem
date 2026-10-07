<?php

namespace App\Filament\Platform\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Tableau de bord de la console plateforme : identique à celui de Filament (widgets découverts dans
 * Filament/Platform/Widgets) ; seule sa place dans le menu change (son propre groupe « Pilotage », en premier).
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationGroup = 'Pilotage';

    protected static ?int $navigationSort = 1;

    /** Comme toutes les pages de la console : réservé au super admin de plateforme (User::isSuperAdmin()). */
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
