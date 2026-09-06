<?php

namespace App\Services;

use App\Models\Approvisionnement;
use App\Models\UvTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconstruit chronologiquement solde_uv_avant/solde_uv_apres sur le grand
 * livre partagé (ventes/retours actifs de uv_transactions +
 * approvisionnements, TOUS agents confondus) à partir d'une date donnée.
 *
 * Ces deux colonnes ne sont PAS un solde par agent : elles sont un
 * instantané du Solde UV Disponible global (App\Services\MasterSoldeService),
 * partagé par toutes les ventes/retours/approvisionnements. Éditer,
 * supprimer ou restaurer une ligne passée rend donc incohérents tous les
 * instantanés postérieurs, quel que soit l'agent concerné — ce service les
 * réécrit dans l'ordre chronologique réel (date réelle, puis type, puis id
 * en cas d'égalité), de façon atomique (DB::transaction + verrou du grand
 * livre).
 *
 * N'écrit jamais via save()/update() : uniquement forceFill()->saveQuietly(),
 * pour ne redéclencher aucun observer (dont lui-même, ni
 * UvTransactionObserver, UvTransactionSecurityObserver,
 * CommissionRecalculationObserver). C'est sûr car solde_uv_avant/apres
 * n'influencent ni la dette agent (agrégat indépendant, cf.
 * Agent::soldeTotals()), ni la commission (basée sur
 * amount/type/created_at/status), ni la signature anti-fraude.
 *
 * Limite connue et acceptée : le champ qui a déclenché ce recalcul (ex. le
 * montant d'une vente éditée) est déjà committé en base au moment où ce
 * service s'exécute — Filament n'enveloppe pas ses sauvegardes dans une
 * transaction englobante couvrant aussi les observers déclenchés. Seule la
 * reconstruction du grand livre elle-même est garantie atomique
 * (tout ou rien). En cas d'échec de cette reconstruction (incident DB),
 * rebuildAll() permet une réparation complète a posteriori.
 */
class LedgerCascadeService
{
    protected static bool $running = false;

    public function __construct(
        protected MasterSoldeService $masterSoldeService = new MasterSoldeService
    ) {}

    public function rebuildFrom(Carbon $from): void
    {
        if (self::$running) {
            Log::warning('LedgerCascadeService::rebuildFrom() ignoré (déjà en cours, appel ré-entrant).', [
                'from' => $from->toDateTimeString(),
            ]);

            return;
        }

        self::$running = true;

        try {
            DB::transaction(function () use ($from) {
                $this->masterSoldeService->lockLedgerForUpdate();

                $ventes = UvTransaction::where('transaction_date', '>=', $from)
                    ->where('status', '!=', 'cancelled')
                    ->lockForUpdate()
                    ->get();

                $approvisionnements = Approvisionnement::where('date_approvisionnement', '>=', $from)
                    ->lockForUpdate()
                    ->get();

                $mouvements = $this->mergeChronologically($ventes, $approvisionnements);

                $running = $this->masterSoldeService->getAvailableBalance(asOf: $from->copy()->subSecond());

                foreach ($mouvements as $mouvement) {
                    $avant = $running;
                    $running += $mouvement['sign'] * $mouvement['amount'];
                    $apres = $running;

                    $mouvement['model']->forceFill([
                        'solde_uv_avant' => $avant,
                        'solde_uv_apres' => $apres,
                    ])->saveQuietly();
                }

                $this->masterSoldeService->syncPersistedSnapshot();
            });
        } finally {
            self::$running = false;
        }
    }

    /**
     * Reconstruit l'intégralité du grand livre depuis le tout premier
     * mouvement existant. Utile pour une réparation manuelle (backfill,
     * incident) — jamais appelé automatiquement par les observers.
     */
    public function rebuildAll(): void
    {
        $premiereVente = UvTransaction::where('status', '!=', 'cancelled')->min('transaction_date');
        $premierApprovisionnement = Approvisionnement::min('date_approvisionnement');

        $dates = array_filter([$premiereVente, $premierApprovisionnement], fn ($d) => $d !== null);

        if (empty($dates)) {
            return;
        }

        $this->rebuildFrom(Carbon::parse(min($dates)));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, UvTransaction>  $ventes
     * @param  \Illuminate\Support\Collection<int, Approvisionnement>  $approvisionnements
     * @return array<int, array{model: UvTransaction|Approvisionnement, timestamp: int, kindOrder: int, id: int, sign: int, amount: float}>
     */
    protected function mergeChronologically($ventes, $approvisionnements): array
    {
        $mouvements = [];

        foreach ($ventes as $t) {
            $mouvements[] = [
                'model' => $t,
                'timestamp' => $t->transaction_date->getTimestamp(),
                'kindOrder' => 0,
                'id' => $t->id,
                'sign' => $t->transaction_type === 'distribution' ? -1 : 1,
                'amount' => (float) $t->amount,
            ];
        }

        foreach ($approvisionnements as $a) {
            $mouvements[] = [
                'model' => $a,
                'timestamp' => $a->date_approvisionnement->getTimestamp(),
                'kindOrder' => 1,
                'id' => $a->id,
                'sign' => 1,
                'amount' => (float) $a->amount,
            ];
        }

        usort(
            $mouvements,
            fn ($a, $b) => [$a['timestamp'], $a['kindOrder'], $a['id']] <=> [$b['timestamp'], $b['kindOrder'], $b['id']]
        );

        return $mouvements;
    }
}
