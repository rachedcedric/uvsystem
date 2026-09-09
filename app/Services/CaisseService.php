<?php

namespace App\Services;

use App\Models\CaisseMouvement;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source unique de vérité pour "Ma caisse" : solde de liquidité manuel,
 * jamais mis en cache, toujours recalculé en direct depuis la somme des
 * mouvements (entrées - sorties). Une sortie ne peut jamais dépasser le
 * solde actuellement disponible (la caisse ne peut pas devenir négative).
 */
class CaisseService
{
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
        return $this->totals()['solde'];
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
     * Point d'entrée unique pour enregistrer un mouvement de caisse : valide
     * (montant > 0, une sortie ne peut pas dépasser le solde actuel) puis
     * crée, dans une transaction.
     *
     * @throws ValidationException
     */
    public function enregistrer(
        string $type,
        float $amount,
        string $libelle,
        ?string $dateMouvement = null,
        ?string $notes = null,
    ): CaisseMouvement {
        $this->validateMouvement($type, $amount);

        return DB::transaction(function () use ($type, $amount, $libelle, $dateMouvement, $notes) {
            return CaisseMouvement::create([
                'user_id' => Auth::id(),
                'type' => $type,
                'amount' => $amount,
                'libelle' => $libelle,
                'notes' => $notes,
                'date_mouvement' => $dateMouvement ?? now(),
            ]);
        });
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
}
