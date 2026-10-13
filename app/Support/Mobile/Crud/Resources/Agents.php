<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Agent;
use App\Models\User;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Fcfa;
use Illuminate\Database\Eloquent\Model;

/** Formulaires de création / modification des agents (liste et fiche : écrans dédiés). */
class Agents extends MobileResource
{
    public const TYPES = ['standard' => 'Standard', 'premium' => 'Premium', 'vip' => 'VIP'];

    public const STATUSES = ['active' => 'Actif', 'inactive' => 'Inactif', 'suspended' => 'Suspendu', 'blocked' => 'Bloqué'];

    public static function slug(): string
    {
        return 'agents';
    }

    public static function model(): string
    {
        return Agent::class;
    }

    public static function label(): string
    {
        return 'Agent';
    }

    public static function plural(): string
    {
        return 'Agents';
    }

    public static function icon(): string
    {
        return 'users';
    }

    public static function showUrl(Model $record): string
    {
        return route('m.agents.show', $record);
    }

    public static function indexUrl(): string
    {
        return route('m.agents');
    }

    public static function fields(): array
    {
        return [
            Field::text('name', 'Nom complet')->required()->maxLength(255)->placeholder('Ex. : Mamadou Diallo'),
            Field::tel('phone', 'Téléphone')->required()->maxLength(20)->unique('agents')
                ->rules('regex:/^[0-9+\s]{8,20}$/')->hint('8 à 15 chiffres')->placeholder('+225 01 23 45 67 89'),
            Field::email('email', 'E-mail')->maxLength(255)->unique('agents'),
            Field::text('address', 'Adresse')->maxLength(500),
            Field::select('type', 'Type d\'agent', self::TYPES)->required()->default('standard'),
            Field::text('zone', 'Zone géographique')->maxLength(100)->placeholder('Ex. : Abidjan Nord'),
            Field::money('debt_limit', 'Plafond de dette')->min(0)->max(999999999)->default(500000)->hint('Crédit maximum autorisé'),
            Field::number('commission_rate', 'Taux de commission (%)')->min(0)->max(100)->default(5),
            Field::select('status', 'Statut', self::STATUSES)->required()->default('active'),
            Field::textarea('notes', 'Notes internes'),
            // Master et admin : lier la fiche à un compte de l'entreprise (le sien compris) pour qu'il retrouve ses propres
            // opérations. Le champ n'existe pas pour les autres rôles : une valeur forgée est ignorée.
            ...(auth()->user()?->isAdmin() ? [
                Field::select('user_id', 'Compte de connexion lié', fn (): array => ['' => 'Aucun compte'] + User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->unique('agents', 'user_id')->hint('Facultatif : donne à ce compte l\'accès à son espace agent.'),
            ] : []),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->name,
            'sub' => collect([$record->zone, $record->phone])->filter()->implode(' · '),
            'end' => Fcfa::format($record->current_debt),
        ];
    }

    public static function details(Model $record): array
    {
        return [];
    }
}
