<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ChargesPage;
use App\Filament\Pages\MaCaissePage;
use App\Filament\Resources\AgentResource;
use App\Filament\Resources\ApprovisionnementResource;
use App\Filament\Resources\RemboursementResource;
use App\Filament\Resources\UvTransactionResource;
use Filament\Widgets\Widget;

/**
 * Raccourcis vers les opérations les plus fréquentes. Chaque raccourci n'apparaît que si l'utilisateur a le droit
 * correspondant (même contrôle que la page de destination : politique de la ressource ou permission) : le widget
 * ne donne aucun accès nouveau, il évite seulement des clics. Aucune donnée n'est lue ici.
 */
class QuickActionsWidget extends Widget
{
    protected static string $view = 'filament.widgets.quick-actions-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /** @return list<array{label: string, hint: string, icon: string, url: string}> */
    public static function actions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $candidates = [
            ['Nouvelle vente', 'Distribuer des UV à un agent', 'heroicon-o-shopping-cart', fn () => UvTransactionResource::canCreate(), fn () => UvTransactionResource::getUrl('create')],
            ['Nouveau remboursement', 'Encaisser le paiement d\'un agent', 'heroicon-o-arrow-uturn-left', fn () => RemboursementResource::canCreate(), fn () => RemboursementResource::getUrl('create')],
            ['Approvisionnement', 'Ajouter des UV au solde', 'heroicon-o-truck', fn () => ApprovisionnementResource::canCreate(), fn () => ApprovisionnementResource::getUrl('create')],
            ['Mouvement de caisse', 'Entrée ou sortie d\'argent', 'heroicon-o-wallet', fn () => $user->can('create_caisse') && MaCaissePage::canAccess(), fn () => MaCaissePage::getUrl()],
            ['Nouvelle charge', 'Enregistrer une dépense', 'heroicon-o-receipt-percent', fn () => $user->can('create_charges') && ChargesPage::canAccess(), fn () => ChargesPage::getUrl()],
            ['Nouvel agent', 'Ajouter un agent à votre réseau', 'heroicon-o-user-plus', fn () => AgentResource::canCreate(), fn () => AgentResource::getUrl('create')],
        ];

        $actions = [];
        foreach ($candidates as [$label, $hint, $icon, $allowed, $url]) {
            if ($allowed()) {
                $actions[] = ['label' => $label, 'hint' => $hint, 'icon' => $icon, 'url' => $url()];
            }
        }

        return $actions;
    }

    public static function canView(): bool
    {
        return self::actions() !== [];
    }

    /** @return list<array{label: string, hint: string, icon: string, url: string}> */
    public function getActions(): array
    {
        return self::actions();
    }
}
