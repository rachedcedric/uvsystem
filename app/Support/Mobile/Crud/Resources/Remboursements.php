<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Agent;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Fcfa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Remboursements extends MobileResource
{
    public static function slug(): string
    {
        return 'remboursements';
    }

    public static function model(): string
    {
        return Remboursement::class;
    }

    public static function label(): string
    {
        return 'Remboursement';
    }

    public static function plural(): string
    {
        return 'Remboursements';
    }

    public static function hidden(): bool
    {
        return true;
    }

    public static function showUrl(Model $record): string
    {
        return route('m.remboursements.show', $record);
    }

    public static function indexUrl(): string
    {
        return route('m.remboursements');
    }

    public static function fields(): array
    {
        return [
            Field::select('agent_id', 'Agent', fn () => Agent::orderBy('name')->pluck('name', 'id')->all())->required(),
            Field::money('amount', 'Montant payé (FCFA)')->required()->min(1),
            Field::select('payment_method', 'Mode de paiement', fn () => PaymentMethod::getActiveOptions())->required(),
            Field::datetime('payment_date', 'Date et heure')->required(),
            Field::textarea('notes', 'Notes'),
        ];
    }

    public static function row(Model $record): array
    {
        return ['title' => $record->reference];
    }

    public static function details(Model $record): array
    {
        return [];
    }

    public static function save(array $data, ?Model $record): Model
    {
        if (\Carbon\Carbon::parse($data['payment_date'])->isFuture()) {
            throw ValidationException::withMessages(['payment_date' => 'La date ne peut pas être dans le futur.']);
        }

        return DB::transaction(function () use ($data, $record) {
            $agent = Agent::whereKey($data['agent_id'])->lockForUpdate()->firstOrFail();

            // Le paiement modifié est retiré de la dette avant de contrôler le nouveau montant.
            $debt = $agent->debtAsOf() + ((int) $agent->id === (int) $record->agent_id ? (float) $record->amount : 0.0);
            if ((float) $data['amount'] > $debt) {
                throw ValidationException::withMessages(['amount' => 'Le montant ne peut pas dépasser le reste à payer ('.Fcfa::format($debt).').']);
            }

            return parent::save($data, $record);
        });
    }

    public static function deleteWarning(Model $record): string
    {
        return 'Le paiement est supprimé et la dette de l\'agent est recalculée.';
    }
}
