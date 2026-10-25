<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ExportPeriodAction;
use App\Filament\Resources\FournisseurResource;
use App\Filament\Resources\RemboursementFournisseurResource;
use App\Models\Fournisseur;
use App\Services\FournisseurDebtService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Dette due aux fournisseurs "Agent Premium" : ce type de fournisseur
 * approvisionne à crédit (livré avant d'être payé), contrairement à un
 * fournisseur "Banque" (payé comptant). Une ligne de ce tableau = un
 * fournisseur à crédit ; le calcul (dette/remboursé/reste) provient
 * exclusivement de FournisseurDebtService — même source que les 3 tuiles
 * (FournisseurDebtStatsWidget) et que le formulaire d'encaissement.
 */
class DettesFournisseursPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Dettes fournisseurs';

    protected static ?string $slug = 'dettes-fournisseurs';

    protected static ?string $title = 'Dettes fournisseurs';

    protected static ?string $navigationGroup = 'Gestion Approvisionnement';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.dettes-fournisseurs-page';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_remboursements_fournisseurs');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::fournisseursEndettes()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Fournisseurs à crédit (Agent Premium) actifs ayant une dette non
     * soldée : même source (FournisseurDebtService) que le tableau, les 3
     * tuiles et le badge de menu — jamais une seconde formule.
     *
     * @return Collection<int, Fournisseur>
     */
    public static function fournisseursEndettes(): Collection
    {
        $service = app(FournisseurDebtService::class);

        return Fournisseur::query()
            ->creditFournisseurs()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Fournisseur $f) => $service->detteRestante($f) > 0)
            ->values();
    }

    public function table(Table $table): Table
    {
        $service = app(FournisseurDebtService::class);
        $soldes = $service->soldesParFournisseur();
        $solde = fn (Fournisseur $record): float => (float) ($soldes[$record->id]['solde'] ?? 0);
        $canPay = Auth::user()?->can('create_remboursements_fournisseurs') ?? false;

        return $table
            ->query(Fournisseur::query()->creditFournisseurs())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Fournisseur $record) => $record->contact ?? ''),

                Tables\Columns\TextColumn::make('total_credit')
                    ->label('Approvisionnements')
                    ->getStateUsing(fn (Fournisseur $record) => (float) ($soldes[$record->id]['credit'] ?? 0))
                    ->formatStateUsing(fn ($state) => self::money((float) $state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_rembourse')
                    ->label('Remboursements')
                    ->getStateUsing(fn (Fournisseur $record) => (float) ($soldes[$record->id]['rembourse'] ?? 0))
                    ->formatStateUsing(fn ($state) => self::money((float) $state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reste')
                    ->label('Solde net')
                    ->getStateUsing($solde)
                    ->formatStateUsing(fn ($state) => self::money((float) $state))
                    ->description(fn ($state) => match (FournisseurDebtService::statutPour((float) $state)) {
                        FournisseurDebtService::STATUT_DETTE => 'Dette à payer',
                        FournisseurDebtService::STATUT_DECOUVERT => 'Avance disponible',
                        default => 'Compte équilibré',
                    })
                    ->weight('bold')
                    ->color(fn ($state) => self::color((float) $state))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('debt_status')
                    ->label('Statut')
                    ->badge()
                    ->getStateUsing(fn (Fournisseur $record) => FournisseurDebtService::STATUT_LABELS[FournisseurDebtService::statutPour($solde($record))])
                    ->color(fn ($state) => match ($state) {
                        'Dette' => 'danger',
                        'Découvert' => 'success',
                        default => 'gray',
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('fiche')
                        ->label('Voir la fiche')
                        ->icon('heroicon-o-eye')
                        ->url(fn (Fournisseur $record) => FournisseurResource::getUrl('view', ['record' => $record])),
                    Tables\Actions\Action::make('encaisser')
                        ->label(fn (Fournisseur $record) => $solde($record) > 0 ? 'Payer' : 'Verser une avance')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->url(fn (Fournisseur $record) => RemboursementFournisseurResource::getUrl('create', ['fournisseur_id' => $record->id]))
                        ->visible(fn (Fournisseur $record) => $canPay && $record->is_active),
                    Tables\Actions\Action::make('paiements')
                        ->label('Historique des paiements')
                        ->icon('heroicon-o-clock')
                        ->url(fn (Fournisseur $record) => RemboursementFournisseurResource::getUrl('index', ['tableFilters' => ['fournisseur' => ['value' => $record->id]]])),
                ])->tooltip('Actions'),
            ])
            ->recordUrl(fn (Fournisseur $record) => FournisseurResource::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->poll('30s')
            ->emptyStateHeading('Aucun fournisseur à crédit')
            ->emptyStateDescription('Aucun fournisseur "Agent Premium" actif pour le moment.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /** Montant signé : le découvert garde toujours son signe « − ». */
    public static function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }

    /** Rouge : dette à payer ; vert : découvert (avance) ; gris : équilibré. */
    public static function color(float $solde): string
    {
        return match (FournisseurDebtService::statutPour($solde)) {
            FournisseurDebtService::STATUT_DETTE => 'danger',
            FournisseurDebtService::STATUT_DECOUVERT => 'success',
            default => 'gray',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('historique')->label('Historique des dettes et crédits')->icon('heroicon-o-clock')->color('gray')
                ->url(HistoriqueDettesPage::getUrl()),
            ExportPeriodAction::make('dettes_fournisseurs'),
        ];
    }
}
