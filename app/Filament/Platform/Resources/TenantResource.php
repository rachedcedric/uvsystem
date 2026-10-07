<?php

namespace App\Filament\Platform\Resources;

use App\Enums\SubscriptionCycle;
use App\Filament\Platform\Resources\TenantResource\Pages;
use App\Filament\Platform\Resources\TenantResource\RelationManagers\UsersRelationManager;
use App\Models\Agent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Entreprises';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'tenant';

    protected static ?string $pluralModelLabel = 'tenants';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        // Jamais de suppression : elle cascaderait sur toutes les données financières. On suspend.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Société')->schema([
                Forms\Components\TextInput::make('name')->label('Nom')->required()->maxLength(255),
                Forms\Components\TextInput::make('slug')->label('Identifiant')
                    ->helperText('Généré depuis le nom si laissé vide.')
                    ->alphaDash()->maxLength(100)->unique(ignoreRecord: true)
                    ->disabled(fn (?Tenant $record) => $record !== null),
                Forms\Components\Select::make('status')->label('Statut')->options([
                    Tenant::STATUS_ACTIVE => 'Actif',
                    Tenant::STATUS_SUSPENDED => 'Suspendu',
                ])->default(Tenant::STATUS_ACTIVE)->required(),
            ])->columns(2),
            Forms\Components\Section::make('Offre')
                ->description('L\'abonnement se gère ensuite dans « Abonnements » (changement d\'offre, essai, suspension…).')
                ->schema([
                    Forms\Components\Select::make('subscription_plan_id')->label('Offre')
                        ->options(fn () => SubscriptionPlan::active()->ordered()->pluck('name', 'id'))
                        ->default(fn () => SubscriptionPlan::active()->ordered()->value('id'))->required(),
                    Forms\Components\Select::make('subscription_cycle')->label('Cycle')
                        ->options(SubscriptionCycle::options())->default('monthly')->required(),
                    Forms\Components\Select::make('subscription_mode')->label('Démarrage')
                        ->options(['trial' => 'Période d\'essai', 'active' => 'Abonnement actif (sans paiement)'])->default('trial')->required(),
                ])->columns(3)
                ->visible(fn (?Tenant $record) => $record === null),
            Forms\Components\Section::make('Contact')->schema([
                Forms\Components\TextInput::make('contact_name')->label('Nom du contact')->maxLength(255),
                Forms\Components\TextInput::make('contact_email')->label('E-mail')->email()->maxLength(255),
                Forms\Components\TextInput::make('contact_phone')->label('Téléphone')->tel()->maxLength(30),
            ])->columns(3),
            Forms\Components\Section::make('Premier administrateur (rôle master)')
                ->description('Créé avec le tenant ; il pourra ensuite inviter ses propres utilisateurs.')
                ->schema([
                    Forms\Components\TextInput::make('admin_name')->label('Nom')->required()->maxLength(255),
                    Forms\Components\TextInput::make('admin_email')->label('E-mail de connexion')->email()->required()
                        ->unique(table: 'users', column: 'email')->maxLength(255),
                    Forms\Components\TextInput::make('admin_password')->label('Mot de passe')->password()->revealable()
                        ->required()->minLength(12)->maxLength(255),
                ])->columns(3)
                ->visible(fn (?Tenant $record) => $record === null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->label('Identifiant')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()
                    ->color(fn (string $state) => $state === Tenant::STATUS_ACTIVE ? 'success' : 'danger')
                    ->formatStateUsing(fn (string $state) => $state === Tenant::STATUS_ACTIVE ? 'Actif' : 'Suspendu'),
                Tables\Columns\TextColumn::make('subscription')->label('Offre')
                    ->state(fn (Tenant $record) => app(SubscriptionManager::class)->current($record)?->plan?->name)->placeholder('—'),
                Tables\Columns\TextColumn::make('users_count')->counts('users')->label('Utilisateurs'),
                Tables\Columns\TextColumn::make('agents')->label('Agents')
                    ->state(fn (Tenant $record) => TenantContext::withoutTenancy(
                        fn () => Agent::where('tenant_id', $record->id)->count()
                    )),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y')->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('suspend')->label('Suspendre')->icon('heroicon-o-pause-circle')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Les utilisateurs de ce tenant ne pourront plus se connecter (web, mobile, API). Aucune donnée n\'est supprimée.')
                    ->visible(fn (Tenant $record) => $record->status === Tenant::STATUS_ACTIVE)
                    ->action(function (Tenant $record) {
                        $record->suspend();
                        Notification::make()->title('Tenant suspendu')->success()->send();
                    }),
                Tables\Actions\Action::make('activate')->label('Réactiver')->icon('heroicon-o-play-circle')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Tenant $record) => $record->status === Tenant::STATUS_SUSPENDED)
                    ->action(function (Tenant $record) {
                        $record->activate();
                        Notification::make()->title('Tenant réactivé')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [UsersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
