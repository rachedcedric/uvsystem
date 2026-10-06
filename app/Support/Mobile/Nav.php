<?php

namespace App\Support\Mobile;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Navigation de l'interface mobile : onglets du bas, accès rapides et menu
 * « Plus », filtrés par permissions Spatie (les mêmes que dans Filament).
 * Une entrée n'apparaît que si sa route existe et que l'utilisateur a le droit.
 */
class Nav
{
    /**
     * @return list<array{label:string, route:string, icon:string, match:string}>
     */
    public static function tabs(?User $user): array
    {
        $home = ['label' => 'Accueil', 'route' => 'm.home', 'icon' => 'home', 'match' => '/m', 'exact' => true, 'can' => null];

        $others = [
            ['label' => 'Agents', 'route' => 'm.agents', 'icon' => 'users', 'match' => '/m/agents', 'can' => 'view_agents'],
            ['label' => 'Ventes', 'route' => 'm.ventes', 'icon' => 'banknotes', 'match' => '/m/ventes', 'can' => 'view_uv_transactions'],
            ['label' => 'Caisse', 'route' => 'm.caisse', 'icon' => 'wallet', 'match' => '/m/caisse', 'can' => 'view_caisse'],
            ['label' => 'Plus', 'route' => 'm.more', 'icon' => 'squares-2x2', 'match' => '/m/plus', 'can' => null],
        ];

        // Accueil au centre de la barre : on le glisse au milieu des onglets visibles pour cet utilisateur
        // (un onglet masqué par les permissions ne doit pas le décaler vers un bord).
        $others = self::visible($others, $user);
        array_splice($others, intdiv(count($others), 2), 0, [$home]);

        return $others;
    }

    /**
     * Actions rapides (feuille « + »).
     *
     * @return list<array{label:string, route:string, icon:string, hint:string}>
     */
    public static function quickActions(?User $user): array
    {
        $actions = [
            ['label' => 'Nouvelle vente UV', 'hint' => 'Distribuer des UV à un agent', 'route' => 'm.ventes.create', 'icon' => 'banknotes', 'can' => 'create_uv_transactions'],
            ['label' => 'Nouveau remboursement', 'hint' => 'Encaisser le paiement d\'un agent', 'route' => 'm.remboursements.create', 'icon' => 'arrow-uturn-left', 'can' => 'create_remboursements'],
            ['label' => 'Mouvement de caisse', 'hint' => 'Entrée ou sortie d\'argent', 'route' => 'm.caisse.create', 'icon' => 'wallet', 'can' => 'create_caisse'],
            ['label' => 'Nouvelle charge', 'hint' => 'Enregistrer une dépense', 'route' => 'm.charges.create', 'icon' => 'receipt-percent', 'can' => 'create_charges'],
            ['label' => 'Approvisionnement', 'hint' => 'Ajouter des UV au solde', 'route' => 'm.approvisionnements.create', 'icon' => 'truck', 'can' => 'create_approvisionnements'],
        ];

        return self::visible($actions, $user);
    }

    /**
     * Rubriques natives du menu « Plus » (hors onglets).
     *
     * @return list<array{label:string, route:string, icon:string}>
     */
    public static function moreItems(?User $user): array
    {
        $items = [
            ['label' => 'Dettes', 'route' => 'm.dettes', 'icon' => 'exclamation-triangle', 'can' => 'view_remboursements'],
            ['label' => 'Commissions', 'route' => 'm.commissions', 'icon' => 'currency-dollar', 'can' => 'view_commissions'],
            ['label' => 'Remboursements', 'route' => 'm.remboursements', 'icon' => 'arrow-uturn-left', 'can' => 'view_remboursements'],
            ['label' => 'Approvisionnements', 'route' => 'm.approvisionnements', 'icon' => 'truck', 'can' => 'view_approvisionnements'],
            ['label' => 'Charges', 'route' => 'm.charges', 'icon' => 'receipt-percent', 'can' => 'view_charges'],
            ['label' => 'Statistiques', 'route' => 'm.statistiques', 'icon' => 'chart-bar', 'can' => 'view_statistics'],
            ['label' => 'Scoring des agents', 'route' => 'm.scoring', 'icon' => 'trophy', 'can' => 'view_statistics'],
            ['label' => 'Rapports et exports', 'route' => 'm.rapports', 'icon' => 'document-arrow-down', 'can' => 'view_reports'],
            ['label' => 'Journal d\'activité', 'route' => 'm.activite', 'icon' => 'clipboard-document-list', 'can' => 'view_activity_log'],
            ['label' => 'Import de données', 'route' => 'm.import', 'icon' => 'arrow-up-tray', 'can' => null, 'roles' => ['master', 'admin']],
            ['label' => 'Sauvegardes', 'route' => 'm.sauvegardes', 'icon' => 'cloud-arrow-up', 'can' => null, 'roles' => ['super_admin']],
            ['label' => 'Diagnostic des notifications', 'route' => 'm.diagnostic-notifications', 'icon' => 'bell-alert', 'can' => null, 'roles' => ['master', 'admin']],
            ['label' => 'Mon abonnement', 'route' => 'm.abonnement', 'icon' => 'credit-card', 'can' => null, 'roles' => ['master', 'admin']],
            ['label' => 'Sécurité du compte', 'route' => 'm.securite', 'icon' => 'shield-check', 'can' => null],
            ['label' => 'Notifications', 'route' => 'm.notifications', 'icon' => 'bell', 'can' => null],
        ];

        return self::visible($items, $user);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private static function visible(array $items, ?User $user): array
    {
        return array_values(array_filter($items, function (array $item) use ($user): bool {
            if (! Route::has($item['route'])) {
                return false;
            }

            if (! empty($item['roles'])) {
                return (bool) $user?->hasAnyRole($item['roles']);
            }

            return $item['can'] === null || ($user?->can($item['can']) ?? false);
        }));
    }
}
