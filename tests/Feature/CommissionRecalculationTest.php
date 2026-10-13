<?php

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\CommissionRecalculation;
use App\Models\Fournisseur;
use App\Models\User;
use App\Models\UvTransaction;
use App\Observers\CommissionRecalculationObserver;
use App\Services\CommissionService;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Barème de commission utilisé par ces tests
|--------------------------------------------------------------------------
|
| RefreshDatabase réexécute les migrations, dont
| 2026_01_01_114143_create_paliers_commission_table.php qui insère déjà
| un barème par défaut :
|   20 000 –    599 999 →  1 800 FCFA
|  600 000 –  4 999 999 →  1 800 FCFA
| 5 000 000 –  9 999 999 → 31 915 FCFA
| 10 000 000 – 19 999 999 → 52 725 FCFA
| 20 000 000 – 29 999 999 → 60 125 FCFA
| 30 000 000+             → 70 000 FCFA
|
| On réutilise directement ce barème (déjà en place, on ne réinvente pas
| la formule) plutôt que d'appeler PalierCommissionSeeder, qui contient un
| barème différent et créerait des tranches ambiguës/qui se chevauchent
| dans la base de test.
|
*/

afterEach(function () {
    Carbon::setTestNow();
});

function service(): CommissionService
{
    return app(CommissionService::class);
}

function agentActifPourCommission(): Agent
{
    $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

    AgentSolde::firstOrCreate(['agent_id' => $agent->id], [
        'total_uv_distribues' => 0,
        'total_rembourse' => 0,
        'solde_restant' => 0,
    ]);

    return $agent;
}

function approvisionnerPourCommission(float $amount): void
{
    Approvisionnement::create([
        'user_id' => User::factory()->create()->id,
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'amount' => $amount,
        'date_approvisionnement' => now(),
    ]);
}

/**
 * Crée une vente à un instant précis, sans jamais modifier created_at
 * après coup : on gèle Carbon::now() pour la durée de la création, comme
 * le ferait une vraie vente saisie à ce moment-là.
 */
function venteCommissionA(Carbon $instant, Agent $agent, float $amount): UvTransaction
{
    approvisionnerPourCommission($amount);

    Carbon::setTestNow($instant);
    try {
        return (new UvDistributionService)->distribute(agent: $agent, amount: $amount);
    } finally {
        Carbon::setTestNow();
    }
}

