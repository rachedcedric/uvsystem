<?php

namespace App\Filament\Pages;

use App\Filament\Resources\RemboursementFournisseurResource;
use App\Models\Fournisseur;
use App\Services\FournisseurDebtService;
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

        return $table
            ->query(Fournisseur::query()->creditFournisseurs())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Fournisseur $record) => $record->contact ?? ''),

                Tables\Columns\TextColumn::make('reste')
                    ->label('Reste à payer')
                    ->getStateUsing(fn (Fournisseur $record) => $service->detteRestante($record))
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' FCFA')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('debt_status')
                    ->label('Statut')
                    ->badge()
                    ->getStateUsing(fn (Fournisseur $record) => $service->detteRestante($record) > 0 ? 'En cours' : 'Soldée')
                    ->color(fn ($state) => $state === 'Soldée' ? 'success' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('encaisser')
                    ->label('Payer')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->url(fn (Fournisseur $record) => RemboursementFournisseurResource::getUrl('create', ['fournisseur_id' => $record->id]))
                    ->visible(fn (Fournisseur $record) => $service->detteRestante($record) > 0 && (Auth::user()?->can('create_remboursements_fournisseurs') ?? false)),
            ])
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->poll('30s')
            ->emptyStateHeading('Aucun fournisseur à crédit')
            ->emptyStateDescription('Aucun fournisseur "Agent Premium" actif pour le moment.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
