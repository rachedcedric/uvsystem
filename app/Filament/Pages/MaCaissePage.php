<?php

namespace App\Filament\Pages;

use App\Models\CaisseMouvement;
use App\Services\CaisseService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Ma Caisse" : liquidité manuelle (entrées/sorties saisies à la main, avec
 * un libellé), avec un historique permettant de connaître le solde de la
 * caisse à n'importe quel moment. Aucune colonne de solde mise en cache :
 * le solde avant/après de chaque mouvement est recalculé en direct par
 * CaisseService::historyWithRunningBalance() (voir ce service pour le
 * détail) — même principe que les autres soldes du projet.
 */
class MaCaissePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationLabel = 'Ma Caisse';

    protected static ?string $slug = 'ma-caisse';

    protected static ?string $title = 'Ma Caisse';

    // Pas de groupe : la caisse est un suivi personnel de liquidité,
    // indépendant des domaines Agents/Fournisseurs/Transactions déjà
    // groupés — elle apparaît donc comme un lien de premier niveau.
    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.ma-caisse-page';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_caisse');
    }

    /**
     * Calculé une seule fois par rendu (pas par ligne du tableau) : le solde
     * avant/après de chaque mouvement dépend de tout l'historique, jamais
     * seulement de la page affichée.
     *
     * @return array<int, array{solde_avant: float, solde_apres: float}>
     */
    public function runningBalances(): array
    {
        return app(CaisseService::class)
            ->historyWithRunningBalance()
            ->keyBy(fn (array $entry) => $entry['mouvement']->id)
            ->map(fn (array $entry) => [
                'solde_avant' => $entry['solde_avant'],
                'solde_apres' => $entry['solde_apres'],
            ])
            ->all();
    }

    public static function mouvementFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Mouvement de caisse')
                ->schema([
                    Forms\Components\Radio::make('type')
                        ->label('Type')
                        ->options([
                            CaisseMouvement::TYPE_ENTREE => '💵 Entrée (ajout de liquidité)',
                            CaisseMouvement::TYPE_SORTIE => '💸 Sortie (retrait de liquidité)',
                        ])
                        ->default(CaisseMouvement::TYPE_ENTREE)
                        ->required()
                        ->inline(false)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Ex : Dépôt espèces, Achat fournitures...'),

                    Forms\Components\TextInput::make('amount')
                        ->label('Montant')
                        ->money(min: 1)
                        ->required(),

                    Forms\Components\DateTimePicker::make('date_mouvement')
                        ->label('Date et heure')
                        ->required()
                        ->default(now())
                        ->maxDate(now())
                        ->seconds(false)
                        ->native(false),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notes (facultatif)')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ];
    }

    public function table(Table $table): Table
    {
        $balances = $this->runningBalances();

        return $table
            ->query(CaisseMouvement::query()->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('date_mouvement')
                    ->label('Date et heure')
                    ->formatStateUsing(fn ($state) => $state
                        ? \Illuminate\Support\Carbon::parse($state)->translatedFormat('d/m/Y').' '.\Illuminate\Support\Carbon::parse($state)->format('H:i')
                        : '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === CaisseMouvement::TYPE_ENTREE ? 'Entrée' : 'Sortie')
                    ->color(fn (string $state) => $state === CaisseMouvement::TYPE_ENTREE ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (CaisseMouvement $record) => $record->notes ?? ''),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state, CaisseMouvement $record) => ($record->type === CaisseMouvement::TYPE_ENTREE ? '+' : '-').number_format((float) $state, 0, ',', ' ').' FCFA')
                    ->weight('bold')
                    ->color(fn (CaisseMouvement $record) => $record->type === CaisseMouvement::TYPE_ENTREE ? 'success' : 'danger')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('solde_apres')
                    ->label('Solde après')
                    ->getStateUsing(fn (CaisseMouvement $record) => $balances[$record->id]['solde_apres'] ?? null)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 0, ',', ' ').' FCFA' : '—')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Enregistré par')
                    ->placeholder('Système')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date_mouvement', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->form(static::mouvementFormSchema())
                        ->using(function (CaisseMouvement $record, array $data) {
                            try {
                                app(CaisseService::class)->validateMouvement($data['type'], (float) $data['amount']);
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Validation échouée')
                                    ->body(collect($e->errors())->flatten()->implode(' '))
                                    ->persistent()
                                    ->send();

                                throw $e;
                            }

                            $record->update($data);

                            return $record;
                        })
                        ->visible(fn () => Auth::user()?->can('edit_caisse') ?? false),

                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => Auth::user()?->can('delete_caisse') ?? false),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Actions'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add')
                    ->label('Ajouter un mouvement')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form(static::mouvementFormSchema())
                    ->visible(fn () => Auth::user()?->can('create_caisse') ?? false)
                    ->action(function (array $data) {
                        try {
                            app(CaisseService::class)->enregistrer(
                                $data['type'],
                                (float) $data['amount'],
                                $data['libelle'],
                                $data['date_mouvement'] ?? null,
                                $data['notes'] ?? null,
                            );
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Validation échouée')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Mouvement enregistré')
                            ->send();
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->poll('30s')
            ->emptyStateHeading('Aucun mouvement de caisse')
            ->emptyStateDescription('Ajoutez votre premier mouvement pour commencer le suivi de votre liquidité.')
            ->emptyStateIcon('heroicon-o-wallet');
    }
}
