<?php

namespace App\Filament\Platform\Resources\TenantResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/** Comptes d'un tenant, gérés par le super admin (jamais de rôle super_admin ici). */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Utilisateurs';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nom')->required()->maxLength(255),
            Forms\Components\TextInput::make('email')->label('E-mail')->email()->required()
                ->unique(ignoreRecord: true)->maxLength(255),
            Forms\Components\TextInput::make('password')->label('Mot de passe')->password()->revealable()
                ->minLength(12)->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->dehydrateStateUsing(fn (string $state) => Hash::make($state)),
            Forms\Components\Select::make('role')->label('Rôle')
                ->options(collect(User::assignableRoleNames())->mapWithKeys(fn ($r) => [$r => ucfirst($r)]))
                ->required()
                ->afterStateHydrated(fn ($component, ?User $record) => $record && $component->state($record->getRoleNames()->first())),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nom')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Rôle')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->after(fn (User $record, array $data) => $record->syncRoles([$data['role']])),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->after(fn (User $record, array $data) => $record->syncRoles([$data['role']])),
                Tables\Actions\Action::make('reset2fa')->label('Réinitialiser la 2FA')->icon('heroicon-o-key')->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('À utiliser si l\'utilisateur a perdu son téléphone et ses codes de récupération.')
                    ->visible(fn (User $record) => $record->hasTwoFactorEnabled())
                    ->action(function (User $record) {
                        app(\App\Services\TwoFactorAuthService::class)->disable($record);
                        \Filament\Notifications\Notification::make()->title('2FA réinitialisée')->success()->send();
                    }),
            ]);
    }
}
