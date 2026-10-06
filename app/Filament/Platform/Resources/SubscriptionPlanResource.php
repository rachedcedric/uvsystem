<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\FeatureCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Catalogue d'offres (global) géré par le super admin. Jamais de suppression d'une offre utilisée. */
class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Abonnements';

    protected static ?string $modelLabel = 'offre';

    protected static ?string $pluralModelLabel = 'offres';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Offre')->schema([
                Forms\Components\TextInput::make('name')->label('Nom')->required()->maxLength(255),
                Forms\Components\TextInput::make('slug')->label('Identifiant')->required()->alphaDash()->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText('Sert aux inclusions « toutes les fonctions {identifiant} » : ne le changez pas à la légère.'),
                Forms\Components\Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
            ])->columns(2),
            Forms\Components\Section::make('Prix (FCFA, entiers)')->schema([
                Forms\Components\TextInput::make('monthly_price')->label('Prix mensuel')->numeric()->integer()->minValue(0)->required()->suffix('FCFA'),
                Forms\Components\TextInput::make('yearly_price')->label('Prix annuel')->numeric()->integer()->minValue(0)->required()->suffix('FCFA'),
                Forms\Components\TextInput::make('trial_days')->label('Jours d\'essai')->numeric()->integer()->minValue(0)->maxValue(365)->required()->default(14),
            ])->columns(3),
            Forms\Components\Section::make('Limites')
                ->description('Laisser vide = ILLIMITÉ. Le zéro n\'est jamais une limite illimitée (minimum 1).')
                ->schema([
                    Forms\Components\TextInput::make('max_users')->label('Utilisateurs max.')->numeric()->integer()->minValue(1)->placeholder('Illimité'),
                    Forms\Components\TextInput::make('max_agents')->label('Agents max.')->numeric()->integer()->minValue(1)->placeholder('Illimité'),
                    Forms\Components\TextInput::make('storage_limit_mb')->label('Stockage max. (Mo)')->numeric()->integer()->minValue(1)->placeholder('Illimité')
                        ->helperText('Enregistré pour l\'affichage ; pas encore appliqué côté serveur.'),
                ])->columns(3),
            Forms\Components\Section::make('Fonctionnalités et affichage')->schema([
                Forms\Components\TagsInput::make('features')->label('Fonctionnalités')
                    ->suggestions(array_keys(FeatureCatalog::labels()))
                    ->helperText('Identifiants techniques. « toutes_les_fonctions_{offre} » inclut toutes celles de l\'offre indiquée.')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('display_order')->label('Ordre d\'affichage')->numeric()->integer()->default(0),
                Forms\Components\Select::make('visual_variant')->label('Variante visuelle')->options(config('subscriptions.visual_variants'))->default('default')->required(),
                Forms\Components\Toggle::make('is_featured')->label('Mise en avant'),
                Forms\Components\Toggle::make('is_active')->label('Active (visible sur /offres et attribuable)')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Offre')->searchable(),
                Tables\Columns\TextColumn::make('monthly_price')->label('Mensuel')->numeric(thousandsSeparator: ' ')->suffix(' FCFA'),
                Tables\Columns\TextColumn::make('yearly_price')->label('Annuel')->numeric(thousandsSeparator: ' ')->suffix(' FCFA'),
                Tables\Columns\TextColumn::make('max_users')->label('Utilisateurs')->placeholder('Illimité'),
                Tables\Columns\TextColumn::make('max_agents')->label('Agents')->placeholder('Illimité'),
                Tables\Columns\IconColumn::make('is_featured')->label('Mise en avant')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
                Tables\Columns\TextColumn::make('usage')->label('Abonnements')
                    ->state(fn (SubscriptionPlan $record) => $record->subscriptionsCount())
                    ->badge()->color(fn (int $state) => $state > 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} (historique inclus)" : 'Aucun'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle')
                    ->label(fn (SubscriptionPlan $record) => $record->is_active ? 'Désactiver' : 'Activer')
                    ->icon(fn (SubscriptionPlan $record) => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->requiresConfirmation()
                    ->action(fn (SubscriptionPlan $record) => $record->update(['is_active' => ! $record->is_active])),
                // Suppression physique réservée aux offres jamais utilisées (policy + garde du modèle).
                Tables\Actions\DeleteAction::make()->visible(fn (SubscriptionPlan $record) => ! $record->isUsed()),
            ])
            ->defaultSort('display_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
