<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Agent;
use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Fcfa;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Modification / suppression d'une vente depuis l'écran de détail (création : écran dédié). */
class Ventes extends MobileResource
{
    public static function slug(): string
    {
        return 'ventes';
    }

    public static function model(): string
    {
        return UvTransaction::class;
    }

    public static function label(): string
    {
        return 'Vente';
    }

    public static function feminine(): bool
    {
        return true;
    }

    public static function plural(): string
    {
        return 'Ventes UV';
    }

    public static function hidden(): bool
    {
        return true;
    }

    public static function showUrl(Model $record): string
    {
        return route('m.ventes.show', $record);
    }

    public static function indexUrl(): string
    {
        return route('m.ventes');
    }

    public static function fields(): array
    {
        return [
            Field::select('agent_id', 'Agent', fn () => Agent::orderBy('name')->pluck('name', 'id')->all())->required(),
            Field::money('amount', 'Montant (FCFA)')->required()->min(1),
            Field::datetime('transaction_date', 'Date et heure')->required(),
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
        if ($record->status === 'cancelled') {
            throw ValidationException::withMessages(['amount' => 'Une vente annulée ne peut plus être modifiée.']);
        }
        if (Carbon::parse($data['transaction_date'])->isFuture()) {
            throw ValidationException::withMessages(['transaction_date' => 'La date ne peut pas être dans le futur.']);
        }

        $amount = (float) $data['amount'];

        if ($record->transaction_type === 'distribution') {
            // Le montant déjà débité par cette vente est restitué avant de contrôler le solde.
            $available = app(MasterSoldeService::class)->getAvailableBalance() + (float) $record->amount;
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => 'Solde UV insuffisant : '.Fcfa::format($available).' disponibles pour cette vente.']);
            }

            $agent = Agent::findOrFail($data['agent_id']);
            $debt = (float) $agent->current_debt - ((int) $agent->id === (int) $record->agent_id ? (float) $record->amount : 0.0);
            if ($agent->debt_limit > 0 && $debt + $amount > (float) $agent->debt_limit) {
                throw ValidationException::withMessages(['amount' => 'Ce montant dépasse le plafond de dette de l\'agent ('.Fcfa::format($agent->debt_limit).').']);
            }
        }

        return parent::save($data, $record);
    }

    public static function deleteWarning(Model $record): string
    {
        return 'La vente est supprimée et les soldes (UV et dette de l\'agent) sont recalculés.';
    }
}
