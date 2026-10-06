<?php

namespace App\Services;

use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source unique de vérité pour "Ma caisse" : solde journalier de liquidité,
 * jamais mis en cache, toujours recalculé en direct depuis les mouvements
 * (entrées - sorties). Chaque journée repart à zéro à minuit.
 */
class CaisseService
{
    public function __construct(private readonly CaissePeriodResolver $periods) {}

    /**
     * @return array{entrees: float, sorties: float, solde: float}
     */
    public function totals(?Carbon $asOf = null): array
    {
        $entreesQuery = CaisseMouvement::query()->where('type', CaisseMouvement::TYPE_ENTREE);
        $sortiesQuery = CaisseMouvement::query()->where('type', CaisseMouvement::TYPE_SORTIE);

        if ($asOf) {
            $entreesQuery->where('date_mouvement', '<=', $asOf);
            $sortiesQuery->where('date_mouvement', '<=', $asOf);
        }

        $entrees = (float) $entreesQuery->sum('amount');
        $sorties = (float) $sortiesQuery->sum('amount');

        return [
            'entrees' => $entrees,
            'sorties' => $sorties,
            'solde' => $entrees - $sorties,
        ];
    }

    public function soldeActuel(): float
    {
        return (float) $this->dayTotals($this->periods->currentDate())['solde'];
    }

    /**
     * Historique complet, du plus ancien au plus récent, avec le solde
     * avant/après calculé en direct pour chaque mouvement (jamais une
     * colonne mise en cache : voir LedgerCascadeService pour la raison
     * exacte pour laquelle un tel cache exige un recalcul en cascade coûteux
     * à maintenir — ici, un simple cumul à la volée l'évite entièrement).
     *
     * @return Collection<int, array{mouvement: CaisseMouvement, solde_avant: float, solde_apres: float}>
     */
    public function historyWithRunningBalance(): Collection
    {
        $mouvements = CaisseMouvement::query()
            ->orderBy('date_mouvement')
            ->orderBy('id')
            ->get();

        $running = 0.0;

        return $mouvements->map(function (CaisseMouvement $mouvement) use (&$running) {
            $avant = $running;
            $running += $mouvement->type === CaisseMouvement::TYPE_ENTREE
                ? (float) $mouvement->amount
                : -1 * (float) $mouvement->amount;

            return [
                'mouvement' => $mouvement,
                'solde_avant' => $avant,
                'solde_apres' => $running,
            ];
        });
    }

