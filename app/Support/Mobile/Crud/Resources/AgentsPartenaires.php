<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\AgentPartenaire;
use App\Models\User;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Agents partenaires. Les colonnes réelles de la table sont nom_entreprise,
 * numero_telephone, lieu, notes et is_active (le formulaire Filament, lui,
 * utilise des champs qui n'existent pas en base).
 */
class AgentsPartenaires extends MobileResource
{
    public static function slug(): string
    {
        return 'agents-partenaires';
    }

    public static function model(): string
    {
        return AgentPartenaire::class;
    }

    public static function label(): string
    {
        return 'Agent partenaire';
    }

    public static function plural(): string
    {
        return 'Agents partenaires';
    }

    public static function icon(): string
    {
        return 'building-office';
    }

    public static function group(): string
    {
        return 'Agents';
    }

    public static function searchColumns(): array
    {
        return ['nom_entreprise', 'numero_telephone', 'lieu'];
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderBy('nom_entreprise');
    }

    public static function can(User $user, string $ability, ?Model $record = null): bool
    {
        return match ($ability) {
            'viewAny', 'view' => $user->can('view_agents'),
            'create' => $user->can('create_agents'),
            'update' => $user->can('edit_agents'),
            default => $user->can('delete_agents'),
        };
    }

    public static function fields(): array
    {
        return [
            Field::text('nom_entreprise', 'Nom de l\'entreprise')->required()->maxLength(255),
            Field::tel('numero_telephone', 'Téléphone')->required()->maxLength(255),
            Field::text('lieu', 'Lieu')->required()->maxLength(255),
            Field::toggle('is_active', 'Actif')->default(true),
            Field::textarea('notes', 'Notes'),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->nom_entreprise,
            'sub' => collect([$record->lieu, $record->numero_telephone])->filter()->implode(' · '),
            'badge' => $record->is_active ? null : 'Inactif',
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Entreprise' => (string) $record->nom_entreprise,
            'Téléphone' => (string) $record->numero_telephone,
            'Lieu' => (string) $record->lieu,
            'Statut' => $record->is_active ? 'Actif' : 'Inactif',
            'Notes' => (string) $record->notes,
        ];
    }
}
