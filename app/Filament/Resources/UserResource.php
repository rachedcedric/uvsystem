<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Utilisateurs';

    protected static ?string $navigationGroup = 'Gestion du tenant';

    protected static ?int $navigationSort = 100;

    protected static ?string $modelLabel = 'utilisateur';

    protected static ?string $pluralModelLabel = 'utilisateurs';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->label('Mot de passe')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->minLength(8)
                            ->confirmed(),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirmer le mot de passe')
                            ->password()
                            ->requiredWith('password'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Rôles & Permissions')
                    ->schema([
                        Forms\Components\Select::make('roles')
                            ->label('Rôles')
                            ->multiple()
                            ->relationship('roles', 'name', fn ($query) => $query->whereIn('name', User::assignableRoleNames()))
                            ->preload()
                            ->searchable()
                            ->visible(fn (): bool => auth()->user()?->isMaster() && auth()->user()?->can('assign_roles'))
                            ->helperText('master = administration complète du tenant, admin = tout sauf attribution des rôles, comptable = finances, commercial = agents/transactions'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Rôles')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'master' => 'danger',
                        'admin' => 'warning',
                        'comptable' => 'info',
                        'commercial' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Rôle')
                    ->relationship('roles', 'name', fn ($query) => $query->whereIn('name', User::assignableRoleNames()))
                    ->multiple()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record): bool => static::canDelete($record)),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_users') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_users') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('edit_users') ?? false;
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return ($user?->can('delete_users') ?? false)
            && $user->getKey() !== $record->getKey()
            && ! $record->hasRole('master');
    }
}
