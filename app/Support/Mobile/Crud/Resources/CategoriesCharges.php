<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\User;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CategoriesCharges extends MobileResource
{
    public static function slug(): string
    {
        return 'categories-charges';
    }

    public static function model(): string
    {
        return ChargeCategorie::class;
    }

    public static function label(): string
    {
        return 'Catégorie de charge';
    }

    public static function feminine(): bool
    {
        return true;
    }

    public static function plural(): string
    {
        return 'Catégories de charges';
    }

    public static function icon(): string
    {
        return 'tag';
    }

    public static function group(): string
    {
        return 'Configuration';
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
        return $user->can('edit_charges');
    }

    public static function canDelete(User $user, Model $record): bool
    {
        return static::can($user, 'delete', $record) && ! Charge::where('category_id', $record->getKey())->withTrashed()->exists();
    }

    public static function fields(): array
    {
        return [
            Field::text('code', 'Code')->required()->maxLength(50)->unique('charge_categories')->rules('alpha_dash')
                ->hint('Identifiant unique (ex. loyer, internet)'),
            Field::text('name', 'Nom affiché')->required()->maxLength(100),
            Field::text('description', 'Description')->maxLength(255),
            Field::number('sort_order', 'Ordre d\'affichage')->default(0)->min(0),
            Field::toggle('is_active', 'Actif')->default(true),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->name,
            'sub' => $record->code,
            'badge' => $record->is_active ? null : 'Inactif',
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Code' => (string) $record->code,
            'Nom' => (string) $record->name,
            'Description' => (string) $record->description,
            'Ordre' => (string) $record->sort_order,
            'Statut' => $record->is_active ? 'Actif' : 'Inactif',
            'Charges liées' => (string) Charge::where('category_id', $record->getKey())->count(),
        ];
    }

    public static function deleteWarning(Model $record): string
    {
        return 'Seule une catégorie sans charge enregistrée peut être supprimée.';
    }
}
