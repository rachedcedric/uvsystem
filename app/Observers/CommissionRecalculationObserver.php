<?php

namespace App\Observers;

use App\Models\Commission;
use App\Models\UvTransaction;
use App\Services\CommissionBusinessDateResolver;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Détecte les ventes ajoutées, modifiées, supprimées ou restaurées après la
 * clôture de leur journée métier de commission, et déclenche une correction
 * immédiate (après commit, jamais dans la transaction de la vente elle-même
 * : un recalcul ne doit jamais faire échouer une vente légitime). Si la
 * correction immédiate échoue, la ligne est marquée recalculation_required
 * pour être reprise par le balayage de rattrapage (commissions:rattraper).
 */
class CommissionRecalculationObserver
{
    public function created(UvTransaction $transaction): void
    {
        $this->handle($transaction);
    }

    public function updated(UvTransaction $transaction): void
    {
        $this->handle($transaction);
    }

    public function deleted(UvTransaction $transaction): void
    {
        $this->handle($transaction);
    }

    public function restored(UvTransaction $transaction): void
    {
        $this->handle($transaction);
    }

    private function handle(UvTransaction $transaction): void
    {
        if ($transaction->transaction_type !== 'distribution') {
            return;
        }

        DB::afterCommit(fn () => $this->recalculerSiNecessaire($transaction));
    }

    /**
     * Logique réellement exécutée après commit de la vente. Volontairement
     * publique (au lieu de private) pour être testable directement : sous
     * RefreshDatabase, les tests s'exécutent dans une transaction englobante
     * qui n'est jamais réellement commitée, donc DB::afterCommit() ne se
     * déclenche jamais pendant les tests — cette méthode permet de vérifier
     * exactement la logique qui s'exécuterait après un vrai commit.
     */
    public function recalculerSiNecessaire(UvTransaction $transaction): void
    {
        try {
            $dateAffectee = app(CommissionBusinessDateResolver::class)->resolve($transaction->created_at);

            $commission = Commission::where('is_global', true)
                ->whereDate('date_commission', $dateAffectee)
                ->first();

            // Journée pas encore clôturée : rien à corriger, le calcul
            // normal (18:02 ou action admin) prendra en compte cette vente.
            if (! $commission) {
                return;
            }

            $resultat = app(CommissionService::class)->verifierEtCorrigerCommission($dateAffectee, 'late_sale');

            if ($resultat['statut'] === 'failed') {
                $commission->forceFill(['statut' => Commission::STATUT_RECALCULATION_REQUIRED])->save();
            }
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la détection de vente tardive pour recalcul de commission', [
                'uv_transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