    /**
     * Enregistre un mouvement unique (validé, dans une transaction).
     *
     * @throws ValidationException
     */
    public function enregistrer(
        string $type,
        float $amount,
        string $libelle,
        ?string $dateMouvement = null,
        ?string $notes = null,
        ?int $categorieId = null,
    ): CaisseMouvement {
        $this->validateMouvement($type, $amount);
        $categorieId = $this->resolveCategorieId($categorieId, 'categorie');

        return DB::transaction(fn () => CaisseMouvement::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'caisse_categorie_id' => $categorieId,
            'amount' => $amount,
            'libelle' => $libelle,
            'notes' => $notes,
            'date_mouvement' => $dateMouvement ?? now(),
        ]));
    }

    /**
     * Enregistre plusieurs mouvements de façon atomique : toutes les lignes
     * sont validées avant toute écriture, puis créées dans UNE transaction
     * (si une ligne échoue, aucune n'est enregistrée). Un verrou court par
     * utilisateur écarte les doubles soumissions concurrentes.
     *
     * @param  array<int, array{type: string, caisse_categorie_id: int|string|null, libelle: string, amount: float|int|string, notes?: string|null}>  $lignes
     * @return Collection<int, CaisseMouvement>
     *
     * @throws ValidationException
     */
    public function enregistrerLot(array $lignes, ?string $caisseDate = null): Collection
    {
        $lignes = array_values($lignes);

        if ($lignes === []) {
            throw ValidationException::withMessages(['mouvements' => 'Ajoutez au moins un mouvement.']);
        }

        $lock = Cache::lock('caisse:lot:'.(Auth::id() ?? 'guest'), 15);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['mouvements' => 'Un enregistrement est déjà en cours. Patientez un instant.']);
        }

        try {
            $errors = [];
            $entrees = '0';
            $sorties = '0';
            $activeIds = CaisseCategorie::active()->pluck('id')->all();

            foreach ($lignes as $i => $ligne) {
                $amount = (string) ($ligne['amount'] ?? '');

                if (! in_array($ligne['type'] ?? null, [CaisseMouvement::TYPE_ENTREE, CaisseMouvement::TYPE_SORTIE], true)) {
                    $errors["mouvements.$i.type"] = 'Le type doit être Entrée ou Sortie.';
                }

                if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
                    $errors["mouvements.$i.amount"] = 'Le montant doit être supérieur à 0.';
                } elseif (($ligne['type'] ?? null) === CaisseMouvement::TYPE_SORTIE) {
                    $sorties = bcadd($sorties, $amount, 2);
                } else {
                    $entrees = bcadd($entrees, $amount, 2);
                }

                if (! in_array((int) ($ligne['caisse_categorie_id'] ?? 0), $activeIds, true)) {
                    $errors["mouvements.$i.caisse_categorie_id"] = 'Choisissez une catégorie active.';
                }

                if (trim((string) ($ligne['libelle'] ?? '')) === '') {
                    $errors["mouvements.$i.libelle"] = 'Le libellé est obligatoire.';
                }
            }

            if ($errors === []) {
                $disponible = bcadd($this->soldeExact(), $entrees, 2);

                if (bccomp($sorties, $disponible, 2) > 0) {
                    $errors['mouvements'] = sprintf(
                        'Les sorties (%s FCFA) dépassent le solde disponible de la caisse (%s FCFA).',
                        number_format((float) $sorties, 0, ',', ' '),
                        number_format((float) $disponible, 0, ',', ' ')
                    );
                }
            }

            $operationAt = $caisseDate
                ? Carbon::parse($caisseDate, CaissePeriodResolver::TIMEZONE)
                : now(CaissePeriodResolver::TIMEZONE);
            $date = $this->periods->businessDateFor($operationAt);

            if ($operationAt->greaterThan(now(CaissePeriodResolver::TIMEZONE)->endOfMinute())) {
                $errors['caisse_date'] = 'La date et l’heure de caisse ne peuvent pas être dans le futur.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return DB::transaction(function () use ($lignes, $operationAt) {
                return collect($lignes)->map(fn (array $ligne, int $i) => CaisseMouvement::create([
                    'user_id' => Auth::id(),
                    'type' => $ligne['type'],
                    'caisse_categorie_id' => (int) $ligne['caisse_categorie_id'],
                    'amount' => $ligne['amount'],
                    'libelle' => trim($ligne['libelle']),
                    'notes' => filled($ligne['notes'] ?? null) ? trim($ligne['notes']) : null,
                    'date_mouvement' => $operationAt->copy()->addSeconds($i),
                ]));
            });
        } finally {
            $lock->release();
        }
    }

    private function resolveCategorieId(?int $categorieId, string $errorKey): int
    {
        if ($categorieId === null) {
            return (int) CaisseCategorie::where('is_system', true)->value('id');
        }

        if (! CaisseCategorie::active()->whereKey($categorieId)->exists()) {
            throw ValidationException::withMessages([$errorKey => 'Choisissez une catégorie active.']);
        }

        return $categorieId;
    }

    private function soldeExact(): string
    {
        return $this->dayTotals($this->periods->currentDate())['solde'];
    }

    /**
     * @throws ValidationException
     */
    public function validateMouvement(string $type, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant doit être supérieur à 0.',
            ]);
        }

        if ($type === CaisseMouvement::TYPE_SORTIE) {
            $solde = $this->soldeActuel();

            if ($amount > $solde) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Le montant de la sortie ne peut pas dépasser le solde actuel de la caisse. (Demandé : %s FCFA, solde actuel : %s FCFA)',
                        number_format($amount, 0, ',', ' '),
                        number_format($solde, 0, ',', ' ')
                    ),
                ]);
            }
        }
    }

    /** Mouvements d'une journée de caisse, dans l'ordre chronologique. */
    public function mouvementsQuery(Carbon $businessDate): \Illuminate\Database\Eloquent\Builder
    {
        $b = $this->periods->bounds($businessDate);

        return CaisseMouvement::query()
            ->where('date_mouvement', '>=', $b['start'])
            ->where('date_mouvement', '<', $b['end']);
    }

    /**
     * Totaux d'une journée de caisse. Solde = entrées − sorties de la journée.
     *
     * @return array{count: int, entrees: string, sorties: string, solde: string}
     */
    public function dayTotals(Carbon $businessDate): array
    {
        return $this->accumulate($this->mouvementsQuery($businessDate)->get(['type', 'amount']));
    }

    /**
     * Récapitulatif par journée de caisse (plus récente d'abord).
     *
     * @return Collection<int, array{date: Carbon, count: int, entrees: string, sorties: string, solde: string}>
     */
    public function daySummaries(?Carbon $from = null, ?Carbon $to = null, ?string $search = null, ?int $categorieId = null): Collection
    {
        $query = CaisseMouvement::query();

        if ($from) {
            $query->where('date_mouvement', '>=', $this->periods->bounds($from)['start']);
        }
        if ($to) {
            $query->where('date_mouvement', '<', $this->periods->bounds($to)['end']);
        }
        if ($categorieId) {
            $query->where('caisse_categorie_id', $categorieId);
        }

        $days = $query->get(['date_mouvement', 'type', 'amount'])
            ->groupBy(fn (CaisseMouvement $m) => $this->periods->businessDateFor($m->date_mouvement)->toDateString())
            ->map(fn (Collection $group, string $date) => ['date' => Carbon::parse($date, CaissePeriodResolver::TIMEZONE)] + $this->accumulate($group))
            ->sortByDesc(fn (array $day) => $day['date']->timestamp)
            ->values();

        if ($search !== null && trim($search) !== '') {
            $needle = trim($search);
            $days = $days->filter(fn (array $day) => str_contains($day['date']->format('d/m/Y'), $needle)
                || str_contains($day['date']->format('Y-m-d'), $needle))->values();
        }

        return $days;
    }

    /**
     * Solde par catégorie sur une journée (ou, sans date, sur une période).
     *
     * @return Collection<int, array{name: string, color: ?string, entrees: string, sorties: string, solde: string}>
     */
    public function categoryBalances(?Carbon $from, ?Carbon $to): Collection
    {
        $query = CaisseMouvement::query()->with('categorie');

        if ($from) {
            $query->where('date_mouvement', '>=', $this->periods->bounds($from)['start']);
        }
        if ($to) {
            $query->where('date_mouvement', '<', $this->periods->bounds($to)['end']);
        }

        return $query->get()
            ->groupBy(fn (CaisseMouvement $m) => $m->caisse_categorie_id ?? 0)
            ->map(function (Collection $group) {
                $categorie = $group->first()->categorie;

                return [
                    'name' => $categorie?->name ?? 'Non catégorisé',
                    'color' => $categorie?->color,
                ] + $this->accumulate($group);
            })
            ->sortBy('name')
            ->values();
    }

    /**
     * Solde après chaque mouvement d'une journée, dans l'ordre chronologique
     * exact (date_mouvement puis id), en partant de 0 au début de la journée.
     *
     * @return array<int, array{solde_avant: string, solde_apres: string}>
     */
    public function dayRunningBalances(Carbon $businessDate): array
    {
        $running = '0';
        $map = [];

        foreach ($this->mouvementsQuery($businessDate)->orderBy('date_mouvement')->orderBy('id')->get() as $m) {
            $avant = $running;
            $running = $m->type === CaisseMouvement::TYPE_ENTREE
                ? bcadd($running, (string) $m->amount, 2)
                : bcsub($running, (string) $m->amount, 2);
            $map[$m->id] = ['solde_avant' => $avant, 'solde_apres' => $running];
        }

        return $map;
    }

    public function soldeAujourdhui(): string
    {
        return $this->dayTotals($this->periods->currentDate())['solde'];
    }

    public function soldeHier(): string
    {
        return $this->dayTotals($this->periods->yesterdayDate())['solde'];
    }

    /** @param Collection<int, CaisseMouvement> $mouvements */
    private function accumulate(Collection $mouvements): array
    {
        $entrees = '0';
        $sorties = '0';

        foreach ($mouvements as $m) {
            if ($m->type === CaisseMouvement::TYPE_ENTREE) {
                $entrees = bcadd($entrees, (string) $m->amount, 2);
            } else {
                $sorties = bcadd($sorties, (string) $m->amount, 2);
            }
        }

        return [
            'count' => $mouvements->count(),
            'entrees' => $entrees,
            'sorties' => $sorties,
            'solde' => bcsub($entrees, $sorties, 2),
        ];
    }

    public static function fcfa(string|float|int $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }
}
