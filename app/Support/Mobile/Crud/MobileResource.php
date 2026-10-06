<?php

namespace App\Support\Mobile\Crud;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Description d'une ressource gérée par les écrans mobiles génériques
 * (liste, fiche, formulaire). Chaque ressource réutilise le modèle, les
 * policies et les règles métier existants ; seule la présentation est ici.
 */
abstract class MobileResource
{
    abstract public static function slug(): string;

    /** @return class-string<Model> */
    abstract public static function model(): string;

    abstract public static function label(): string;

    abstract public static function plural(): string;

    /** @return list<Field> */
    abstract public static function fields(): array;

    /**
     * Ligne de liste.
     *
     * @return array{title:string, sub?:?string, end?:?string, endClass?:?string, icon?:string, tone?:?string, badge?:?string, badgeTone?:?string}
     */
    abstract public static function row(Model $record): array;

    /**
     * Détails de la fiche : [libellé => valeur].
     *
     * @return array<string,string>
     */
    abstract public static function details(Model $record): array;

    public static function icon(): string
    {
        return 'rectangle-stack';
    }

    /** Ressource utilisée seulement pour modifier / supprimer depuis un écran dédié (absente du menu « Plus »). */
    public static function hidden(): bool
    {
        return false;
    }

    /** Rubrique du menu « Plus ». */
    public static function group(): string
    {
        return 'Gestion';
    }

    /** Colonnes interrogées par la recherche. @return list<string> */
    public static function searchColumns(): array
    {
        return ['name'];
    }

    public static function searchPlaceholder(): string
    {
        return 'Rechercher';
    }

    /**
     * Filtres en puces : [clé => libellé]. 'all' = pas de filtre.
     *
     * @return array<string,string>
     */
    public static function filters(): array
    {
        return [];
    }

    public static function applyFilter(Builder $query, string $filter): Builder
    {
        return $query;
    }

    public static function query(): Builder
    {
        return static::model()::query();
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /** Résumé affiché sous la recherche (ex. total). */
    public static function summary(Builder $query): ?string
    {
        return null;
    }

    // ------------------------------------------------------------ droits

    public static function can(User $user, string $ability, ?Model $record = null): bool
    {
        $target = $record ?? static::model();

        return Gate::forUser($user)->allows($ability, $target);
    }

    // ------------------------------------------------------ persistance

    /** @return array<string,mixed> Valeurs initiales du formulaire d'édition. */
    public static function toForm(Model $record): array
    {
        $data = [];
        foreach (static::fields() as $field) {
            $data[$field->name] = $field->fromRecord($record->getAttribute($field->name));
        }

        return $data;
    }

    /**
     * Enregistre les données validées et normalisées.
     *
     * @param  array<string,mixed>  $data
     */
    public static function save(array $data, ?Model $record): Model
    {
        if ($record) {
            $record->update($data);

            return $record;
        }

        return static::model()::create($data);
    }

    public static function canDelete(User $user, Model $record): bool
    {
        return static::can($user, 'delete', $record);
    }

    public static function delete(Model $record): void
    {
        $record->delete();
    }

    /** Écran de détail d'un enregistrement (surchargé quand un écran dédié existe). */
    public static function showUrl(Model $record): string
    {
        return route('m.crud.show', [static::slug(), $record->getKey()]);
    }

    public static function indexUrl(): string
    {
        return route('m.crud.index', static::slug());
    }

    /** Titre de l'enregistrement (en-tête de fiche). */
    public static function title(Model $record): string
    {
        return static::row($record)['title'];
    }

    /** Message de confirmation de suppression. */
    public static function deleteWarning(Model $record): string
    {
        return 'Cette action est définitive.';
    }
}
