<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\PalierCommission;
use App\Models\User;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Fcfa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Paliers de commission : la commission est fixe selon le total journalier des ventes. */
class Paliers extends MobileResource
{
    public static function slug(): string
    {
        return 'paliers';
    }

    public static function model(): string
    {
        return PalierCommission::class;
    }

    public static function label(): string
    {
        return 'Palier de commission';
    }

    public static function plural(): string
    {
        return 'Paliers de commission';
    }

    public static function icon(): string
    {
        return 'chart-bar';
    }

    public static function group(): string
    {
        return 'Paramètres';
    }

    public static function searchColumns(): array
    {
        return [];
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderBy('seuil_min');
    }

    public static function can(User $user, string $ability, ?Model $record = null): bool
    {
        return Settings::allows($user, $ability);
    }

    public static function fields(): array
    {
        return [
            Field::money('seuil_min', 'Seuil minimum (FCFA)')->required()->min(0)->hint('Total journalier minimum'),
            Field::money('seuil_max', 'Seuil maximum (FCFA)')->required()->min(1)->rules('gt:data.seuil_min')->hint('Total journalier maximum'),
            Field::money('commission', 'Commission (FCFA)')->required()->min(0)->hint('Montant fixe pour ce palier'),
            Field::toggle('is_active', 'Actif')->default(true),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => Fcfa::number($record->seuil_min).' → '.Fcfa::format($record->seuil_max),
            'sub' => 'Commission '.Fcfa::format($record->commission),
            'badge' => $record->is_active ? null : 'Inactif',
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Seuil minimum' => Fcfa::format($record->seuil_min),
            'Seuil maximum' => Fcfa::format($record->seuil_max),
            'Commission' => Fcfa::format($record->commission),
            'Statut' => $record->is_active ? 'Actif' : 'Inactif',
        ];
    }
}
