<?php

namespace App\Filament\Platform\Resources;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Exceptions\SubscriptionException;
use App\Filament\Platform\Resources\TenantSubscriptionResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Subscriptions\Payments\ManualSubscriptionPaymentGateway;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionManager;
use Closure;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Abonnements de TOUS les tenants (historique compris), réservé au super admin de plateforme.
 * Aucune logique métier ici : chaque action appelle SubscriptionManager / SubscriptionBilling, et revérifie
 * côté serveur isSuperAdmin() (la visibilité d'un bouton n'est jamais la protection).
 */
class TenantSubscriptionResource extends Resource
{
    protected static ?string $model = TenantSubscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Abonnements';

    protected static ?string $modelLabel = 'abonnement';

    protected static ?string $pluralModelLabel = 'abonnements';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false; // création via l'action « Attribuer une offre », qui passe par le manager
    }

    public static function canDelete($record): bool
    {
        return false; // l'historique n'est jamais supprimé
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false; // les lignes ne se modifient que par les actions du manager
    }

    /** Vue plateforme : tous les tenants (le scope de tenant ne s'applique pas au super admin). */
    public static function getEloquentQuery(): Builder
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        return TenantSubscription::query()->withoutGlobalScopes()->with(['tenant', 'plan']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tenant.name')->label('Tenant')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('plan.name')->label('Offre')->sortable(),
                Tables\Columns\TextColumn::make('cycle')->label('Cycle')->formatStateUsing(fn (SubscriptionCycle $state) => $state->label()),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()
                    ->state(fn (TenantSubscription $r) => $r->effectiveStatus())
                    ->formatStateUsing(fn (SubscriptionStatus $state) => $state->label())
                    ->color(fn (SubscriptionStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('applied_price')->label('Prix')->numeric(thousandsSeparator: ' ')->suffix(' FCFA'),
                Tables\Columns\TextColumn::make('ends')->label('Échéance')
                    ->state(fn (TenantSubscription $r) => $r->endsAt())->dateTime('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('days')->label('Jours restants')
                    ->state(fn (TenantSubscription $r) => $r->isCurrent() ? $r->daysRemaining() : null)
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : max(0, $state))->placeholder('—'),
                Tables\Columns\IconColumn::make('current')->label('Courant')->boolean()->state(fn (TenantSubscription $r) => $r->isCurrent()),
            ])
            ->filters([
                Tables\Filters\Filter::make('current_only')->label('Abonnements courants seulement')->default()
                    ->query(fn (Builder $q) => $q->whereNotNull('current_marker')),
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options(SubscriptionStatus::options()),
                Tables\Filters\SelectFilter::make('subscription_plan_id')->label('Offre')->options(fn () => SubscriptionPlan::query()->ordered()->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('cycle')->label('Cycle')->options(SubscriptionCycle::options()),
                Tables\Filters\SelectFilter::make('tenant_id')->label('Tenant')->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                Tables\Filters\Filter::make('expiration')->label('Date d\'expiration')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Expire à partir du'),
                        Forms\Components\DatePicker::make('until')->label('Expire jusqu\'au'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $end = fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNotNull('current_period_ends_at')->where('current_period_ends_at', '>=', $data['from'])
                            ->when($data['until'], fn ($x) => $x->where('current_period_ends_at', '<=', $data['until'].' 23:59:59')))
                            ->orWhere(fn (Builder $w) => $w->whereNotNull('trial_ends_at')->where('trial_ends_at', '>=', $data['from'])
                                ->when($data['until'], fn ($x) => $x->where('trial_ends_at', '<=', $data['until'].' 23:59:59')));

                        return $query
                            ->when($data['from'], fn (Builder $q) => $q->where($end))
                            ->when(! $data['from'] && $data['until'], fn (Builder $q) => $q->where(fn (Builder $w) => $w
                                ->where('current_period_ends_at', '<=', $data['until'].' 23:59:59')
                                ->orWhere('trial_ends_at', '<=', $data['until'].' 23:59:59')));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                ...self::lifecycleActions(),
            ])
            ->headerActions([self::assignAction()])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Abonnement')->schema([
                TextEntry::make('tenant.name')->label('Tenant'),
                TextEntry::make('plan.name')->label('Offre'),
                TextEntry::make('cycle')->label('Cycle')->formatStateUsing(fn (SubscriptionCycle $state) => $state->label()),
                TextEntry::make('status')->label('Statut')->badge()
                    ->state(fn (TenantSubscription $r) => $r->effectiveStatus())
                    ->formatStateUsing(fn (SubscriptionStatus $state) => $state->label())
                    ->color(fn (SubscriptionStatus $state) => $state->color()),
                TextEntry::make('applied_price')->label('Prix appliqué')->numeric(thousandsSeparator: ' ')->suffix(' FCFA'),
                TextEntry::make('days')->label('Jours restants')->state(fn (TenantSubscription $r) => $r->isCurrent() && $r->daysRemaining() !== null ? max(0, $r->daysRemaining()) : '—'),
                TextEntry::make('starts_at')->label('Début')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('trial_ends_at')->label('Fin d\'essai')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('current_period_ends_at')->label('Fin de période')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('cancelled_at')->label('Annulé le')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('suspended_at')->label('Suspendu le')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])->columns(3),
            Section::make('Historique du tenant')->schema([
                RepeatableEntry::make('history')->label('')->state(fn (TenantSubscription $r) => app(SubscriptionManager::class)
                    ->history(Tenant::findOrFail($r->tenant_id))
                    ->map(fn (TenantSubscription $h) => [
                        'plan' => $h->plan?->name,
                        'cycle' => $h->cycle->label(),
                        'status' => $h->status->label(),
                        'price' => number_format($h->applied_price, 0, ',', ' ').' FCFA',
                        'period' => ($h->starts_at?->format('d/m/Y') ?? '—').' → '.($h->ended_at?->format('d/m/Y') ?? ($h->endsAt()?->format('d/m/Y') ?? 'en cours')),
                    ])->all())
                    ->schema([
                        TextEntry::make('plan')->label('Offre'),
                        TextEntry::make('cycle')->label('Cycle'),
                        TextEntry::make('status')->label('Statut'),
                        TextEntry::make('price')->label('Prix'),
                        TextEntry::make('period')->label('Période'),
                    ])->columns(5),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantSubscriptions::route('/'),
            'view' => Pages\ViewTenantSubscription::route('/{record}'),
        ];
    }

    // ------------------------------------------------------------------ actions

    /** Exécute une opération du manager ; refuse (403) tout appelant qui n'est pas super admin, même sans bouton. */
    public static function guarded(Closure $operation, string $success): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        try {
            $operation();
            Notification::make()->title($success)->success()->send();
        } catch (InvalidSubscriptionTransition|SubscriptionException $e) {
            Notification::make()->title($e instanceof SubscriptionException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage())->danger()->send();
        }
    }

    public static function assignAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('assign')->label('Attribuer une offre')->icon('heroicon-o-plus')
            ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
            ->form([
                Forms\Components\Select::make('tenant_id')->label('Tenant')->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                Forms\Components\Select::make('plan_id')->label('Offre')->options(fn () => SubscriptionPlan::active()->ordered()->pluck('name', 'id'))->required(),
                Forms\Components\Select::make('cycle')->label('Cycle')->options(SubscriptionCycle::options())->default('monthly')->required(),
                Forms\Components\Select::make('mode')->label('Démarrage')->options(['trial' => 'Période d\'essai', 'active' => 'Abonnement actif (sans paiement)'])->default('trial')->required(),
                Forms\Components\Toggle::make('allow_repeat')->label('Autoriser un nouvel essai pour ce tenant')->visible(fn (Forms\Get $get) => $get('mode') === 'trial'),
            ])
            ->action(fn (array $data) => self::guarded(function () use ($data) {
                $tenant = Tenant::findOrFail($data['tenant_id']);
                $plan = SubscriptionPlan::findOrFail($data['plan_id']);
                $cycle = SubscriptionCycle::from($data['cycle']);
                $manager = app(SubscriptionManager::class);

                $data['mode'] === 'trial'
                    ? $manager->startTrial($tenant, $plan, $cycle, allowRepeat: (bool) ($data['allow_repeat'] ?? false))
                    : $manager->activate($tenant, $plan, $cycle);
            }, 'Offre attribuée'));
    }

    /** @return list<Tables\Actions\Action> actions réservées à la ligne COURANTE d'un tenant */
    public static function lifecycleActions(): array
    {
        $current = fn (TenantSubscription $r) => $r->isCurrent() && (auth()->user()?->isSuperAdmin() ?? false);
        $tenant = fn (TenantSubscription $r) => Tenant::findOrFail($r->tenant_id);
        $manager = fn () => app(SubscriptionManager::class);

        return [
            Tables\Actions\Action::make('payment')->label('Enregistrer un paiement')->icon('heroicon-o-banknotes')
                ->visible($current)
                ->modalDescription('Attestez un encaissement réel : l\'abonnement est prolongé d\'une période. Une référence est obligatoire.')
                ->form([Forms\Components\TextInput::make('reference')->label('Référence de l\'encaissement')->required()->maxLength(255)])
                ->action(fn (TenantSubscription $r, array $data) => self::guarded(function () use ($r, $data, $tenant) {
                    $billing = app(SubscriptionBilling::class);
                    $payment = $billing->request($tenant($r), ManualSubscriptionPaymentGateway::NAME);
                    $billing->confirm($payment, ['reference' => $data['reference'], 'confirmed_by' => auth()->user()]);
                }, 'Paiement enregistré, abonnement prolongé')),
            Tables\Actions\Action::make('change_plan')->label('Changer d\'offre')->icon('heroicon-o-arrows-right-left')
                ->visible($current)
                ->form([
                    Forms\Components\Select::make('plan_id')->label('Nouvelle offre')->options(fn () => SubscriptionPlan::active()->ordered()->pluck('name', 'id'))->required(),
                    Forms\Components\Select::make('cycle')->label('Cycle')->options(SubscriptionCycle::options())->required(),
                ])
                ->fillForm(fn (TenantSubscription $r) => ['plan_id' => $r->subscription_plan_id, 'cycle' => $r->cycle->value])
                ->action(fn (TenantSubscription $r, array $data) => self::guarded(
                    fn () => $manager()->changePlan($tenant($r), SubscriptionPlan::findOrFail($data['plan_id']), SubscriptionCycle::from($data['cycle'])),
                    'Offre changée (historique conservé)')),
            Tables\Actions\Action::make('extend_trial')->label('Prolonger l\'essai')->icon('heroicon-o-clock')
                ->visible(fn (TenantSubscription $r) => $current($r) && ($r->status === SubscriptionStatus::Trialing || ($r->status === SubscriptionStatus::Expired && $r->trial_ends_at !== null)))
                ->form([Forms\Components\TextInput::make('days')->label('Jours à ajouter')->numeric()->integer()->minValue(1)->maxValue(365)->required()])
                ->action(fn (TenantSubscription $r, array $data) => self::guarded(fn () => $manager()->extendTrial($tenant($r), (int) $data['days']), 'Essai prolongé')),
            Tables\Actions\Action::make('auto_renew')
                ->label(fn (TenantSubscription $r) => $r->auto_renew ? 'Désactiver la facture auto' : 'Activer la facture auto')->icon('heroicon-o-arrow-path')
                ->visible($current)
                ->modalDescription('À l\'échéance, une facture de renouvellement est émise (paiement en attente + notification). Le tenant règle cette facture via Jèko ; aucun prélèvement automatique n\'est effectué.')
                ->requiresConfirmation()
                ->action(fn (TenantSubscription $r) => self::guarded(fn () => $manager()->setAutoRenew($tenant($r), ! $r->auto_renew), 'Option mise à jour')),
            Tables\Actions\Action::make('suspend')->label('Suspendre')->icon('heroicon-o-pause-circle')->color('warning')
                ->visible(fn (TenantSubscription $r) => $current($r) && ! in_array($r->status, [SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled], true))
                ->form([Forms\Components\TextInput::make('reason')->label('Motif')->maxLength(255)])
                ->modalDescription('Le tenant garde la consultation et les exports, mais ne peut plus rien modifier. Aucune donnée n\'est supprimée.')
                ->action(fn (TenantSubscription $r, array $data) => self::guarded(fn () => $manager()->suspend($tenant($r), $data['reason'] ?? null), 'Abonnement suspendu')),
            Tables\Actions\Action::make('reactivate')->label('Réactiver')->icon('heroicon-o-play-circle')->color('success')
                ->visible(fn (TenantSubscription $r) => $current($r) && $r->status === SubscriptionStatus::Suspended)
                ->requiresConfirmation()
                ->action(fn (TenantSubscription $r) => self::guarded(fn () => $manager()->reactivate($tenant($r)), 'Abonnement réactivé')),
            Tables\Actions\Action::make('cancel')->label('Annuler')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (TenantSubscription $r) => $current($r) && ! in_array($r->status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true))
                ->form([Forms\Components\Toggle::make('immediately')->label('Immédiatement (sinon : à la fin de la période payée)')])
                ->action(fn (TenantSubscription $r, array $data) => self::guarded(fn () => $manager()->cancel($tenant($r), (bool) ($data['immediately'] ?? false)), 'Abonnement annulé')),
        ];
    }
}
