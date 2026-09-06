<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionRecalculation;
use App\Models\PalierCommission;
use App\Models\UvTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    public function __construct(
        protected CommissionBusinessDateResolver $dateResolver = new CommissionBusinessDateResolver
    ) {}

    public function calculerCommissionGlobale(float $volumeTotal): float
    {
        $palier = PalierCommission::findPalierForMontant($volumeTotal);
        if ($palier) {
            return (float) $palier->commission;
        }

        $premierPalier = PalierCommission::where('is_active', true)->orderBy('seuil_min', 'asc')->first();
        if ($premierPalier && $volumeTotal < $premierPalier->seuil_min) {
            return 0.0;
        }

        $dernierPalier = PalierCommission::where('is_active', true)->orderBy('seuil_max', 'desc')->first();
        if ($dernierPalier && $volumeTotal > $dernierPalier->seuil_max) {
            return (float) $dernierPalier->commission;
        }

        return 0.0;
    }

    public function getPalier(float $volumeTotal): ?PalierCommission
    {
        return PalierCommission::findPalierForMontant($volumeTotal);
    }

    /**
     * @deprecated Utiliser CommissionBusinessDateResolver::resolve() directement.
     *             Conservé pour compatibilité (utilisé par MainKpisWidget).
     */
    public function getDateCommission(Carbon $dateTransaction): Carbon
    {
        return $this->dateResolver->resolve($dateTransaction);
    }

    /**
     * @deprecated Utiliser CommissionBusinessDateResolver::periodFor() directement.
     *             Conservé pour compatibilité (utilisé par MainKpisWidget).
     */
    public function getPeriodeVentes(Carbon $dateCommission): array
    {
        return $this->dateResolver->periodFor($dateCommission);
    }

    /**
     * Recalcule la commission globale d'une journée métier à partir des
     * ventes réelles, et met à jour la ligne existante uniquement si la
     * valeur a changé (idempotent : jamais de doublon, jamais de
     * suppression). Journalise systématiquement le résultat dans
     * commission_recalculations, y compris quand rien n'a changé.
     *
     * C'est le SEUL endroit qui écrit dans la table commissions — utilisé
     * par la clôture planifiée (18:02), le rattrapage automatique, le
     * recalcul manuel (admin) et le backfill historique.
     */
    public function verifierEtCorrigerCommission(Carbon $dateCommission, string $source = 'manual'): array
    {
        $dateCommission = $dateCommission->copy()->startOfDay();

        $resultat = [
            'date_commission' => $dateCommission->format('Y-m-d'),
            'volume_total' => 0.0,
            'commission_globale' => 0.0,
            'nombre_transactions' => 0,
            'nombre_agents' => 0,
            'palier_applique' => null,
            'statut' => 'failed',
            'ancienne_commission' => null,
            'nouvelle_commission' => null,
            'erreurs' => [],
        ];

        [$periodeDebut, $periodeFin] = $this->dateResolver->periodFor($dateCommission);

        $transactions = UvTransaction::where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$periodeDebut, $periodeFin])
            ->get();

        $volumeTotal = (float) $transactions->sum('amount');
        $nombreTransactions = $transactions->count();
        $nombreAgents = $transactions->pluck('agent_id')->unique()->count();
        $commissionGlobale = $this->calculerCommissionGlobale($volumeTotal);
        $palier = $this->getPalier($volumeTotal);

        $resultat['volume_total'] = $volumeTotal;
        $resultat['commission_globale'] = $commissionGlobale;
        $resultat['nombre_transactions'] = $nombreTransactions;
        $resultat['nombre_agents'] = $nombreAgents;
        $resultat['palier_applique'] = $palier ? [
            'seuil_min' => (float) $palier->seuil_min,
            'seuil_max' => (float) $palier->seuil_max,
            'commission' => (float) $palier->commission,
        ] : null;

        try {
            DB::transaction(function () use (
                $dateCommission, $volumeTotal, $commissionGlobale, $palier,
                $nombreTransactions, $nombreAgents, $source, &$resultat
            ) {
                $existante = Commission::withTrashed()
                    ->where('is_global', true)
                    ->whereDate('date_commission', $dateCommission)
                    ->lockForUpdate()
                    ->first();

                if ($existante && $existante->trashed()) {
                    $existante->restore();
                }

                $ancienneCommission = $existante ? (float) $existante->commission_globale : null;
                $inchange = $existante !== null
                    && $this->valeursEquivalentes((float) $existante->volume_total_jour, $volumeTotal)
                    && $this->valeursEquivalentes((float) $existante->commission_globale, $commissionGlobale)
                    && (int) $existante->nombre_transactions === $nombreTransactions
                    && $existante->statut === Commission::STATUT_CALCULATED;

                $attributs = [
                    'agent_id' => null,
                    'is_global' => true,
                    'date_commission' => $dateCommission->toDateString(),
                    'volume_total_jour' => $volumeTotal,
                    'commission_globale' => $commissionGlobale,
                    'volume_agent' => 0,
                    'part_agent' => 0,
                    'pourcentage_contribution' => 100,
                    'nombre_transactions' => $nombreTransactions,
                    'montant_vente' => $volumeTotal,
                    'montant_commission' => $commissionGlobale,
                    'seuil_min' => $palier ? $palier->seuil_min : 0,
                    'seuil_max' => $palier ? $palier->seuil_max : 0,
                    'statut' => Commission::STATUT_CALCULATED,
                    'calculated_at' => now(),
                    'notes' => sprintf(
                        'Commission globale du %s - %d transactions - %d agents',
                        $dateCommission->format('d/m/Y'),
                        $nombreTransactions,
                        $nombreAgents
                    ),
                ];

                // On réutilise volontairement la ligne déjà verrouillée
                // ($existante) plutôt que Commission::updateOrCreate() :
                // sa propre recherche interne fait une égalité stricte sur
                // date_commission, qui peut ne pas retrouver une ligne dont
                // la valeur stockée porte une composante horaire (cast
                // 'date') — cela provoquait une tentative d'INSERT en
                // double, rejetée par la contrainte unique. En repartant de
                // la ligne trouvée via whereDate() ci-dessus, la mise à
                // jour cible toujours la bonne ligne.
                if ($existante !== null) {
                    $existante->fill($attributs)->save();
                    $commission = $existante;
                } else {
                    $commission = Commission::create($attributs);
                }

                $statutLigne = $existante === null ? 'created' : ($inchange ? 'unchanged' : 'updated');

                $resultat['statut'] = $statutLigne;
                $resultat['ancienne_commission'] = $ancienneCommission;
                $resultat['nouvelle_commission'] = $commissionGlobale;

                if ($statutLigne !== 'unchanged') {
                    activity()
                        ->performedOn($commission)
                        ->causedBy(Auth::user())
                        ->event('commission_calculated')
                        ->withProperties([
                            'date' => $dateCommission->format('Y-m-d'),
                            'volume' => $volumeTotal,
                            'commission' => $commissionGlobale,
                            'transactions' => $nombreTransactions,
                            'agents' => $nombreAgents,
                            'source' => $source,
                        ])
                        ->log("Commission globale {$statutLigne}: {$commissionGlobale} FCFA pour volume {$volumeTotal} FCFA");
                }

                CommissionRecalculation::create([
                    'date_commission' => $dateCommission->toDateString(),
                    'commission_id' => $commission->id,
                    'nombre_ventes' => $nombreTransactions,
                    'montant_total_ventes' => $volumeTotal,
                    'ancienne_commission' => $ancienneCommission,
                    'nouvelle_commission' => $commissionGlobale,
                    'statut' => $statutLigne,
                    'source' => $source,
                    'user_id' => Auth::id(),
                    'message' => null,
                ]);
            });
        } catch (\Exception $e) {
            $resultat['statut'] = 'failed';
            $resultat['erreurs'][] = $e->getMessage();

            Log::error('Erreur vérification/correction commission', [
                'date' => $dateCommission->format('Y-m-d'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            CommissionRecalculation::create([
                'date_commission' => $dateCommission->toDateString(),
                'commission_id' => null,
                'nombre_ventes' => $nombreTransactions,
                'montant_total_ventes' => $volumeTotal,
                'ancienne_commission' => null,
                'nouvelle_commission' => null,
                'statut' => 'failed',
                'source' => $source,
                'user_id' => Auth::id(),
                'message' => $e->getMessage(),
            ]);
        }

        return $resultat;
    }

    /**
     * Compare deux montants en tolérant l'imprécision flottante liée aux
     * conversions decimal:2 <-> float (une différence réelle est toujours
     * d'au moins 1 centime).
     */
    protected function valeursEquivalentes(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }

    /**
     * @deprecated Conservé pour compatibilité avec CommissionResource,
     *             ListCommissions et CalculerCommissionsCommand — leur
     *             format de retour ne change pas. En interne, délègue
     *             entièrement à verifierEtCorrigerCommission() : le
     *             paramètre $forcer n'a plus d'effet sur le comportement
     *             (le recalcul est désormais toujours sûr et idempotent),
     *             il est conservé uniquement pour ne pas casser la
     *             signature des appelants existants.
     */
    public function calculerCommissionsPourDate(Carbon $dateCommission, bool $forcer = false, string $source = 'manual'): array
    {
        $resultat = $this->verifierEtCorrigerCommission($dateCommission, $source);

        return [
            'date_commission' => $resultat['date_commission'],
            'volume_total' => $resultat['volume_total'],
            'commission_globale' => $resultat['commission_globale'],
            'nombre_agents' => $resultat['nombre_agents'],
            'nombre_transactions' => $resultat['nombre_transactions'],
            'palier_applique' => $resultat['palier_applique'],
            'deja_calcule' => $resultat['statut'] === 'unchanged',
            'erreurs' => $resultat['erreurs'],
            'commissions_creees' => in_array($resultat['statut'], ['created', 'updated', 'unchanged'], true) ? 1 : 0,
            'montant_total' => $resultat['commission_globale'],
        ];
    }

    public function calculerCommissionsDuJour(): array
    {
        return $this->calculerCommissionsPourDate(Carbon::today());
    }

    public function calculerCommissionsHier(): array
    {
        return $this->calculerCommissionsPourDate(Carbon::yesterday());
    }

    /**
     * Recalcule chaque journée d'une période, jour par jour, en réutilisant
     * verifierEtCorrigerCommission() (idempotent, jamais destructif).
     */
    public function recalculerCommissionsPourPeriode(Carbon $dateDebut, Carbon $dateFin, string $source = 'manual'): array
    {
        $resultats = [];
        $date = $dateDebut->copy()->startOfDay();
        $fin = $dateFin->copy()->startOfDay();

        while ($date->lessThanOrEqualTo($fin)) {
            $resultats[$date->format('Y-m-d')] = $this->verifierEtCorrigerCommission($date->copy(), $source);
            $date->addDay();
        }

        return $resultats;
    }

    /**
     * Balaie les journées métier déjà closes sur une fenêtre glissante
     * (config('commission.catchup_window_days')) et corrige toute journée
     * manquante ou marquée "recalculation_required". Appelé par le
     * scheduler toutes les 15 minutes ET par l'action admin "journées
     * incomplètes uniquement" — même logique, un seul endroit.
     */
    public function rattraperCommissionsManquantes(?int $joursMax = null): array
    {
        $joursMax ??= (int) config('commission.catchup_window_days', 30);
        $maintenant = Carbon::now();
        $dateCommissionCourante = $this->dateResolver->resolve($maintenant);

        $resultats = [];

        for ($i = 1; $i <= $joursMax; $i++) {
            $date = $dateCommissionCourante->copy()->subDays($i);

            if (! $this->dateResolver->isClosed($date, $maintenant)) {
                continue;
            }

            $commission = Commission::where('is_global', true)
                ->whereDate('date_commission', $date)
                ->first();

            $aBesoinDeRattrapage = $commission === null
                || $commission->statut === Commission::STATUT_RECALCULATION_REQUIRED;

            if (! $aBesoinDeRattrapage) {
                continue;
            }

            $resultats[$date->format('Y-m-d')] = $this->verifierEtCorrigerCommission($date, 'catchup');
        }

        return $resultats;
    }

    public function getPartAgentPourDate(int $agentId, Carbon $date): ?Commission
    {
        return Commission::where('agent_id', $agentId)->where('is_global', false)->whereDate('date_commission', $date)->first();
    }

    public function getResumeCommissionsAgent(int $agentId, ?Carbon $dateDebut = null, ?Carbon $dateFin = null): array
    {
        $query = Commission::forAgent($agentId);
        if ($dateDebut && $dateFin) {
            $query->forPeriod($dateDebut, $dateFin);
        }
        $commissions = $query->get();

        return [
            'agent_id' => $agentId,
            'total_parts' => $commissions->sum('part_agent'),
            'nombre_jours' => $commissions->count(),
            'volume_total' => $commissions->sum('volume_agent'),
            'pourcentage_moyen' => $commissions->avg('pourcentage_contribution'),
        ];
    }

    public function getStatistiquesGlobales(?Carbon $dateDebut = null, ?Carbon $dateFin = null): array
    {
        $queryGlobales = Commission::globales();
        $queryParts = Commission::partsOnly();

        if ($dateDebut && $dateFin) {
            $queryGlobales->forPeriod($dateDebut, $dateFin);
            $queryParts->forPeriod($dateDebut, $dateFin);
        }

        $commissionsGlobales = $queryGlobales->get();
        $parts = $queryParts->get();

        return [
            'totaux' => [
                'volume_total' => $commissionsGlobales->sum('volume_total_jour'),
                'commissions_totales' => $commissionsGlobales->sum('commission_globale'),
                'nombre_jours' => $commissionsGlobales->count(),
            ],
            'top_agents' => $parts->groupBy('agent_id')->map(fn ($group) => [
                'agent_id' => $group->first()->agent_id,
                'agent_name' => $group->first()->agent?->name ?? 'N/A',
                'total_parts' => $group->sum('part_agent'),
            ])->sortByDesc('total_parts')->take(10)->values()->toArray(),
        ];
    }
}
