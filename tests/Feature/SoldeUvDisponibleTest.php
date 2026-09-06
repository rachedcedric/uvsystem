<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SoldeUvDisponibleTest extends TestCase
{
    use RefreshDatabase;

    protected UvDistributionService $service;

    protected MasterSoldeService $masterSoldeService;

    protected Agent $agent;

    protected Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(UvDistributionService::class);
        $this->masterSoldeService = app(MasterSoldeService::class);
        $this->agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $this->fournisseur = Fournisseur::factory()->create();
        $this->actingAs(User::factory()->create());
    }

    protected function approvisionner(float $amount): void
    {
        \App\Models\Approvisionnement::create([
            'user_id' => auth()->id(),
            'fournisseur_id' => $this->fournisseur->id,
            'amount' => $amount,
            'date_approvisionnement' => now(),
        ]);
    }

    public function test_scenario_from_the_spec(): void
    {
        // Total approvisionné : 10 000 000 FCFA, total vendu : 3 500 000 FCFA
        $this->approvisionner(10_000_000);
        $this->service->distribute($this->agent, 3_500_000, $this->fournisseur);

        $this->assertEqualsWithDelta(6_500_000, $this->masterSoldeService->getAvailableBalance(), 0.01);

        // Nouvelle vente de 500 000 FCFA
        $this->service->distribute($this->agent, 500_000, $this->fournisseur);

        $this->assertEqualsWithDelta(6_000_000, $this->masterSoldeService->getAvailableBalance(), 0.01);
    }

    public function test_approvisionnement_increases_balance_immediately(): void
    {
        $this->approvisionner(6_000_000);
        $this->assertEqualsWithDelta(6_000_000, $this->masterSoldeService->getAvailableBalance(), 0.01);

        $this->approvisionner(2_000_000);
        $this->assertEqualsWithDelta(8_000_000, $this->masterSoldeService->getAvailableBalance(), 0.01);
    }

    public function test_vente_decreases_balance_immediately(): void
    {
        $this->approvisionner(8_000_000);
        $this->service->distribute($this->agent, 750_000, $this->fournisseur);

        $this->assertEqualsWithDelta(7_250_000, $this->masterSoldeService->getAvailableBalance(), 0.01);
    }

    public function test_vente_exceeding_balance_is_blocked(): void
    {
        $this->approvisionner(1_000_000);

        $this->expectException(ValidationException::class);

        try {
            $this->service->distribute($this->agent, 1_500_000, $this->fournisseur);
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Solde UV insuffisant pour effectuer cette vente.',
                collect($e->errors())->flatten()->implode(' ')
            );

            // Le solde ne doit pas avoir bougé : rien n'a été débité.
            $this->assertEqualsWithDelta(1_000_000, $this->masterSoldeService->getAvailableBalance(), 0.01);

            throw $e;
        }
    }

    public function test_balance_never_goes_negative(): void
    {
        $this->approvisionner(100_000);

        try {
            $this->service->distribute($this->agent, 200_000, $this->fournisseur);
        } catch (ValidationException) {
            // attendu
        }

        $this->assertGreaterThanOrEqual(0, $this->masterSoldeService->getAvailableBalance());
    }

    public function test_cancel_with_uv_restitution_recredits_balance(): void
    {
        $this->approvisionner(5_000_000);
        $transaction = $this->service->distribute($this->agent, 1_200_000, $this->fournisseur);

        $this->assertEqualsWithDelta(3_800_000, $this->masterSoldeService->getAvailableBalance(), 0.01);

        $this->service->cancel($transaction);

        $this->assertEqualsWithDelta(5_000_000, $this->masterSoldeService->getAvailableBalance(), 0.01);
        $this->assertSame('cancelled', $transaction->fresh()->status);
    }

    public function test_financial_remboursement_never_affects_uv_balance(): void
    {
        $this->approvisionner(2_000_000);
        $this->service->distribute($this->agent, 500_000, $this->fournisseur);

        $before = $this->masterSoldeService->getAvailableBalance();

        Remboursement::factory()->create([
            'agent_id' => $this->agent->id,
            'user_id' => auth()->id(),
            'amount' => 200_000,
            'payment_date' => now(),
            'status' => 'completed',
        ]);

        $this->assertEqualsWithDelta($before, $this->masterSoldeService->getAvailableBalance(), 0.01);
    }

    public function test_transaction_records_before_and_after_balance(): void
    {
        $this->approvisionner(4_000_000);
        $transaction = $this->service->distribute($this->agent, 900_000, $this->fournisseur);

        $this->assertEqualsWithDelta(4_000_000, (float) $transaction->solde_uv_avant, 0.01);
        $this->assertEqualsWithDelta(3_100_000, (float) $transaction->solde_uv_apres, 0.01);
    }

    public function test_cached_snapshot_stays_consistent_with_real_history(): void
    {
        $this->approvisionner(3_000_000);
        $this->service->distribute($this->agent, 1_000_000, $this->fournisseur);
        $this->service->processReturn($this->agent, 200_000);

        $totals = $this->masterSoldeService->getGlobalTotals();

        $this->assertEqualsWithDelta(
            $totals['total_approvisionne'] - $totals['total_distribue'] + $totals['total_retourne'],
            $totals['solde_disponible'],
            0.01
        );

        // La ligne persistée (legacy) doit refléter la même vérité.
        $solde = \App\Models\MasterSolde::first();
        $this->assertEqualsWithDelta($totals['solde_disponible'], (float) $solde->solde_disponible, 0.01);
    }
}
