<?php

namespace App\Filament\Resources;

use App\Exports\AgentSituationExport;
use App\Filament\Resources\AgentResource\Pages;
use App\Filament\Resources\AgentResource\RelationManagers;
use App\Models\Agent;
use App\Models\User;
use App\Notifications\PaymentReminderNotification;
use App\Services\SmsService;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Maatwebsite\Excel\Facades\Excel;

class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Gestion Agents';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations Agent')
                    ->description('Informations de base de l\'agent')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom complet')
                            ->required()
                            ->placeholder('Ex: Mamadou Diallo')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->required()
                            ->unique(ignoreRecord: true, modifyRuleUsing: TenantContext::scopeUnique())
                            ->placeholder('+225 01 23 45 67 89')
                            ->regex('/^[0-9+\s]{8,20}$/')
                            ->helperText('Format: 8 à 15 chiffres')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->placeholder('agent@example.com')
                            ->unique(ignoreRecord: true, modifyRuleUsing: TenantContext::scopeUnique())
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address')
                            ->label('Adresse')
                            ->placeholder('Quartier, Ville')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Configuration Commerciale')
                    ->description('Paramètres de l\'activité de l\'agent')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Type d\'agent')
                            ->options([
                                'standard' => '📦 Standard',
                                'premium' => '⭐ Premium',
                                'vip' => '👑 VIP',
                            ])
                            ->required()
                            ->default('standard')
                            ->native(false),
                        Forms\Components\TextInput::make('zone')
                            ->label('Zone géographique')
                            ->placeholder('Ex: Abidjan Nord')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('debt_limit')
                            ->label('Plafond de dette')
                            ->money(min: 0, max: 999999999)
                            // Aucune valeur imposée : le master choisit. Laissé vide, le champ est enregistré VIDE (null), pas 0.
                            ->dehydrateStateUsing(fn ($state) => ($state === null || $state === '') ? null : Money::toFloat($state))
                            ->helperText('Montant maximum de crédit autorisé. Laissez vide pour ne fixer aucune limite.'),
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Taux de commission')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.5)
                            ->helperText('Commission sur les ventes'),
                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->options([
                                'active' => '✅ Actif',
                                'inactive' => '⏸️ Inactif',
                                'suspended' => '🚫 Suspendu',
                                'blocked' => '🔒 Bloqué',
                            ])
                            ->required()
                            ->default('active')
                            ->native(false),
                    ])
                    ->columns(2),

                // Le master ou l'admin peut lier une fiche agent à un compte de son entreprise (le sien compris) : le titulaire
                // retrouve alors ses propres ventes et remboursements dans « Mon espace agent ». Réservé au master et à l'admin ;
                // un champ masqué n'est jamais enregistré, un compte d'une autre entreprise n'est pas proposé ni accepté.
                Forms\Components\Section::make('Compte de connexion')
                    ->description('Facultatif : donne à un compte de votre entreprise l\'accès à son espace agent. Un master ou un admin peut ainsi être aussi agent.')
                    ->icon('heroicon-o-user-circle')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('Compte lié')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder('Aucun compte')
                            ->rules([
                                // Uniquement un compte de CETTE entreprise (jamais un compte d'une autre).
                                fn (): In => Rule::in(User::query()->pluck('id')->all()),
                                fn (?Agent $record) => Rule::unique('agents', 'user_id')->ignore($record?->getKey())->whereNull('deleted_at')->where('tenant_id', TenantContext::id()),
                            ])
                            ->validationMessages(['unique' => 'Ce compte est déjà lié à un autre agent.', 'in' => 'Ce compte n\'appartient pas à votre entreprise.']),
                    ])
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->collapsible(),

                Forms\Components\Section::make('Notes & Observations')
                    ->description('Informations complémentaires')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes internes')
                            ->placeholder('Ajouter des observations sur cet agent...')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
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
                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'standard' => '📦 Standard',
                        'premium' => '⭐ Premium',
                        'vip' => '👑 VIP',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'standard' => 'primary',
                        'premium' => 'success',
                        'vip' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('zone')
                    ->label('Zone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Dette actuelle')
                    ->money('XOF')
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('debt_limit')
                    ->label('Plafond')
                    ->money('XOF')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('debt_ratio')
                    ->label('Ratio %')
                    ->getStateUsing(fn (Agent $record): string => $record->debt_limit > 0
                            ? number_format(($record->current_debt / $record->debt_limit) * 100, 1).'%'
                            : '0%'
                    )
                    ->badge()
                    ->color(fn (Agent $record): string => $record->debt_limit > 0 && ($record->current_debt / $record->debt_limit) > 0.8
                            ? 'danger'
                            : ($record->debt_limit > 0 && ($record->current_debt / $record->debt_limit) > 0.5 ? 'warning' : 'success')
                    ),
                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'warning',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'suspended' => 'Suspendu',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'standard' => 'Standard',
                        'premium' => 'Premium',
                    ]),
                Tables\Filters\Filter::make('has_debt')
                    ->label('Avec dette')
                    ->query(fn (Builder $query): Builder => $query->where('current_debt', '>', 0)),
                Tables\Filters\Filter::make('high_debt_ratio')
                    ->label('Alerte dette (>80%)')
                    ->query(fn (Builder $query): Builder => $query->whereRaw('current_debt > debt_limit * 0.8')
                    ),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('exportFiche')
                        ->label('Fiche Agent PDF')
                        ->icon('heroicon-o-document')
                        ->color('danger')
                        ->action(function (Agent $record) {
                            $pdf = Pdf::loadView('exports.agent-fiche-pdf', [
                                'agent' => $record->load(['transactions', 'remboursements']),
                            ])->setPaper('a4', 'portrait');

                            return response()->streamDownload(
                                fn () => print ($pdf->output()),
                                'fiche_agent_'.$record->id.'_'.now()->format('Y-m-d').'.pdf'
                            );
                        }),
                    Tables\Actions\Action::make('sendReminder')
                        ->label('Envoyer rappel')
                        ->icon('heroicon-o-bell-alert')
                        ->color('warning')
                        ->visible(fn (Agent $record): bool => $record->current_debt > 0)
                        ->form([
                            Forms\Components\Select::make('channel')
                                ->label('Canal d\'envoi')
                                ->options([
                                    'sms' => '📱 SMS',
                                    'email' => '📧 Email',
                                    'both' => '📱📧 SMS + Email',
                                ])
                                ->default('sms')
                                ->required(),
                            Forms\Components\Textarea::make('custom_message')
                                ->label('Message personnalisé (optionnel)')
                                ->placeholder('Laissez vide pour le message par défaut')
                                ->rows(3),
                        ])
                        ->modalHeading('Envoyer un rappel de paiement')
                        ->modalDescription(fn (Agent $record): string => "Envoyer un rappel à {$record->name} pour sa dette de ".
                            number_format($record->current_debt, 0, ',', ' ').' FCFA'
                        )
                        ->action(function (Agent $record, array $data) {
                            $channel = $data['channel'];
                            $customMessage = $data['custom_message'] ?? null;
                            $smsService = app(SmsService::class);
                            $sentChannels = [];

                            try {
                                // Envoi SMS
                                if (in_array($channel, ['sms', 'both'])) {
                                    $smsMessage = $customMessage ?: "Bonjour {$record->name}, vous avez une dette de ".
                                        number_format($record->current_debt, 0, ',', ' ').' FCFA en attente. Merci de régulariser votre situation. - '.config('branding.name');

                                    if ($smsService->sendToAgent($record, $smsMessage)) {
                                        $sentChannels[] = 'SMS';
                                    }
                                }

                                // Envoi Email
                                if (in_array($channel, ['email', 'both']) && $record->email) {
                                    $record->notify(new PaymentReminderNotification($record, $customMessage));
                                    $sentChannels[] = 'Email';
                                }

                                if (count($sentChannels) > 0) {
                                    Notification::make()
                                        ->title('Rappel envoyé avec succès ✅')
                                        ->body('Envoyé via : '.implode(' + ', $sentChannels)." à {$record->name}")
                                        ->success()
                                        ->send();

                                    // Log l'action
                                    activity()
                                        ->performedOn($record)
                                        ->causedBy(Auth::user())
                                        ->withProperties([
                                            'channels' => $sentChannels,
                                            'debt' => $record->current_debt,
                                            'custom_message' => $customMessage,
                                        ])
                                        ->event('payment_reminder_sent')
                                        ->log("Rappel de paiement envoyé à {$record->name} via ".implode(', ', $sentChannels));
                                } else {
                                    Notification::make()
                                        ->title('Aucun rappel envoyé')
                                        ->body("Impossible d'envoyer le rappel. Vérifiez la configuration SMS ou l'email de l'agent.")
                                        ->warning()
                                        ->send();
                                }

                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Erreur d\'envoi')
                                    ->body('Erreur : '.$e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\BulkAction::make('exportSelection')
                        ->label('Exporter sélection')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (Collection $records) {
                            $ids = $records->pluck('id')->toArray();
                            $filename = 'agents_selection_'.now()->format('Y-m-d_His').'.xlsx';
                            $path = TenantContext::storagePath('exports/'.$filename);

                            Excel::store(new AgentSituationExport(null, false, $ids), $path, 'local');

                            return redirect()->route('exports.download', ['filename' => $filename]);
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Informations Agent')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Nom'),
                        Infolists\Components\TextEntry::make('phone')
                            ->label('Téléphone'),
                        Infolists\Components\TextEntry::make('type')
                            ->label('Type')
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'inactive' => 'warning',
                                'suspended' => 'danger',
                            }),
                    ])
                    ->columns(2),
                Infolists\Components\Section::make('Situation Financière')
                    ->schema([
                        Infolists\Components\TextEntry::make('solde.total_uv_distribues')
                            ->label('Total UV distribués')
                            ->money('XOF'),
                        Infolists\Components\TextEntry::make('solde.total_rembourse')
                            ->label('Total remboursé')
                            ->money('XOF'),
                        Infolists\Components\TextEntry::make('current_debt')
                            ->label('Dette actuelle')
                            ->money('XOF')
                            ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                        Infolists\Components\TextEntry::make('debt_limit')
                            ->label('Plafond autorisé')
                            ->money('XOF'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TransactionsRelationManager::class,
            RelationManagers\RemboursementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgents::route('/'),
            'create' => Pages\CreateAgent::route('/create'),
            'view' => Pages\ViewAgent::route('/{record}'),
            'edit' => Pages\EditAgent::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['solde']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone'];
    }
}
