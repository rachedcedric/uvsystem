<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ModesPaiement extends MobileResource
{
    public static function slug(): string
    {
        return 'modes-paiement';
    }

    public static function model(): string
    {
        return PaymentMethod::class;
    }

    public static function label(): string
    {
        return 'Mode de paiement';
    }

    public static function plural(): string
    {
        return 'Modes de paiement';
    }

    public static function icon(): string
    {
        return 'credit-card';
    }

    public static function group(): string
    {
        return 'Paramètres';
    }

    public static function searchColumns(): array
    {
        return ['name', 'code'];
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public static function can(User $user, string $ability, ?Model $record = null): bool
    {
        return Settings::allows($user, $ability);
    }

    public static function fields(): array
    {
        return [
            // Le code identifie les remboursements existants : modifiable seulement à la création.
            Field::text('code', 'Code')->required()->maxLength(50)->unique('payment_methods')->onlyOnCreate()
                ->hint('Identifiant unique (ex. cash, mobile_money)'),
            Field::text('name', 'Nom affiché')->required()->maxLength(100),
            Field::text('icon', 'Icône (emoji)')->maxLength(10)->hint('Ex. : 💵 📱 🏦'),
            Field::textarea('description', 'Description')->maxLength(255),
            Field::number('sort_order', 'Ordre d\'affichage')->default(0)->min(0),
            Field::toggle('is_active', 'Actif')->default(true),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => trim(($record->icon ? $record->icon.' ' : '').$record->name),
            'sub' => $record->code,
            'badge' => $record->is_active ? null : 'Inactif',
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Code' => (string) $record->code,
            'Nom' => (string) $record->name,
            'Icône' => (string) $record->icon,
            'Description' => (string) $record->description,
            'Ordre' => (string) $record->sort_order,
            'Statut' => $record->is_active ? 'Actif' : 'Inactif',
        ];
    }

    public static function deleteWarning(Model $record): string
    {
        return 'Les remboursements déjà enregistrés avec ce mode gardent son code. Préférez le désactiver.';
    }
}
