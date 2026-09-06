<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentSoldeTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create([
            'name' => 'Agent Test',
            'status' => 'active',
        ]);

        $this->fournisseur = Fournisseur::factory()->create([
            'name' => 'Fournisseur Test',
        ]);
    }

    /** @test */
    public function solde_is_created_automatically_when_transaction_is_created()
    {
        // Vérifier qu'il n'y a pas de solde initialement
        $this->assertDatabaseMissing('agent_soldes', [
            'agent_id' => $this->agent->id,
        ]);

        // Créer une transaction
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 100000,
            'reference' => 'TEST-001',
        ]);

        // Vérifier que le solde a été créé automatiquement
        $this->assertDatabaseHas('agent_soldes', [
            'agent_id' => $this->agent->id,
        ]);

        $solde = AgentSolde::where('agent_id', $this->agent->id)->first();
        $this->assertEquals(0, (float) $solde->total_rembourse);
        $this->assertEquals(100000, (float) $solde->solde_restant); // Montant FCFA
    }

    /** @test */
    public function solde_is_updated_when_remboursement_is_created()
    {
        // Créer une transaction initiale
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 100000,
            'reference' => 'TEST-001',
        ]);

        // Créer un remboursement
        Remboursement::create([
            'agent_id' => $this->agent->id,
            'amount' => 50000,
            'payment_method' => 'cash',
            'reference' => 'REMB-001',
        ]);

        // Vérifier que le solde a été mis à jour
        $solde = AgentSolde::where('agent_id', $this->agent->id)->first();
        $this->assertEquals(50000, (float) $solde->total_rembourse);
        $this->assertEquals(50000, (float) $solde->solde_restant); // Montant FCFA
    }

    /** @test */
    public function solde_handles_multiple_transactions_correctly()
    {
        // Transaction 1
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 100000,
            'reference' => 'TEST-001',
        ]);

        // Transaction 2
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 50000,
            'reference' => 'TEST-002',
        ]);

        // Transaction 3 (retour)
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'retour',
            'unit_price' => 1000,
            'amount' => 20000,
            'reference' => 'TEST-003',
        ]);

        $solde = AgentSolde::where('agent_id', $this->agent->id)->first();

        // Montant: 150,000 - 20,000 = 130,000 FCFA
        $this->assertEquals(130000, (float) $solde->solde_restant); // Montant FCFA
    }

    /** @test */
    public function solde_recalculate_method_works_correctly()
    {
        // Créer des transactions
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 100000,
            'reference' => 'TEST-001',
        ]);

        // Modifier manuellement le solde (simulation d'une incohérence)
        $solde = AgentSolde::where('agent_id', $this->agent->id)->first();
        $solde->update(['solde_restant' => 999999]);

        // Vérifier l'incohérence
        $this->assertEquals(999999, $solde->fresh()->solde_restant);

        // Recalculer
        $newSolde = AgentSolde::recalculate($this->agent);

        // Vérifier la correction
        $this->assertEquals(100000, (float) $newSolde->solde_restant);
    }

    /** @test */
    public function solde_with_zero_debt_shows_no_debt()
    {
        // Créer une transaction
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 100000,
            'reference' => 'TEST-001',
        ]);

        // Rembourser totalement
        Remboursement::create([
            'agent_id' => $this->agent->id,
            'amount' => 100000,
            'payment_method' => 'cash',
            'reference' => 'REMB-001',
        ]);

        $solde = AgentSolde::where('agent_id', $this->agent->id)->first();
        $this->assertEquals(0, (float) $solde->solde_restant);
    }

    /** @test */
    public function solde_handles_multiple_remboursements()
    {
        // Créer une transaction
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 100000,
            'reference' => 'TEST-001',
        ]);

        // Remboursement 1
        Remboursement::create([
            'agent_id' => $this->agent->id,
            'amount' => 30000,
            'payment_method' => 'cash',
            'reference' => 'REMB-001',
        ]);

        // Remboursement 2
        Remboursement::create([
            'agent_id' => $this->agent->id,
            'amount' => 20000,
            'payment_method' => 'mobile_money',
            'reference' => 'REMB-002',
        ]);

        // Remboursement 3
        Remboursement::create([
            'agent_id' => $this->agent->id,
            'amount' => 10000,
            'payment_method' => 'bank_transfer',
            'reference' => 'REMB-003',
        ]);

        $solde = AgentSolde::where('agent_id', $this->agent->id)->first();
        $this->assertEquals(60000, (float) $solde->total_rembourse);
        $this->assertEquals(40000, (float) $solde->solde_restant); // Montant FCFA
    }

    /** @test */
    public function solde_calculation_is_accurate_with_complex_scenario()
    {
        // Distribution 1: 200,000 FCFA
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 200000,
            'reference' => 'TEST-001',
        ]);

        // Remboursement 1: 50,000 FCFA
        Remboursement::create([
            'agent_id' => $this->agent->id,
            'amount' => 50000,
            'payment_method' => 'cash',
            'reference' => 'REMB-001',
        ]);

        // Distribution 2: 150,000 FCFA
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'distribution',
            'unit_price' => 1000,
            'amount' => 150000,
            'reference' => 'TEST-002',
        ]);

        // Retour: 30,000 FCFA
        UvTransaction::create([
            'agent_id' => $this->agent->id,
            'fournisseur_id' => $this->fournisseur->id,
            'transaction_type' => 'retour',
            'unit_price' => 1000,
            'amount' => 30000,
            'reference' => 'TEST-003',
        ]);

        // Remboursement 2: 100,000 FCFA
        Remboursement::create([
            'agent_id' => $this->agent->id,
            'amount' => 100000,
            'payment_method' => 'mobile_money',
            'reference' => 'REMB-002',
        ]);

        $solde = AgentSolde::where('agent_id', $this->agent->id)->first();

        // Calcul attendu:
        // Total remboursé: 150,000 FCFA
        // Montant distribué: 350,000 FCFA
        // Montant retourné: 30,000 FCFA
        // Solde restant: 350,000 - 30,000 - 150,000 = 170,000 FCFA

        $this->assertEquals(150000, (float) $solde->total_rembourse);
        $this->assertEquals(170000, (float) $solde->solde_restant); // Montant FCFA
    }

    /** @test */
    public function each_agent_has_only_one_solde_record()
    {
        // Créer plusieurs transactions
        for ($i = 1; $i <= 5; $i++) {
            UvTransaction::create([
                'agent_id' => $this->agent->id,
                'fournisseur_id' => $this->fournisseur->id,
                'transaction_type' => 'distribution',
                'unit_price' => 1000,
                'amount' => 10000,
                'reference' => "TEST-00{$i}",
            ]);
        }

        // Vérifier qu'il n'y a qu'un seul solde
        $soldeCount = AgentSolde::where('agent_id', $this->agent->id)->count();
        $this->assertEquals(1, $soldeCount);
    }
}
