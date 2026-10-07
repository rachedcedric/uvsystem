<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Models\PaymentMethod;
use App\Tenancy\TenantContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Modes de paiement';

    protected static ?string $modelLabel = 'Mode de paiement';

    protected static ?string $pluralModelLabel = 'Modes de paiement';

    protected static ?string $navigationGroup = 'Paramètres du tenant';

    protected static ?int $navigationSort = 100;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_system_settings');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->can('edit_system_settings');
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->can('edit_system_settings');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->can('edit_system_settings');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations du mode de paiement')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->unique(ignoreRecord: true, modifyRuleUsing: TenantContext::scopeUnique())
                            ->maxLength(50)
                            ->alphaDash()
                            ->helperText('Identifiant unique (ex: cash, mobile_money, virement)')
                            ->placeholder('mobile_money'),

                        Forms\Components\TextInput::make('name')
                            ->label('Nom affiché')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Mobile Money'),

                        Forms\Components\TextInput::make('icon')
                            ->label('Icône (emoji)')
                            ->maxLength(10)
                            ->placeholder('📱')
                            ->helperText('Emoji affiché devant le nom (ex: 💵 📱 🏦 📝)'),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->maxLength(255)
                            ->rows(2)
                            ->placeholder('Description optionnelle du mode de paiement'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Ordre d\'affichage')
                            ->numeric()
                            ->default(0)
                            ->helperText('Les modes avec un ordre plus petit apparaissent en premier'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true)
                            ->helperText('Désactiver pour masquer ce mode sans le supprimer'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width('50px'),

                Tables\Columns\TextColumn::make('icon')
                    ->label('Icône')
                    ->width('60px')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('remboursements_count')
                    ->label('Utilisations')
                    ->counts('remboursements')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->placeholder('Tous')
                    ->trueLabel('Actifs uniquement')
                    ->falseLabel('Inactifs uniquement'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (PaymentMethod $record) {
                        // Empêcher la suppression si utilisé
                        if ($record->remboursements()->count() > 0) {
                            throw new \Exception('Ce mode de paiement est utilisé par des remboursements. Désactivez-le plutôt.');
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('sort_order');
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
            'index' => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit' => Pages\EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
