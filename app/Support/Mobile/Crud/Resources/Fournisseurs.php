<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\Fournisseur;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Fournisseurs extends MobileResource
{
    public const TYPES = ['BANQUE' => 'Banque', 'AGENT_PREMIUM' => 'Agent Premium'];

    public static function slug(): string
    {
        return 'fournisseurs';
    }

    public static function model(): string
    {
        return Fournisseur::class;
    }

    public static function label(): string
    {
        return 'Fournisseur';
    }

    public static function plural(): string
    {
        return 'Fournisseurs';
    }

    public static function icon(): string
    {
        return 'building-library';
    }

    public static function group(): string
    {
        return 'Approvisionnement';
    }

    public static function searchColumns(): array
    {
        return ['name', 'contact', 'email'];
    }

    public static function searchPlaceholder(): string
    {
        return 'Nom, téléphone ou e-mail';
    }

    public static function filters(): array
    {
        return ['all' => 'Tous', 'BANQUE' => 'Banques', 'AGENT_PREMIUM' => 'Agents premium', 'inactive' => 'Inactifs'];
    }

    public static function applyFilter(Builder $query, string $filter): Builder
    {
        return $filter === 'inactive' ? $query->where('is_active', false) : $query->where('type', $filter);
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    public static function fields(): array
    {
        return [
            Field::text('name', 'Nom du fournisseur')->required()->maxLength(255),
            Field::select('type', 'Type de fournisseur', self::TYPES)->required()->default('BANQUE'),
            Field::toggle('is_active', 'Actif')->default(true),
            Field::tel('contact', 'Téléphone')->maxLength(255),
            Field::email('email', 'E-mail')->maxLength(255),
            Field::textarea('address', 'Adresse'),
            Field::textarea('notes', 'Notes'),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->name,
            'sub' => collect([self::TYPES[$record->type] ?? $record->type, $record->contact])->filter()->implode(' · '),
            'badge' => $record->is_active ? null : 'Inactif',
        ];
    }

    public static function details(Model $record): array
    {
        $debt = [];
        if ($record->type === 'AGENT_PREMIUM') {
            $totals = app(\App\Services\FournisseurDebtService::class)->soldeTotals($record);
            $debt = [
                'Total à crédit' => \App\Support\Mobile\Fcfa::format($totals['distribue']),
                'Déjà payé' => \App\Support\Mobile\Fcfa::format($totals['rembourse']),
                'Reste à payer' => \App\Support\Mobile\Fcfa::format($totals['distribue'] - $totals['rembourse']),
            ];
        }

        return $debt + [
            'Type' => self::TYPES[$record->type] ?? (string) $record->type,
            'Statut' => $record->is_active ? 'Actif' : 'Inactif',
            'Téléphone' => (string) $record->contact,
            'E-mail' => (string) $record->email,
            'Adresse' => (string) $record->address,
            'Notes' => (string) $record->notes,
        ];
    }
}
