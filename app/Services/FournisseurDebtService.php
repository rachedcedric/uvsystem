<?php

namespace App\Services;

use App\Models\Fournisseur;
use App\Models\RemboursementFournisseur;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source unique de vérité pour la dette due à un fournisseur "Agent
 * Premium" : ce type de fournisseur approvisionne à crédit (livré avant
 * d'être payé), contrairement à un fournisseur "Banque" (payé comptant,
 * jamais de dette). Chaque approvisionnement d'un fournisseur Premium
 * augmente donc automatiquement la dette envers lui ; chaque
 * RemboursementFournisseur la réduit. Aucun solde mis en cache : toujours
 * recalculé en direct depuis les mouvements réels (même principe que
 * Agent::soldeTotals()), pour ne jamais désynchroniser deux formules.
 */
class FournisseurDebtService
{
    /**
     * @return array{distribue: float, rembourse: float}
     */
    public function soldeTotals(Fournisseur $fournisseur, ?Carbon $asOf = null): array
    {
        $approQuery = $fournisseur->approvisionnements();
        $rembQuery = $fournisseur->remboursementsFournisseurs();

        if ($asOf) {
            $approQuery->where('date_approvisionnement', '<=', $asOf);
            $rembQuery->where('payment_date', '<=', $asOf);
        }

        return [
            'distribue' => (float) $approQuery->sum('amount'),
            'rembourse' => (float) $rembQuery->sum('amount'),
        ];
    }

    /**
     * Dette restante d'un fournisseur donné (non plafonnée à 0 : un
     * trop-remboursé apparaît en négatif, comme Agent::debtAsOf()).
     */
    public function detteRestante(Fournisseur $fournisseur, ?Carbon $asOf = null): float
    {
        $totaux = $this->soldeTotals($fournisseur, $asOf);

        return $totaux['distribue'] - $totaux['rembourse'];
    }

    /**
     * Résumé global (toutes tuiles de DettesFournisseursPage) : somme sur
     * tous les fournisseurs à crédit (type AGENT_PREMIUM).
     *
     * @return array{totalDette: float, totalRembourse: float, resteAPayer: float}
     */
    public function globalSummary(): array
    {
        $fournisseurIds = Fournisseur::query()->creditFournisseurs()->pluck('id');

        $totalDette = (float) \App\Models\Approvisionnement::query()
            ->whereIn('fournisseur_id', $fournisseurIds)
            ->sum('amount');

        $totalRembourse = (float) RemboursementFournisseur::query()
            ->whereIn('fournisseur_id', $fournisseurIds)
            ->sum('amount');

        return [
            'totalDette' => $totalDette,
            'totalRembourse' => $totalRembourse,
            'resteAPayer' => $totalDette - $totalRembourse,
        ];
    }

    /**
     * Point d'entrée unique pour enregistrer un remboursement fournisseur :
     * valide (montant > 0, montant <= reste à payer réel) puis crée, dans
     * une transaction. Miroir de RemboursementService::process().
     *
     * @throws ValidationException
     */
    public function process(
        Fournisseur $fournisseur,
        float $amount,
        string $paymentMethod = 'cash',
        ?string $paymentDate = null,
        ?string $notes = null,
        ?string $reference = null,
    ): RemboursementFournisseur {
        $this->validateAmount($fournisseur, $amount);

        return DB::transaction(function () use ($fournisseur, $amount, $paymentMethod, $paymentDate, $notes, $reference) {
            return RemboursementFournisseur::create([
                'fournisseur_id' => $fournisseur->id,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'payment_date' => $paymentDate ?? now(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    public function validateAmount(Fournisseur $fournisseur, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant du remboursement doit être supérieur à 0.',
            ]);
        }

        $reste = $this->detteRestante($fournisseur);

        if ($amount > $reste) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Le montant du remboursement ne peut pas dépasser le reste à payer. (Demandé : %s FCFA, reste à payer : %s FCFA)',
                    number_format($amount, 0, ',', ' '),
                    number_format($reste, 0, ',', ' ')
                ),
            ]);
        }
    }
}
