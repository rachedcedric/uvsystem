<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\OperationDateTime;
use Illuminate\Database\Eloquent\Model;

/** Modification / suppression d'une charge (création avec justificatif : écran dédié). */
class Charges extends MobileResource
{
    public static function slug(): string
    {
        return 'charges';
    }

    public static function model(): string
    {
        return Charge::class;
    }

    public static function label(): string
    {
        return 'Charge';
    }

    public static function plural(): string
    {
        return 'Charges';
    }

    public static function hidden(): bool
    {
        return true;
    }

    public static function showUrl(Model $record): string
    {
        return route('m.charges.show', $record);
    }

    public static function indexUrl(): string
    {
        return route('m.charges');
    }

    public static function fields(): array
    {
        return [
            Field::text('libelle', 'Libellé')->required()->maxLength(255),
            Field::money('montant', 'Montant (FCFA)')->required()->min(1),
            Field::select('category_id', 'Catégorie', fn () => ChargeCategorie::getActiveOptions())->required(),
            Field::date('date_charge', 'Date')->required()->rules('before_or_equal:today'),
            Field::time('heure_charge', 'Heure')->required(),
            Field::textarea('description', 'Description'),
        ];
    }

    public static function row(Model $record): array
    {
        return ['title' => $record->libelle];
    }

    public static function details(Model $record): array
    {
        return [];
    }

    public static function save(array $data, ?Model $record): Model
    {
        OperationDateTime::fromInputs($data['date_charge'], $data['heure_charge'], 'heure_charge');
        $data['updated_by'] = auth()->id();

        return parent::save($data, $record);
    }

    public static function deleteWarning(Model $record): string
    {
        return 'La charge est retirée des totaux et du revenu net.';
    }
}
