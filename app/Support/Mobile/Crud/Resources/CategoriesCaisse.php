<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\CaisseCategorie;
use App\Models\User;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CategoriesCaisse extends MobileResource
{
    public static function slug(): string
    {
        return 'categories-caisse';
    }

    public static function model(): string
    {
        return CaisseCategorie::class;
    }

    public static function label(): string
    {
        return 'Catégorie de caisse';
    }

    public static function plural(): string
    {
        return 'Catégories de caisse';
    }

    public static function icon(): string
    {
        return 'tag';
    }

    public static function group(): string
    {
        return 'Caisse';
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public static function can(User $user, string $ability, ?Model $record = null): bool
    {
        return match ($ability) {
            'viewAny', 'view' => $user->can('view_caisse'),
            'create' => $user->can('create_caisse'),
            'update' => $user->can('edit_caisse'),
            default => $user->can('delete_caisse'),
        };
    }

    public static function canDelete(User $user, Model $record): bool
    {
        return $user->can('delete_caisse') && ! $record->is_system && ! $record->isUsed();
    }

    public static function fields(): array
    {
        return [
            Field::text('name', 'Nom de la catégorie')->required()->maxLength(100),
            Field::color('color', 'Couleur (facultatif)'),
            Field::number('sort_order', 'Ordre d\'affichage')->required()->min(0)->default(0),
            Field::toggle('is_active', 'Active')->default(true),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->name,
            'sub' => $record->is_system ? 'Catégorie système' : null,
            'badge' => $record->is_active ? null : 'Inactive',
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Nom' => (string) $record->name,
            'Statut' => $record->is_active ? 'Active' : 'Inactive',
            'Ordre' => (string) $record->sort_order,
            'Utilisée' => $record->isUsed() ? 'Oui' : 'Non',
        ];
    }

    public static function save(array $data, ?Model $record): Model
    {
        if (CaisseCategorie::nameIsTaken((string) $data['name'], $record?->getKey())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Une catégorie portant ce nom existe déjà.']);
        }

        return parent::save($data, $record);
    }

    public static function deleteWarning(Model $record): string
    {
        return 'Une catégorie système ou déjà utilisée ne peut pas être supprimée.';
    }
}
