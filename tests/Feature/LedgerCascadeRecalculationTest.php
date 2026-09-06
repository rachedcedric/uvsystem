<?php

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\CommissionRecalculation;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\CommissionService;
use App\Services\MasterSoldeService;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function ledgerAgent(): Agent
{
    $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

    AgentSolde::firstOrCreate(['agent_id' => $agent->id], [
        'total_uv_distribues' => 0,
        'total_rembourse' => 0,
        'solde_restant' => 0,
    ]);

    return $agent;
}

function ledgerApprovisionnement(Carbon $at, float $amount): Approvisionnement
{
    return Approvisionnement::create([
        'user_id' => User::factory()->create()->id,
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'amount' => $amount,
        'date_approvisionnement' => $at->toDateString(),
    ]);
}

function ledgerVente(Agent $agent, Carbon $at, float $amount): UvTransaction
{
    return (new UvDistributionService)->distribute(
        agent: $agent,
        amount: $amount,
        transactionDate: $at->toDateTimeString(),
    );
}

function ledgerRemboursement(Agent $agent, Carbon $at, float $amount): Remboursement
{
    return (new RemboursementService)->process(
        agent: $agent,
        amount: $amount,
        paymentMethod: 'cash',
        paymentDate: $at->toDateTimeString(),
    );
}

