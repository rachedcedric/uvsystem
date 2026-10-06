<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\SubscriptionChangeRequestResource\Pages;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Tenancy\TenantContext;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Demandes de changement d'offre des tenants : confirmer le paiement (applique le changement) ou refuser. */
class SubscriptionChangeRequestResource extends Resource
{
    protected static ?string $model = SubscriptionChangeRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Abonnements';

    protected static ?string $modelLabel = 'demande de changement';

    protected static ?string $pluralModelLabel = 'demandes de changement';

    protected static ?string $navigationLabel = 'Demandes de changement';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        return SubscriptionChangeRequest::query()->withoutGlobalScopes()->with('plan');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tenant_id')->label('Tenant')->formatStateUsing(fn ($state) => Tenant::find($state)?->name),
                Tables\Columns\TextColumn::make('plan.name')->label('Offre demandée'),
                Tables\Columns\TextColumn::make('requested_cycle')->label('Cycle')->formatStateUsing(fn ($state) => $state->label()),
                Tables\Columns\TextColumn::make('due')->label('À régler')->state(fn (SubscriptionChangeRequest $r) => (int) ($r->quote['amount_due'] ?? 0))
                    ->numeric(thousandsSeparator: ' ')->suffix(' FCFA'),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('Demandée le')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\Action::make('confirm_payment')->label('Confirmer le paiement')->icon('heroicon-o-banknotes')
                    ->visible(fn (SubscriptionChangeRequest $r) => $r->status === SubscriptionChangeRequest::PENDING && (auth()->user()?->isSuperAdmin() ?? false))
                    ->modalDescription('Attestez un encaissement réel : le changement d\'offre est appliqué. Une référence est obligatoire.')
                    ->form([Forms\Components\TextInput::make('reference')->label('Référence de l\'encaissement')->required()->maxLength(255)])
                    ->action(fn (SubscriptionChangeRequest $r, array $data) => TenantSubscriptionResource::guarded(function () use ($r, $data) {
                        $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->where('status', SubscriptionPayment::PENDING)->get()
                            ->first(fn (SubscriptionPayment $p) => ($p->metadata['change_request_id'] ?? null) === $r->id));

                        abort_if($payment === null, 404, 'Aucun paiement en attente pour cette demande.');

                        app(SubscriptionBilling::class)->confirm($payment, ['reference' => $data['reference'], 'confirmed_by' => auth()->user()]);
                    }, 'Paiement confirmé, offre changée')),
                Tables\Actions\Action::make('reject')->label('Refuser')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (SubscriptionChangeRequest $r) => $r->status === SubscriptionChangeRequest::PENDING && (auth()->user()?->isSuperAdmin() ?? false))
                    ->form([Forms\Components\TextInput::make('note')->label('Motif')->maxLength(255)])
                    ->action(fn (SubscriptionChangeRequest $r, array $data) => TenantSubscriptionResource::guarded(
                        fn () => app(SubscriptionBilling::class)->rejectChange($r, auth()->user(), $data['note'] ?? null), 'Demande refusée')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSubscriptionChangeRequests::route('/')];
    }
}
