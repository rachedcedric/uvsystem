<?php

namespace App\Filament\Resources;

use App\Exports\ApprovisionnementExport;
use App\Filament\Resources\ApprovisionnementResource\Pages;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ApprovisionnementResource extends Resource
{
    protected static ?string $model = Approvisionnement::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Approvisionnements';

    protected static ?string $modelLabel = 'Approvisionnement';

    protected static ?string $pluralModelLabel = 'Approvisionnements';

    protected static ?string $navigationGroup = 'Gestion Approvisionnement';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('📦 Détails Approvisionnement')
                    ->description('Enregistrement d\'un nouvel approvisionnement - Le montant sera automatiquement ajouté au solde principal')
                    ->icon('heroicon-o-truck')
                    ->schema([
                        Forms\Components\Select::make('fournisseur_id')
                            ->label('Fournisseur')
                            ->options(Fournisseur::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $fournisseur = Fournisseur::find($state);
                                    if ($fournisseur) {
                                        $set('_fournisseur_info', "Type: {$fournisseur->type} | Contact: {$fournisseur->phone}");
                                    }
                                }
                            })
                            ->columnSpan(2)
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom du fournisseur')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label('Téléphone')
                                    ->tel()
                                    ->required(),
                                Forms\Components\Select::make('type')
                                    ->options([
                                        'AGENT_PREMIUM' => 'Agent Premium',
                                        'BANQUE' => 'Banque',
                                    ])
                                    ->required()
                                    ->default('BANQUE'),
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Actif')
                                    ->default(true),
                            ]),

                        Forms\Components\Placeholder::make('_fournisseur_info')
                            ->label('Informations')
                            ->content(fn ($get) => $get('_fournisseur_info') ?? 'Sélectionnez un fournisseur')
                            ->columnSpan(1)
                            ->visible(fn ($get) => $get('fournisseur_id')),

                        Forms\Components\TextInput::make('amount')
                            ->label('Montant de l\'approvisionnement')
                            ->required()
                            ->autocomplete(false)
                            ->money(min: 1)
                            ->prefix('💰')
                            ->helperText('Ce montant sera ajouté au solde principal')
                            ->columnSpan(1),

                        Forms\Components\DateTimePicker::make('date_approvisionnement')
                            ->label('Date et heure d\'approvisionnement')
                            ->required()
                            ->default(now())
                            ->maxDate(now())
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d/m/Y H:i')
                            ->columnSpan(1),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notes / Observations')
                            ->rows(2)
                            ->placeholder('Détails supplémentaires sur cet approvisionnement...')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('📋 Informations Système')
                    ->schema([
                        Forms\Components\Placeholder::make('reference')
                            ->label('Référence')
                            ->content(fn ($record) => $record?->reference ?? 'Sera générée automatiquement (APP-XXXXX)')
                            ->helperText('Référence unique générée par le système'),

                        Forms\Components\Placeholder::make('created_info')
                            ->label('Informations de création')
                            ->content(fn ($record) => $record
                                ? 'Créé le '.$record->created_at->format('d/m/Y à H:i').' par '.($record->user->name ?? 'Système')
                                : 'Non encore créé')
                            ->visible(fn ($record) => $record !== null),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Détails de l\'approvisionnement')
                    ->schema([
                        Infolists\Components\TextEntry::make('reference')
                            ->label('Référence')
                            ->icon('heroicon-o-hashtag'),

                        Infolists\Components\TextEntry::make('fournisseur.name')
                            ->label('Fournisseur'),

                        Infolists\Components\TextEntry::make('amount')
                            ->label('Montant')
                            ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                            ->weight('bold'),

                        Infolists\Components\TextEntry::make('solde_uv_avant')
                            ->label('Solde Avant')
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 0, ',', ' ').' FCFA' : 'Non disponible'),

                        Infolists\Components\TextEntry::make('solde_uv_apres')
                            ->label('Solde Après')
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 0, ',', ' ').' FCFA' : 'Non disponible')
                            ->weight('bold')
                            ->color('success'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Date')
                            ->date('d/m/Y'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Heure')
                            ->time('H:i:s'),

                        Infolists\Components\TextEntry::make('user.name')
                            ->label('Enregistré par')
                            ->placeholder('Système'),

                        Infolists\Components\TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Référence copiée ✓')
                    ->icon('heroicon-o-hashtag')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('fournisseur.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Approvisionnement $record): string => $record->fournisseur->phone ?? '')
                    ->icon('heroicon-o-building-storefront')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('fournisseur.type')
                    ->label('Type')
                    ->badge()
                    ->colors([
                        'success' => 'AGENT_PREMIUM',
                        'info' => 'BANQUE',
                    ])
                    ->icons([
                        'heroicon-o-star' => 'AGENT_PREMIUM',
                        'heroicon-o-building-library' => 'BANQUE',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'AGENT_PREMIUM' => 'Premium',
                        'BANQUE' => 'Banque',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable()
                    ->alignEnd()
                    ->weight('bold')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Large)
                    ->color('success')
                    ->icon('heroicon-o-banknotes'),

                Tables\Columns\TextColumn::make('solde_uv_avant')
                    ->label('Solde Avant')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 0, ',', ' ').' FCFA' : 'Non disponible')
                    ->color(fn ($state) => $state !== null ? 'gray' : 'warning')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('solde_uv_apres')
                    ->label('Solde Après')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 0, ',', ' ').' FCFA' : 'Non disponible')
                    ->weight('bold')
                    ->color(fn ($state) => $state !== null ? 'success' : 'warning')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('date_approvisionnement')
                    ->label('Date et heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->icon('heroicon-o-calendar')
                    ->description(fn (Approvisionnement $record): string => $record->created_at->diffForHumans()),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->notes)
                    ->searchable()
                    ->toggleable()
                    ->icon('heroicon-o-document-text')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn ($record) => $record->user?->name ?? 'Système'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('fournisseur_id')
                    ->label('Fournisseur')
                    ->relationship('fournisseur', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Type Fournisseur')
                    ->options([
                        'AGENT_PREMIUM' => '⭐ Agent Premium',
                        'BANQUE' => '🏦 Banque',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value']) {
                            $query->whereHas('fournisseur', function ($q) use ($data) {
                                $q->where('type', $data['value']);
                            });
                        }
                    }),

                Tables\Filters\Filter::make('date_approvisionnement')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('Du')
                            ->native(false),
                        Forms\Components\DatePicker::make('date_to')
                            ->label('Au')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date_approvisionnement', '>=', $date),
                            )
                            ->when(
                                $data['date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date_approvisionnement', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['date_from'] && ! $data['date_to']) {
                            return null;
                        }

                        return 'Du '.($data['date_from'] ?? '...').' au '.($data['date_to'] ?? '...');
                    }),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->color('info'),
                    Tables\Actions\EditAction::make()
                        ->color('warning'),
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Actions'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('Exporter Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->modalHeading('📊 Exporter les Approvisionnements')
                    ->modalDescription('Sélectionnez les critères d\'export')
                    ->modalSubmitActionLabel('Télécharger')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('Du')
                            ->native(false),
                        Forms\Components\DatePicker::make('date_to')
                            ->label('Au')
                            ->native(false),
                        Forms\Components\Select::make('fournisseur_id')
                            ->label('Fournisseur (optionnel)')
                            ->options(Fournisseur::pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function (array $data) {
                        return Excel::download(
                            new ApprovisionnementExport(
                                $data['date_from'] ?? null,
                                $data['date_to'] ?? null,
                                $data['fournisseur_id'] ?? null
                            ),
                            'approvisionnements_'.now()->format('Y-m-d_His').'.xlsx'
                        );
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date_approvisionnement', 'desc')
            ->emptyStateHeading('Aucun approvisionnement')
            ->emptyStateDescription('Commencez par enregistrer votre premier approvisionnement')
            ->emptyStateIcon('heroicon-o-truck')
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovisionnements::route('/'),
            'create' => Pages\CreateApprovisionnement::route('/create'),
            'edit' => Pages\EditApprovisionnement::route('/{record}/edit'),
        ];
    }
}
