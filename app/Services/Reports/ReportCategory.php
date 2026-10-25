<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Une catégorie de « Rapports et exports » (ventes, approvisionnements, dettes…) : mêmes lignes, mêmes totaux et
 * mêmes droits pour l'écran Web, l'écran mobile, le PDF et l'Excel. Les valeurs des cellules restent brutes
 * (Carbon, nombres) : ReportPresenter les met en forme selon le support.
 *
 * Types de colonne : text, datetime, date, money (montant), signed (montant signé : dette > 0, crédit/découvert < 0),
 * status (pastille).
 */
abstract class ReportCategory
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Nom d'icône Heroicons (sans préfixe). */
    abstract public function icon(): string;

    public function description(): string
    {
        return '';
    }

    /** @return list<string> au moins une de ces permissions est requise */
    abstract protected function permissions(): array;

    public function allows(?User $user): bool
    {
        return $user !== null && collect($this->permissions())->contains(fn (string $p) => $user->can($p));
    }

    /** Catégorie liée à une période (false : instantané, ex. situation des agents). */
    public function dated(): bool
    {
        return true;
    }

    /** @return list<array{label: string, type: string}> */
    abstract public function columns(): array;

    /**
     * @param  array{q?: ?string, type?: ?string, statut?: ?string}  $filters
     * @return Collection<int, array{cells: list<mixed>, cancelled?: bool, tone?: ?string}>
     */
    abstract public function rows(DateRange $range, array $filters = [], ?int $limit = null): Collection;

    /**
     * @param  array{q?: ?string, type?: ?string, statut?: ?string}  $filters
     * @return array{count: int, total: float, total_label: string, extra?: array<string, string>}
     */
    abstract public function summary(DateRange $range, array $filters = []): array;

    public function searchPlaceholder(): string
    {
        return 'Rechercher';
    }
}
