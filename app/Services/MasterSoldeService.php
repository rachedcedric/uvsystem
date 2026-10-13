<?php

namespace App\Services;

use App\Models\MasterSolde;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Source de vérité unique pour le Solde UV Disponible (= le stock de crédit UV
 * réellement disponible à la vente, tous Masters confondus) :
 *
 *     Solde UV Disponible = Total approvisionnements validés
 *                          - Total ventes (distributions) validées
 *                          + Total retours UV validés
 *
 * Toute lecture (dashboard, formulaires de vente, API) et toute écriture
 * (validation avant vente, débit atomique) doivent passer par ce service.
 * Les remboursements financiers (table remboursements) n'entrent jamais dans
 * ce calcul : ils soldent une dette agent en FCFA, pas un retour d'UV.
 */
class MasterSoldeService
{
    private const SEUIL_ALERTE_DEFAUT = 100000;

    /**
     * Verrouille l'intégralité du grand livre (toutes les lignes master_soldes)
     * pour la durée de la transaction DB en cours. À appeler en tout premier,
     * à l'intérieur d'un DB::transaction(), avant de lire le solde et de créer
     * une vente : ceci sérialise les ventes concurrentes sur le même solde.
     */
    public function lockLedgerForUpdate(): void
    {
        // Le verrou porte sur les lignes existantes ; s'il n'y en a aucune
        // (aucun approvisionnement n'a jamais été fait), il n'y a rien à
        // sérialiser et le solde sera de toute façon 0.
        MasterSolde::query()->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Calcule le Solde UV Disponible réel, en direct depuis les mouvements
     * validés (jamais depuis une valeur mise en cache qui peut être obsolète).
     *
     * @param  Carbon|null  $asOf  Si fourni, reconstitue le solde tel qu'il était à cette date/heure.
     */
    public function getAvailableBalance(?Carbon $asOf = null): float
    {
        return $this->getGlobalTotals($asOf)['solde_disponible'];
    }

    /**
     * @return array{total_approvisionne: float, total_distribue: float, total_retourne: float, solde_disponible: float}
     */
    public function getGlobalTotals(?Carbon $asOf = null): array
    {
        $approQuery = TenantContext::table('approvisionnements')->whereNull('deleted_at');

        $distQuery = TenantContext::table('uv_transactions')
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at');

        $retQuery = TenantContext::table('uv_transactions')
            ->where('transaction_type', 'retour')
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at');

        if ($asOf) {
            $approQuery->where('date_approvisionnement', '<=', $asOf);
            $distQuery->where('transaction_date', '<=', $asOf);
            $retQuery->where('transaction_date', '<=', $asOf);
        }

        $totalApprovisionne = (float) $approQuery->sum('amount');
        $totalDistribue = (float) $distQuery->sum('amount');
        $totalRetourne = (float) $retQuery->sum('amount');

        return [
            'total_approvisionne' => $totalApprovisionne,
            'total_distribue' => $totalDistribue,
            'total_retourne' => $totalRetourne,
            'solde_disponible' => $totalApprovisionne - $totalDistribue + $totalRetourne,
        ];
    }

    /**
     * Recalcule les totaux réels et les recopie dans toutes les lignes
     * master_soldes existantes, pour que tout lecteur legacy qui lit encore
     * la colonne mise en cache (plutôt que d'appeler getAvailableBalance())
     * voie une valeur cohérente avec l'historique réel des transactions.
     * N'est jamais la source d'autorité : uniquement un instantané persisté.
     */
    public function syncPersistedSnapshot(): void
    {
        $totals = $this->getGlobalTotals();

        // S'il n'existe encore aucune ligne (tout premier approvisionnement),
        // en amorcer une pour que l'instantané reste consultable, sans quoi
        // aucun lecteur legacy ne pourrait jamais le lire.
        if (MasterSolde::query()->count() === 0) {
            $ownerId = Auth::id() ?? MasterSolde::query()->value('user_id');

            if (! $ownerId) {
                $ownerId = User::query()
                    ->whereHas('roles', fn ($q) => $q->where('name', 'master'))
                    ->value('id') ?? User::query()->value('id');
            }

            if ($ownerId) {
                MasterSolde::create(['user_id' => $ownerId, 'total_approvisionne' => 0, 'total_distribue' => 0, 'solde_disponible' => 0]);
            }
        }

        MasterSolde::query()->each(function (MasterSolde $solde) use ($totals) {
            $solde->forceFill([
                'total_approvisionne' => $totals['total_approvisionne'],
                'total_distribue' => $totals['total_distribue'] - $totals['total_retourne'],
                'solde_disponible' => $totals['solde_disponible'],
            ])->save();
        });
    }

    /**
     * Récupère (ou crée) la ligne master_soldes d'un utilisateur, uniquement
     * utile pour cibler une notification ou afficher un instantané ; jamais
     * pour valider une vente (utiliser getAvailableBalance()).
     */
    public function getMasterSolde(?int $userId = null): ?MasterSolde
    {
        $userId = $userId ?? Auth::id();

        if (! $userId) {
            return null;
        }

        return MasterSolde::firstOrCreate(
            ['user_id' => $userId],
            [
                'total_approvisionne' => 0,
                'total_distribue' => 0,
                'solde_disponible' => 0,
            ]
        );
    }

    /**
     * Vérifie si le Solde UV Disponible couvre le montant demandé.
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->getAvailableBalance() >= $amount;
    }

    /**
     * Vérifie si le solde est en dessous du seuil d'alerte (en FCFA)
     */
    public function isBelowThreshold(): bool
    {
        $threshold = (float) env('MASTER_SOLDE_ALERT_THRESHOLD', self::SEUIL_ALERTE_DEFAUT);

        return $this->getAvailableBalance() < $threshold;
    }

    /**
     * Envoie une notification si le solde est bas (best-effort, non bloquant).
     */
    public function checkAndAlertLowBalance(): void
    {
        if (! $this->isBelowThreshold()) {
            return;
        }

        $available = $this->getAvailableBalance();
        $formattedSolde = number_format($available, 0, ',', ' ');

        $user = Auth::user();

        if ($user) {
            Notification::make()
                ->warning()
                ->title('Solde UV Disponible faible')
                ->body("Le solde UV disponible est de {$formattedSolde} FCFA. Pensez à vous réapprovisionner.")
                ->icon('heroicon-o-exclamation-triangle')
                ->persistent()
                ->sendToDatabase($user);
        }

        Log::warning('Solde UV Disponible sous le seuil d\'alerte', [
            'solde_disponible' => $available,
            'threshold' => env('MASTER_SOLDE_ALERT_THRESHOLD', self::SEUIL_ALERTE_DEFAUT),
        ]);
    }

    /**
     * Valide (non-atomiquement) qu'une vente peut être effectuée. Utile pour
     * un retour rapide côté formulaire, mais ne remplace pas la vérification
     * verrouillée faite dans UvDistributionService::distribute().
     *
     * @throws \Exception
     */
    public function validateDistribution(float $amount): void
    {
        $available = $this->getAvailableBalance();

        if ($amount > $available) {
            throw new \Exception(
                'Solde UV insuffisant pour effectuer cette vente. '.
                "Disponible : {$this->format($available)} FCFA, demandé : {$this->format($amount)} FCFA."
            );
        }
    }

    private function format(float $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }
}
