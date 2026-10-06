<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Agent;
use App\Models\SousDistributeur;
use App\Models\User;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Fcfa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SousDistributeurs extends MobileResource
{
    public const TYPES = ['standard' => 'Standard', 'premium' => 'Premium', 'vip' => 'VIP'];

    public const STATUTS = ['actif' => 'Actif', 'inactif' => 'Inactif', 'suspendu' => 'Suspendu'];

    public static function slug(): string
    {
        return 'sous-distributeurs';
    }

    public static function model(): string
    {
        return SousDistributeur::class;
    }

    public static function label(): string
    {
        return 'Sous-distributeur';
    }

    public static function plural(): string
    {
        return 'Sous-distributeurs';
    }

    public static function icon(): string
    {
        return 'user-group';
    }

    public static function group(): string
    {
        return 'Agents';
    }

    public static function searchColumns(): array
    {
        return ['nom', 'telephone', 'ville'];
    }

    public static function searchPlaceholder(): string
    {
        return 'Nom, téléphone ou ville';
    }

    public static function filters(): array
    {
        return ['all' => 'Tous'] + self::STATUTS;
    }

    public static function applyFilter(Builder $query, string $filter): Builder
    {
        return $query->where('statut', $filter);
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderBy('nom');
    }

    public static function query(): Builder
    {
        return SousDistributeur::query()->with(['agent:id,name', 'solde']);
    }

    /** Pas de policy dédiée : mêmes droits que la gestion des agents. */
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
            Field::select('agent_id', 'Agent parent', fn () => Agent::orderBy('name')->pluck('name', 'id')->all())->required(),
            Field::text('nom', 'Nom complet')->required()->maxLength(255),
            Field::tel('telephone', 'Téléphone')->required()->maxLength(20)->unique('sous_distributeurs'),
            Field::email('email', 'E-mail')->maxLength(255),
            Field::text('adresse', 'Adresse')->maxLength(255),
            Field::text('ville', 'Ville')->maxLength(100),
            Field::select('type', 'Type', self::TYPES)->required()->default('standard'),
            Field::money('plafond_dette', 'Plafond de dette (FCFA)')->min(0)->hint('Laisser vide pour aucune limite'),
            Field::number('commission_rate', 'Taux de commission (%)')->min(0)->max(100)->default(0),
            Field::select('statut', 'Statut', self::STATUTS)->required()->default('actif'),
            Field::textarea('notes', 'Notes'),
        ];
    }

    public static function row(Model $record): array
    {
        $dette = (float) $record->dette;

        return [
            'title' => $record->nom,
            'sub' => collect([$record->agent?->name, $record->ville])->filter()->implode(' · '),
            'end' => $dette > 0 ? Fcfa::format($dette) : null,
            'badge' => $record->statut === 'actif' ? null : (self::STATUTS[$record->statut] ?? $record->statut),
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Agent parent' => (string) $record->agent?->name,
            'Téléphone' => (string) $record->telephone,
            'E-mail' => (string) $record->email,
            'Adresse' => collect([$record->adresse, $record->ville])->filter()->implode(', '),
            'Type' => self::TYPES[$record->type] ?? (string) $record->type,
            'Statut' => self::STATUTS[$record->statut] ?? (string) $record->statut,
            'Dette actuelle' => Fcfa::format($record->dette),
            'Plafond de dette' => $record->plafond_dette ? Fcfa::format($record->plafond_dette) : 'Aucune limite',
            'Taux de commission' => rtrim(rtrim(number_format((float) $record->commission_rate, 2, ',', ''), '0'), ',').' %',
            'Notes' => (string) $record->notes,
        ];
    }
}