describe('CommissionService::verifierEtCorrigerCommission', function () {

    it('persists a zero commission for a business day with no sales', function () {
        $date = Carbon::create(2026, 3, 10);

        $resultat = service()->verifierEtCorrigerCommission($date, 'manual');

        expect($resultat['statut'])->toBe('created')
            ->and($resultat['nombre_transactions'])->toBe(0)
            ->and($resultat['commission_globale'])->toBe(0.0);

        $commission = Commission::where('is_global', true)->whereDate('date_commission', $date)->first();
        expect($commission)->not->toBeNull()
            ->and($commission->statut)->toBe(Commission::STATUT_CALCULATED);
    });

    it('computes the correct commission for a single sale', function () {
        $agent = agentActifPourCommission();
        $jour = Carbon::create(2026, 3, 11);
        venteCommissionA($jour->copy()->setTime(10, 0), $agent, 1_200_000);

        $resultat = service()->verifierEtCorrigerCommission($jour, 'manual');

        expect($resultat['nombre_transactions'])->toBe(1)
            ->and($resultat['volume_total'])->toBe(1_200_000.0)
            ->and($resultat['commission_globale'])->toBe(1800.0);
    });

    it('aggregates several sales within the business day period into the correct bracket', function () {
        $agent = agentActifPourCommission();
        $jour = Carbon::create(2026, 3, 12);
        // 3 x 2 000 000 = 6 000 000 → tranche 5M-9.999M (31 915), alors que
        // chaque vente individuelle ne dépasserait pas la tranche 600k-4.999M
        // (1 800) : la commission doit bien être calculée sur le total agrégé.
        venteCommissionA($jour->copy()->setTime(9, 0), $agent, 2_000_000);
        venteCommissionA($jour->copy()->setTime(14, 0), $agent, 2_000_000);
        venteCommissionA($jour->copy()->setTime(17, 30), $agent, 2_000_000);

        $resultat = service()->verifierEtCorrigerCommission($jour, 'manual');

        expect($resultat['nombre_transactions'])->toBe(3)
            ->and($resultat['volume_total'])->toBe(6_000_000.0)
            ->and($resultat['commission_globale'])->toBe(31915.0);
    });

    it('returns zero commission when volume is below the lowest palier', function () {
        $agent = agentActifPourCommission();
        $jour = Carbon::create(2026, 3, 13);
        venteCommissionA($jour->copy()->setTime(10, 0), $agent, 10_000);

        $resultat = service()->verifierEtCorrigerCommission($jour, 'manual');

        expect($resultat['commission_globale'])->toBe(0.0)
            ->and($resultat['nombre_transactions'])->toBe(1);
    });

    it('buckets a sale exactly at the 18:02:00 cutoff into the next business day', function () {
        $agent = agentActifPourCommission();
        venteCommissionA(Carbon::create(2026, 3, 14, 18, 2, 0), $agent, 1_200_000);

        $le14 = service()->verifierEtCorrigerCommission(Carbon::create(2026, 3, 14), 'manual');
        $le15 = service()->verifierEtCorrigerCommission(Carbon::create(2026, 3, 15), 'manual');

        expect($le14['nombre_transactions'])->toBe(0)
            ->and($le15['nombre_transactions'])->toBe(1)
            ->and($le15['commission_globale'])->toBe(1800.0);
    });

    it('keeps a sale at 18:01:59 on the current business day', function () {
        $agent = agentActifPourCommission();
        venteCommissionA(Carbon::create(2026, 3, 14, 18, 1, 59), $agent, 1_200_000);

        $le14 = service()->verifierEtCorrigerCommission(Carbon::create(2026, 3, 14), 'manual');

        expect($le14['nombre_transactions'])->toBe(1);
    });

    it('is idempotent: re-running for an already-correct day changes nothing and creates no duplicate row', function () {
        $agent = agentActifPourCommission();
        $jour = Carbon::create(2026, 3, 16);
        venteCommissionA($jour->copy()->setTime(10, 0), $agent, 1_200_000);

        $premier = service()->verifierEtCorrigerCommission($jour, 'manual');
        $deuxieme = service()->verifierEtCorrigerCommission($jour, 'manual');
        $troisieme = service()->verifierEtCorrigerCommission($jour, 'manual');

        expect($premier['statut'])->toBe('created')
            ->and($deuxieme['statut'])->toBe('unchanged')
            ->and($troisieme['statut'])->toBe('unchanged');

        expect(Commission::where('is_global', true)->whereDate('date_commission', $jour)->count())->toBe(1);
    });

    it('corrects a commission row that was manually set to a wrong value, keeping the same row id', function () {
        $agent = agentActifPourCommission();
        $jour = Carbon::create(2026, 3, 17);
        venteCommissionA($jour->copy()->setTime(10, 0), $agent, 1_200_000);

        service()->verifierEtCorrigerCommission($jour, 'manual');

        $commission = Commission::where('is_global', true)->whereDate('date_commission', $jour)->first();
        $idOriginal = $commission->id;
        $commission->forceFill(['commission_globale' => 999999, 'volume_total_jour' => 1])->save();

        $resultat = service()->verifierEtCorrigerCommission($jour, 'manual');

        expect($resultat['statut'])->toBe('updated')
            ->and($resultat['ancienne_commission'])->toBe(999999.0)
            ->and($resultat['nouvelle_commission'])->toBe(1800.0);

        $corrigee = Commission::where('is_global', true)->whereDate('date_commission', $jour)->first();
        expect(Commission::where('is_global', true)->whereDate('date_commission', $jour)->count())->toBe(1)
            ->and($corrigee->id)->toBe($idOriginal)
            ->and((float) $corrigee->commission_globale)->toBe(1800.0);
    });

    it('logs every recalculation attempt to commission_recalculations, including no-op runs', function () {
        $agent = agentActifPourCommission();
        $jour = Carbon::create(2026, 3, 18);
        venteCommissionA($jour->copy()->setTime(10, 0), $agent, 1_200_000);

        service()->verifierEtCorrigerCommission($jour, 'manual');
        service()->verifierEtCorrigerCommission($jour, 'manual');

        $logs = CommissionRecalculation::whereDate('date_commission', $jour)->orderBy('id')->get();

        expect($logs)->toHaveCount(2)
            ->and($logs[0]->statut)->toBe('created')
            ->and($logs[1]->statut)->toBe('unchanged');
    });
});