describe('LedgerCascadeService — reconstruction du grand livre global', function () {

    it('rebuilds solde_uv_avant/apres across ALL agents when a past sale amount is edited (global ledger, not per-agent)', function () {
        $agentA = ledgerAgent();
        $agentB = ledgerAgent();

        ledgerApprovisionnement(Carbon::create(2026, 6, 1, 8, 0), 10_000_000);

        $t1 = ledgerVente($agentA, Carbon::create(2026, 6, 1, 9, 0), 1_000_000);
        $t2 = ledgerVente($agentA, Carbon::create(2026, 6, 1, 10, 0), 500_000);
        ledgerRemboursement($agentA, Carbon::create(2026, 6, 1, 11, 0), 300_000);
        // Après 18:02 : journée métier suivante (2026-06-02), pour vérifier
        // que la cascade traverse aussi une frontière de jour métier.
        $t4 = ledgerVente($agentB, Carbon::create(2026, 6, 1, 19, 0), 2_000_000);

        // Clôtures de commission des deux journées AVANT l'édition, pour
        // vérifier ensuite qu'aucune ne se voit recréditée par erreur.
        $service = app(CommissionService::class);
        $service->verifierEtCorrigerCommission(Carbon::create(2026, 6, 1), 'manual');
        $service->verifierEtCorrigerCommission(Carbon::create(2026, 6, 2), 'manual');

        expect((float) $t1->fresh()->solde_uv_avant)->toBe(10_000_000.0)
            ->and((float) $t1->fresh()->solde_uv_apres)->toBe(9_000_000.0)
            ->and((float) $t2->fresh()->solde_uv_avant)->toBe(9_000_000.0)
            ->and((float) $t2->fresh()->solde_uv_apres)->toBe(8_500_000.0)
            ->and((float) $t4->fresh()->solde_uv_avant)->toBe(8_500_000.0)
            ->and((float) $t4->fresh()->solde_uv_apres)->toBe(6_500_000.0);

        // Édition : le montant de T2 passe de 500 000 à 1 500 000.
        $t2->update(['amount' => 1_500_000]);

        expect((float) $t2->fresh()->solde_uv_avant)->toBe(9_000_000.0)
            ->and((float) $t2->fresh()->solde_uv_apres)->toBe(7_500_000.0)
            // T4 appartient à un AUTRE agent : la cascade doit quand même le
            // recalculer, preuve que le grand livre est global.
            ->and((float) $t4->fresh()->solde_uv_avant)->toBe(7_500_000.0)
            ->and((float) $t4->fresh()->solde_uv_apres)->toBe(5_500_000.0)
            // T1 précède le point d'édition : inchangé.
            ->and((float) $t1->fresh()->solde_uv_avant)->toBe(10_000_000.0)
            ->and((float) $t1->fresh()->solde_uv_apres)->toBe(9_000_000.0);

        // Le Solde UV Disponible global (source de vérité indépendante)
        // reste cohérent avec la fin de la chaîne recalculée.
        expect(app(MasterSoldeService::class)->getAvailableBalance())->toBe(5_500_000.0);

        // La dette de l'agent A (recalcul SUM déjà existant, non cassé par
        // cette refonte) reflète le nouveau montant de T2.
        expect((float) $agentA->fresh()->current_debt)->toBe(2_200_000.0);

        // Aucune commission déjà clôturée n'est recréditée par la seule
        // réécriture des instantanés du grand livre (saveQuietly() sur T4,
        // qui n'appartient pas au même jour métier que l'édition de T2).
        expect(CommissionRecalculation::whereDate('date_commission', '2026-06-01')->count())->toBe(1);
        expect(CommissionRecalculation::whereDate('date_commission', '2026-06-02')->count())->toBe(1);
    });

    it('does not re-fire the "updated" model event for records only touched by the cascade rewrite (saveQuietly)', function () {
        $agentA = ledgerAgent();
        $agentB = ledgerAgent();

        ledgerApprovisionnement(Carbon::create(2026, 6, 5, 8, 0), 10_000_000);
        ledgerVente($agentA, Carbon::create(2026, 6, 5, 9, 0), 1_000_000);
        $t2 = ledgerVente($agentA, Carbon::create(2026, 6, 5, 10, 0), 500_000);
        $t4 = ledgerVente($agentB, Carbon::create(2026, 6, 5, 12, 0), 2_000_000);

        $updatedIds = [];
        Event::listen('eloquent.updated: '.UvTransaction::class, function ($model) use (&$updatedIds) {
            $updatedIds[] = $model->id;
        });

        $t2->update(['amount' => 1_500_000]);

        // T2 lui-même déclenche bien un vrai événement "updated" (l'édition
        // de l'utilisateur) ; T4, dont seuls solde_uv_avant/apres sont
        // réécrits par la cascade, ne doit PAS re-déclencher "updated".
        expect($updatedIds)->toBe([$t2->id]);
        expect((float) $t4->fresh()->solde_uv_apres)->toBe(5_500_000.0);
    });

    it('rebuilds the ledger when a past sale is soft-deleted', function () {
        $agent = ledgerAgent();

        ledgerApprovisionnement(Carbon::create(2026, 6, 10, 8, 0), 5_000_000);
        $t1 = ledgerVente($agent, Carbon::create(2026, 6, 10, 9, 0), 1_000_000);
        $t2 = ledgerVente($agent, Carbon::create(2026, 6, 10, 10, 0), 500_000);

        expect((float) $t2->fresh()->solde_uv_avant)->toBe(4_000_000.0);

        $t1->delete();

        expect($t1->fresh()->trashed())->toBeTrue()
            ->and((float) $t2->fresh()->solde_uv_avant)->toBe(5_000_000.0)
            ->and((float) $t2->fresh()->solde_uv_apres)->toBe(4_500_000.0);

        expect(app(MasterSoldeService::class)->getAvailableBalance())->toBe(4_500_000.0);
    });

    it('retroactively fixes solde snapshots when a sale is created with a backdated transaction_date after later sales already exist', function () {
        $agentA = ledgerAgent();
        $agentB = ledgerAgent();

        ledgerApprovisionnement(Carbon::create(2026, 6, 15, 8, 0), 10_000_000);

        ledgerVente($agentA, Carbon::create(2026, 6, 15, 9, 0), 1_000_000);
        $t4 = ledgerVente($agentB, Carbon::create(2026, 6, 15, 12, 0), 2_000_000);

        // Créée après T4 (ordre réel d'insertion) mais avec une date de
        // transaction antidatée à 10h, donc avant T4 (12h) déjà en base.
        $backdated = ledgerVente($agentA, Carbon::create(2026, 6, 15, 10, 0), 500_000);

        expect((float) $backdated->fresh()->solde_uv_avant)->toBe(9_000_000.0)
            ->and((float) $backdated->fresh()->solde_uv_apres)->toBe(8_500_000.0)
            ->and((float) $t4->fresh()->solde_uv_avant)->toBe(8_500_000.0)
            ->and((float) $t4->fresh()->solde_uv_apres)->toBe(6_500_000.0);

        expect(app(MasterSoldeService::class)->getAvailableBalance())->toBe(6_500_000.0);
    });

    it('provides rebuildAll() to fully repair the ledger from the earliest movement', function () {
        $agent = ledgerAgent();

        ledgerApprovisionnement(Carbon::create(2026, 6, 20, 8, 0), 5_000_000);
        $t1 = ledgerVente($agent, Carbon::create(2026, 6, 20, 9, 0), 1_000_000);

        // Corrompre volontairement l'instantané pour vérifier que
        // rebuildAll() le restaure.
        $t1->forceFill(['solde_uv_avant' => 0, 'solde_uv_apres' => 0])->saveQuietly();
        expect((float) $t1->fresh()->solde_uv_apres)->toBe(0.0);

        app(\App\Services\LedgerCascadeService::class)->rebuildAll();

        expect((float) $t1->fresh()->solde_uv_avant)->toBe(5_000_000.0)
            ->and((float) $t1->fresh()->solde_uv_apres)->toBe(4_000_000.0);
    });
});
