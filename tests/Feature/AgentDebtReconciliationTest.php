<?php

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Regression coverage for the pre-production audit's 3 critical findings
|--------------------------------------------------------------------------
|
| 1. Cancelling a sale never reduced the agent's debt (missing status
|    filter on Agent::recalculateDebt() / the observers).
| 2. Cancelled sales stayed counted in commission volume.
| 3. AgentSolde::recalculate() used a different, wrong formula than
|    Agent::recalculateDebt() (added retours instead of subtracting them).
|
| All three shared one root cause: several places re-summed
| distribution/retour transactions independently instead of going through
| one shared source of truth. These tests lock in the fix and the
| invariant that Agent::current_debt and AgentSolde::solde_restant must
| always agree.
*/

uses(RefreshDatabase::class);

function reconciliationService(): UvDistributionService
{
    return new UvDistributionService;
}

function reconciliationAgent(): Agent
{
    $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

    AgentSolde::create([
        'agent_id' => $agent->id,
        'total_uv_distribues' => 0,
        'total_rembourse' => 0,
        'solde_restant' => 0,
    ]);

    return $agent;
}

function reconciliationApprovisionner(float $amount): void
{
    Approvisionnement::create([
        'user_id' => User::factory()->create()->id,
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'amount' => $amount,
        'date_approvisionnement' => now(),
    ]);
}

describe('Cancelling a sale correctly reduces the agent debt', function () {

    it('brings current_debt back down after a cancellation, not stuck at the pre-cancel value', function () {
        $agent = reconciliationAgent();
        reconciliationApprovisionner(200000);

        $vente = reconciliationService()->distribute(agent: $agent, amount: 100000);
        expect((float) $agent->fresh()->current_debt)->toBe(100000.0);

        reconciliationService()->cancel($vente);

        expect((float) $agent->fresh()->current_debt)->toBe(0.0);
    });

    it('keeps AgentSolde::solde_restant in agreement with Agent::current_debt after a cancellation', function () {
        $agent = reconciliationAgent();
        reconciliationApprovisionner(200000);

        $vente = reconciliationService()->distribute(agent: $agent, amount: 100000);
        reconciliationService()->cancel($vente);

        expect((float) $agent->fresh()->current_debt)->toBe(0.0)
            ->and((float) $agent->fresh()->solde->solde_restant)->toBe(0.0);
    });

    it('does not block a legitimate new sale because a cancelled one is still counted', function () {
        $agent = reconciliationAgent();
        $agent->update(['debt_limit' => 100000]);
        reconciliationApprovisionner(300000);

        $vente = reconciliationService()->distribute(agent: $agent, amount: 100000);
        reconciliationService()->cancel($vente);

        // Plafond de 100 000 : si la vente annulée comptait encore, ceci échouerait.
        $nouvelleVente = reconciliationService()->distribute(agent: $agent->fresh(), amount: 100000);

        expect($nouvelleVente)->not->toBeNull()
            ->and((float) $agent->fresh()->current_debt)->toBe(100000.0);
    });
});

describe('AgentSolde::recalculate() agrees with Agent::recalculateDebt()', function () {

    it('produces the exact same debt figure as the agent itself, including a retour', function () {
        $agent = reconciliationAgent();
        reconciliationApprovisionner(500000);

        reconciliationService()->distribute(agent: $agent, amount: 300000);
        reconciliationService()->processReturn(agent: $agent, amount: 50000);

        $agent->recalculateDebt();
        $solde = AgentSolde::recalculate($agent->fresh());

        // Distribué 300 000 - retourné 50 000 - remboursé 0 = 250 000.
        expect((float) $agent->fresh()->current_debt)->toBe(250000.0)
            ->and((float) $solde->solde_restant)->toBe(250000.0);
    });

    it('excludes a cancelled sale from both current_debt and solde_restant identically', function () {
        $agent = reconciliationAgent();
        reconciliationApprovisionner(500000);

        reconciliationService()->distribute(agent: $agent, amount: 200000);
        $venteAnnulee = reconciliationService()->distribute(agent: $agent->fresh(), amount: 150000);
        reconciliationService()->cancel($venteAnnulee);

        $agent->recalculateDebt();
        $solde = AgentSolde::recalculate($agent->fresh());

        expect((float) $agent->fresh()->current_debt)->toBe(200000.0)
            ->and((float) $solde->solde_restant)->toBe(200000.0);
    });
});

describe('Commission volume excludes cancelled sales', function () {

    it('does not count a cancelled sale toward the day\'s commission volume', function () {
        // Barème par défaut inséré par la migration create_paliers_commission_table
        // (réutilisé tel quel, jamais réinventé) : 20 000-599 999 -> 1 800 FCFA ;
        // 5 000 000-9 999 999 -> 31 915 FCFA.
        $agent = reconciliationAgent();
        reconciliationApprovisionner(7000000);

        $jour = Carbon::create(2026, 6, 1);
        Carbon::setTestNow($jour->copy()->setTime(10, 0));
        reconciliationService()->distribute(agent: $agent, amount: 150000);
        $venteAnnulee = reconciliationService()->distribute(agent: $agent->fresh(), amount: 6000000);
        reconciliationService()->cancel($venteAnnulee);
        Carbon::setTestNow();

        $resultat = app(CommissionService::class)->verifierEtCorrigerCommission($jour, 'manual');

        // Si la vente annulée (6 000 000) comptait encore, le volume total
        // franchirait la tranche 5M-9.999M (31 915) au lieu de rester sur
        // la tranche 20k-599k (1 800) pour les 150 000 restants.
        expect($resultat['volume_total'])->toBe(150000.0)
            ->and($resultat['commission_globale'])->toBe(1800.0);
    });
});