describe('CommissionService::rattraperCommissionsManquantes', function () {

    it('catches up missing commissions for closed business days after a simulated scheduler outage', function () {
        $agent = agentActifPourCommission();

        // Le scheduler n'a tourné à aucun moment sur ces 3 jours.
        venteCommissionA(Carbon::create(2026, 4, 1, 10, 0), $agent, 1_200_000);
        venteCommissionA(Carbon::create(2026, 4, 2, 10, 0), $agent, 700_000);
        venteCommissionA(Carbon::create(2026, 4, 3, 10, 0), $agent, 700_000);

        Carbon::setTestNow(Carbon::create(2026, 4, 5, 9, 0));
        $resultats = service()->rattraperCommissionsManquantes();
        Carbon::setTestNow();

        expect($resultats)->toHaveKey('2026-04-01')
            ->and($resultats)->toHaveKey('2026-04-02')
            ->and($resultats)->toHaveKey('2026-04-03')
            ->and($resultats['2026-04-01']['statut'])->toBe('created');

        expect(Commission::where('is_global', true)->whereDate('date_commission', '2026-04-01')->count())->toBe(1);
    });

    it('is idempotent across repeated runs (restart-after-restart scenario)', function () {
        $agent = agentActifPourCommission();
        venteCommissionA(Carbon::create(2026, 4, 10, 10, 0), $agent, 1_200_000);

        Carbon::setTestNow(Carbon::create(2026, 4, 12, 9, 0));
        $premier = service()->rattraperCommissionsManquantes();
        $deuxieme = service()->rattraperCommissionsManquantes();
        Carbon::setTestNow();

        expect($premier)->toHaveKey('2026-04-10')
            ->and($deuxieme)->not->toHaveKey('2026-04-10'); // déjà correcte, plus rien à rattraper

        expect(Commission::where('is_global', true)->whereDate('date_commission', '2026-04-10')->count())->toBe(1);
    });

    it('picks up commissions explicitly flagged recalculation_required', function () {
        // Ligne volontairement incorrecte (valeur ne correspondant à aucune
        // vente réelle) et marquée à recalculer — isolé de tout mécanisme
        // de vente pour ne tester que le balayage de rattrapage lui-même.
        $jour = Carbon::create(2026, 4, 15);
        $commission = Commission::create([
            'agent_id' => null,
            'is_global' => true,
            'date_commission' => $jour,
            'volume_total_jour' => 999999,
            'commission_globale' => 999999,
            'volume_agent' => 0,
            'part_agent' => 0,
            'pourcentage_contribution' => 100,
            'nombre_transactions' => 1,
            'montant_vente' => 999999,
            'montant_commission' => 999999,
            'seuil_min' => 0,
            'seuil_max' => 0,
            'statut' => Commission::STATUT_RECALCULATION_REQUIRED,
        ]);

        Carbon::setTestNow(Carbon::create(2026, 4, 17, 9, 0));
        $resultats = service()->rattraperCommissionsManquantes();
        Carbon::setTestNow();

        expect($resultats)->toHaveKey('2026-04-15')
            ->and($commission->fresh()->statut)->toBe(Commission::STATUT_CALCULATED)
            // Aucune vente réelle ce jour-là : corrigée à 0, pas laissée à 999999.
            ->and((float) $commission->fresh()->commission_globale)->toBe(0.0);
    });
});

describe('Late sale after closure (CommissionRecalculationObserver)', function () {

    it('corrects the commission immediately when a late sale is recorded after closure', function () {
        $jour = Carbon::create(2026, 5, 2);
        service()->verifierEtCorrigerCommission($jour, 'manual'); // clôture initiale, 0 vente

        // La vente porte un created_at de 17h (toujours dans la période de
        // $jour, avant le cutoff de 18:02), mais n'est ajoutée en base que
        // maintenant, après que $jour a déjà été clôturée ci-dessus.
        $agent = agentActifPourCommission();
        $venteTardive = venteCommissionA($jour->copy()->setTime(17, 0), $agent, 1_200_000);

        (new CommissionRecalculationObserver)->recalculerSiNecessaire($venteTardive->fresh());

        $commission = Commission::where('is_global', true)->whereDate('date_commission', $jour)->first();

        expect($commission->statut)->toBe(Commission::STATUT_CALCULATED)
            ->and((float) $commission->commission_globale)->toBe(1800.0)
            ->and((int) $commission->nombre_transactions)->toBe(1);

        expect(Commission::where('is_global', true)->whereDate('date_commission', $jour)->count())->toBe(1);
    });

    it('corrects the commission when a sale within a closed day is amended afterwards', function () {
        $jour = Carbon::create(2026, 5, 4);
        $agent = agentActifPourCommission();
        $vente = venteCommissionA($jour->copy()->setTime(10, 0), $agent, 1_200_000);

        service()->verifierEtCorrigerCommission($jour, 'manual');

        $commissionInitiale = Commission::where('is_global', true)->whereDate('date_commission', $jour)->first();
        expect((float) $commissionInitiale->commission_globale)->toBe(1800.0);

        // Correction du montant de la vente après clôture (jamais de
        // modification de created_at) — fait passer la journée dans une
        // tranche de commission différente.
        $vente->forceFill(['amount' => 6_000_000])->save();

        (new CommissionRecalculationObserver)->recalculerSiNecessaire($vente->fresh());

        $commissionCorrigee = $commissionInitiale->fresh();
        expect((float) $commissionCorrigee->commission_globale)->toBe(31915.0)
            ->and($commissionCorrigee->id)->toBe($commissionInitiale->id)
            ->and(Commission::where('is_global', true)->whereDate('date_commission', $jour)->count())->toBe(1);
    });

    it('does not touch a business day that has not been closed yet', function () {
        $jour = Carbon::create(2026, 5, 3);
        $agent = agentActifPourCommission();
        $vente = venteCommissionA($jour->copy()->setTime(10, 0), $agent, 1_200_000);

        (new CommissionRecalculationObserver)->recalculerSiNecessaire($vente->fresh());

        expect(Commission::where('is_global', true)->whereDate('date_commission', $jour)->exists())->toBeFalse();
    });
});
