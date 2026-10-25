<?php

namespace App\Services\Reports;

use App\Models\ClotureJournaliere;
use App\Models\ClotureSolde;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lecture de l'historique des dettes et crédits : les clôtures quotidiennes enregistrées (ClotureSoldesService),
 * jamais les soldes actuels. Mêmes journées et mêmes lignes pour l'écran Web, l'écran mobile, le PDF et l'Excel.
 *
 * Filtres (sur les lignes figées) : type (agent / fournisseur), nature (dette / crédit), recherche par nom,
 * journées (avec soldes / sans solde). Les journées sont classées de la plus récente à la plus ancienne.
 */
class ClotureHistoryService
{
    public const TYPES = ['all' => 'Agents et fournisseurs', 'agent' => 'Agents', 'fournisseur' => 'Fournisseurs'];

    public const NATURES = ['' => 'Dettes et crédits', 'dette' => 'Dettes', 'credit' => 'Crédits'];

    public const JOURS = ['' => 'Toutes les journées', 'avec' => 'Avec soldes', 'sans' => 'Sans solde'];

    /**
     * @param  array{type?: ?string, nature?: ?string, q?: ?string, jours?: ?string}  $filters
     */
    public function jours(DateRange $range, array $filters, int $parPage, int $page = 1): LengthAwarePaginator
    {
        $paginator = $this->query($range, $filters)->paginate($parPage, ['*'], 'page', $page);

        return $paginator->setCollection($this->presenter($paginator->getCollection(), $filters));
    }

    /** @return Collection<int, array<string, mixed>> toutes les journées de la période (exports) */
    public function tous(DateRange $range, array $filters): Collection
    {
        return $this->presenter($this->query($range, $filters)->get(), $filters);
    }

    /** Dernière journée de la période qui peut avoir une clôture : hier au plus tard (la journée en cours n'est pas finie). */
    public static function derniereJourneeClose(DateRange $range): Carbon
    {
        $hier = Carbon::yesterday(config('app.timezone'));

        return $range->to->lessThan($hier) ? $range->to->copy()->startOfDay() : $hier;
    }

    public static function filtresActifs(array $filters): bool
    {
        return ($filters['type'] ?? 'all') !== 'all' || ($filters['nature'] ?? '') !== '' || trim((string) ($filters['q'] ?? '')) !== '';
    }

    private function query(DateRange $range, array $filters): Builder
    {
        $lignes = $this->filtreLignes($filters);
        $query = ClotureJournaliere::query()
            ->whereBetween('date', [$range->from->toDateString(), self::derniereJourneeClose($range)->toDateString()])
            ->orderByDesc('date');

        return match ($filters['jours'] ?? '') {
            'avec' => $query->whereHas('soldes', $lignes),
            'sans' => $query->whereDoesntHave('soldes', $lignes),
            default => $query,
        };
    }

    /** @return \Closure(Builder): void */
    private function filtreLignes(array $filters): \Closure
    {
        $type = $filters['type'] ?? 'all';
        $nature = $filters['nature'] ?? '';
        $q = trim((string) ($filters['q'] ?? ''));

        return function (Builder $query) use ($type, $nature, $q) {
            $query->when(in_array($type, [ClotureSolde::TYPE_AGENT, ClotureSolde::TYPE_FOURNISSEUR], true), fn (Builder $b) => $b->where('compte_type', $type))
                ->when(array_key_exists($nature, ClotureSolde::NATURES), fn (Builder $b) => $b->where('nature', $nature))
                ->when($q !== '', fn (Builder $b) => $b->where('nom', 'like', '%'.$q.'%'));
        };
    }

    /**
     * @param  Collection<int, ClotureJournaliere>  $clotures
     * @return Collection<int, array<string, mixed>>
     */
    private function presenter(Collection $clotures, array $filters): Collection
    {
        if ($clotures->isEmpty()) {
            return collect();
        }

        $lignes = ClotureSolde::query()
            ->whereIn('cloture_id', $clotures->modelKeys())
            ->tap($this->filtreLignes($filters))
            ->orderByDesc('montant')->orderBy('nom')
            ->get(['cloture_id', 'compte_type', 'compte_id', 'nom', 'type_label', 'nature', 'montant'])
            ->groupBy('cloture_id');
        $filtres = self::filtresActifs($filters);

        return $clotures->map(function (ClotureJournaliere $cloture) use ($lignes, $filtres) {
            $jour = ($lignes[$cloture->id] ?? collect())->map(fn (ClotureSolde $l) => [
                'type' => $l->compte_type,
                'type_label' => $l->type_label,
                'id' => $l->compte_id,
                'nom' => $l->nom,
                'nature' => $l->nature,
                'nature_label' => $l->natureLabel(),
                'montant' => (float) $l->montant,
                'tone' => $l->nature === ClotureSolde::NATURE_DETTE ? 'danger' : 'ok',
            ])->values();
            $dettes = $jour->where('nature', ClotureSolde::NATURE_DETTE);
            $credits = $jour->where('nature', ClotureSolde::NATURE_CREDIT);

            return [
                'date' => $cloture->jour(),
                'lignes' => $jour,
                'nb_dettes' => $dettes->count(),
                'nb_credits' => $credits->count(),
                'total_dettes' => round($dettes->sum('montant'), 2),
                'total_credits' => round($credits->sum('montant'), 2),
                'resume' => self::resume($dettes->count(), $credits->count(), $filtres),
                'source' => $cloture->source,
                'closed_at' => $cloture->closed_at,
                'ecart' => $cloture->hasEcart(),
            ];
        })->values();
    }

    /** « 2 dettes • 1 crédit », ou « Aucun solde ». */
    public static function resume(int $dettes, int $credits, bool $filtres = false): string
    {
        $parts = array_filter([
            $dettes > 0 ? $dettes.' dette'.($dettes > 1 ? 's' : '') : null,
            $credits > 0 ? $credits.' crédit'.($credits > 1 ? 's' : '') : null,
        ]);

        return $parts === [] ? ($filtres ? 'Aucun solde pour ces filtres' : 'Aucun solde') : implode(' • ', $parts);
    }
}
