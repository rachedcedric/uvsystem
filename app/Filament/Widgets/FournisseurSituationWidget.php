<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DettesFournisseursPage;
use App\Models\Fournisseur;
use App\Services\FournisseurDebtService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tuiles de la fiche d'un fournisseur : approvisionnements, remboursements, dette, découvert (signé « − ») et
 * solde net. Aucun calcul propre : délègue à FournisseurDebtService::situation().
 */
class FournisseurSituationWidget extends BaseWidget
{
    public Fournisseur $fournisseur;

    protected static ?string $pollingInterval = '30s';

    public function mount(Fournisseur $fournisseur): void
    {
        $this->fournisseur = $fournisseur;
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $s = app(FournisseurDebtService::class)->situation($this->fournisseur);
        $money = fn (float $n) => DettesFournisseursPage::money($n);
        $last = $s['derniere_operation'];

        return [
            Stat::make('Total des approvisionnements', $money($s['total_approvisionne']))
                ->description($s['credit'] ? $s['nb_approvisionnements'].' approvisionnement(s) à crédit' : 'Payés comptant : aucune dette')
                ->icon('heroicon-o-truck')
                ->color('primary'),

            Stat::make('Total des remboursements', $money($s['total_rembourse']))
                ->description($s['nb_remboursements'].' paiement(s)'.($s['nb_annules'] ? ' · '.$s['nb_annules'].' annulé(s)' : ''))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success'),

            Stat::make('Solde net', $money($s['solde']))
                ->description($s['statut_label'])
                ->icon('heroicon-o-scale')
                ->color(DettesFournisseursPage::color($s['solde'])),

            Stat::make('Dette actuelle', $money($s['dette']))
                ->description('À payer au fournisseur')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($s['dette'] > 0 ? 'danger' : 'gray'),

            Stat::make('Découvert actuel', $money($s['decouvert']))
                ->description('Avance versée par le Master')
                ->icon('heroicon-o-banknotes')
                ->color($s['decouvert'] < 0 ? 'success' : 'gray'),

            Stat::make('Dernière opération', $last ? $money($last['montant']) : '—')
                ->description($last ? $last['libelle'].' · '.$last['date']->format('d/m/Y H:i').' · '.$last['reference'] : 'Aucune opération')
                ->icon('heroicon-o-clock')
                ->color('gray'),
        ];
    }
}
