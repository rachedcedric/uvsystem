<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\MasterSoldeService;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Fcfa;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Approvisionnements extends MobileResource
{
    public static function slug(): string
    {
        return 'approvisionnements';
    }

    public static function model(): string
    {
        return Approvisionnement::class;
    }

    public static function label(): string
    {
        return 'Approvisionnement';
    }

    public static function plural(): string
    {
        return 'Approvisionnements';
    }

    public static function hidden(): bool
    {
        return true;
    }

    public static function indexUrl(): string
    {
        return route('m.approvisionnements');
    }

    public static function fields(): array
    {
        return [
            Field::select('fournisseur_id', 'Fournisseur', fn () => Fournisseur::orderBy('name')->pluck('name', 'id')->all())->required(),
            Field::money('amount', 'Montant (FCFA)')->required()->min(1),
            Field::datetime('date_approvisionnement', 'Date et heure')->required(),
            Field::textarea('notes', 'Notes'),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->fournisseur?->name ?? 'Fournisseur supprimé',
            'sub' => $record->reference,
            'icon' => 'truck',
            'end' => '+'.Fcfa::number($record->amount),
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Référence' => (string) $record->reference,
            'Fournisseur' => (string) $record->fournisseur?->name,
            'Montant' => Fcfa::format($record->amount),
            'Date et heure' => Carbon::parse($record->date_approvisionnement)->translatedFormat('j F Y, H:i'),
            'Enregistré par' => (string) $record->user?->name,
            'Notes' => (string) $record->notes,
        ];
    }

    public static function save(array $data, ?Model $record): Model
    {
        if (Carbon::parse($data['date_approvisionnement'])->isFuture()) {
            throw ValidationException::withMessages(['date_approvisionnement' => 'La date et l’heure ne peuvent pas être dans le futur.']);
        }

        // Réduire un approvisionnement ne doit pas rendre le solde UV négatif.
        $after = app(MasterSoldeService::class)->getAvailableBalance() - (float) $record->amount + (float) $data['amount'];
        if ($after < 0) {
            throw ValidationException::withMessages(['amount' => 'Ce montant rendrait le solde UV négatif ('.Fcfa::format($after).'). Des ventes en dépendent.']);
        }

        return parent::save($data, $record);
    }

    public static function canDelete(User $user, Model $record): bool
    {
        return static::can($user, 'delete', $record)
            && app(MasterSoldeService::class)->getAvailableBalance() - (float) $record->amount >= 0;
    }

    public static function deleteWarning(Model $record): string
    {
        return 'Le montant est retiré du solde UV disponible. Impossible si des ventes en dépendent.';
    }
}
