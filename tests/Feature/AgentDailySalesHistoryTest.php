<?php

namespace Tests\Feature;

use App\Filament\Resources\UvTransactionResource\Pages\CreateUvTransaction;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgentDailySalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Agent $agentA;

    protected Agent $agentB;

    protected Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        // Ancre l'horloge en milieu de journée : évite tout flakiness des
        // tests qui reculent l'horloge de quelques minutes (now()->subMinutes)
        // si la suite tourne réellement tout près de minuit.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['view_uv_transactions', 'create_uv_transactions'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_uv_transactions', 'create_uv_transactions']);

        $this->user = User::factory()->create();
        $this->user->assignRole('master');
        $this->actingAs($this->user);

        $this->agentA = Agent::factory()->create(['name' => 'Agent A', 'status' => 'active', 'debt_limit' => 0]);
        $this->agentB = Agent::factory()->create(['name' => 'Agent B', 'status' => 'active', 'debt_limit' => 0]);
        $this->fournisseur = Fournisseur::factory()->create();

        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => $this->fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);
    }

    protected function vendre(Agent $agent, float $amount, ?Carbon $at = null): UvTransaction
    {
        $transaction = app(UvDistributionService::class)->distribute($agent, $amount, $this->fournisseur);

        if ($at) {
            $transaction->forceFill(['transaction_date' => $at])->saveQuietly();
        }

        return $transaction;
    }

    /** 1. Aucun agent sélectionné */
    public function test_no_agent_selected_shows_neutral_state(): void
    {
        $component = Livewire::test(CreateUvTransaction::class);

        $history = $component->instance()->getAgentSalesHistory();

        $this->assertNull($history['agentId']);
        $component->assertSee('Sélectionnez un agent pour afficher ses ventes UV du jour.');
    }

    /** 2. Agent sans transaction aujourd'hui */
    public function test_agent_without_sales_today_shows_empty_state(): void
    {
        $component = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id);

        $component->assertSee('Aucune Vente UV enregistrée aujourd\'hui pour cet agent.', false);

        $history = $component->instance()->getAgentSalesHistory();
        $this->assertSame(0, $history['total']);
        $this->assertSame(0.0, $history['totalAmount']);
    }

    /** 3. Agent avec une seule vente UV */
    public function test_agent_with_a_single_sale(): void
    {
        $this->vendre($this->agentA, 250_000);

        $history = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()
            ->getAgentSalesHistory();

        $this->assertSame(1, $history['total']);
        $this->assertEqualsWithDelta(250_000, $history['totalAmount'], 0.01);
    }

    /** 4. Agent avec plusieurs ventes UV */
    public function test_agent_with_multiple_sales_ordered_most_recent_first(): void
    {
        $this->vendre($this->agentA, 100_000, now()->subMinutes(30));
        $this->vendre($this->agentA, 200_000, now()->subMinutes(10));
        $latest = $this->vendre($this->agentA, 300_000, now());

        $history = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()
            ->getAgentSalesHistory();

        $this->assertSame(3, $history['total']);
        $this->assertEqualsWithDelta(600_000, $history['totalAmount'], 0.01);
        $this->assertSame($latest->id, $history['items'][0]->id);
    }

    /** 5. Agent avec plus de 10 transactions + 13. pagination */
    public function test_agent_with_more_than_ten_transactions_is_paginated(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->vendre($this->agentA, 10_000, now()->subMinutes(13 - $i));
        }

        $component = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id);

        $history = $component->instance()->getAgentSalesHistory();
        $this->assertSame(13, $history['total']);
        $this->assertCount(10, $history['items']);
        $this->assertSame(2, $history['totalPages']);
        $this->assertSame(1, $history['page']);

        $component->call('nextHistoryPage');
        $history = $component->instance()->getAgentSalesHistory();
        $this->assertSame(2, $history['page']);
        $this->assertCount(3, $history['items']);

        $component->call('previousHistoryPage');
        $history = $component->instance()->getAgentSalesHistory();
        $this->assertSame(1, $history['page']);
    }

    /** 6. Changement de Agent A vers Agent B */
    public function test_switching_agent_replaces_history_and_resets_page(): void
    {
        $this->vendre($this->agentA, 500_000);
        $this->vendre($this->agentB, 750_000);

        $component = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id);

        $historyA = $component->instance()->getAgentSalesHistory();
        $this->assertSame(1, $historyA['total']);
        $this->assertEqualsWithDelta(500_000, $historyA['totalAmount'], 0.01);

        $component->set('data.agent_id', $this->agentB->id);
        $historyB = $component->instance()->getAgentSalesHistory();

        $this->assertSame(1, $historyB['total']);
        $this->assertEqualsWithDelta(750_000, $historyB['totalAmount'], 0.01);
        $this->assertSame($this->agentB->id, $historyB['items'][0]->agent_id);
    }

    /** 7 + 8. Création d'une nouvelle vente => historique et totaux mis à jour immédiatement */
    public function test_creating_a_sale_immediately_refreshes_history_and_totals(): void
    {
        $component = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->set('data.amount', 400_000)
            ->set('data.transaction_type', 'distribution')
            ->set('data.transaction_date', now());

        $component->call('create');

        $history = $component->instance()->getAgentSalesHistory();

        $this->assertSame(1, $history['total']);
        $this->assertEqualsWithDelta(400_000, $history['totalAmount'], 0.01);
        // L'agent reste sélectionné après la création (create() reste sur la page).
        $this->assertSame($this->agentA->id, $component->get('data.agent_id'));
    }

    /** 9. Transactions d'hier non affichées */
    public function test_yesterday_sales_are_excluded(): void
    {
        $this->vendre($this->agentA, 999_000, now()->subDay());

        $history = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()
            ->getAgentSalesHistory();

        $this->assertSame(0, $history['total']);
    }

    /** 10. Approvisionnements non affichés */
    public function test_approvisionnements_are_never_listed_as_sales(): void
    {
        // Un approvisionnement supplémentaire aujourd'hui, sans aucune vente.
        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => $this->fournisseur->id,
            'amount' => 1_000_000,
            'date_approvisionnement' => now(),
        ]);

        $history = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()
            ->getAgentSalesHistory();

        $this->assertSame(0, $history['total']);
    }

    /** 11. Remboursements non affichés */
    public function test_remboursements_are_never_listed_as_sales(): void
    {
        Remboursement::factory()->create([
            'agent_id' => $this->agentA->id,
            'user_id' => $this->user->id,
            'amount' => 50_000,
            'payment_date' => now(),
            'status' => 'completed',
        ]);

        $history = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()
            ->getAgentSalesHistory();

        $this->assertSame(0, $history['total']);
    }

    /** 12. Vente UV d'un autre agent non affichée */
    public function test_other_agents_sales_are_not_shown(): void
    {
        $this->vendre($this->agentB, 321_000);

        $history = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()
            ->getAgentSalesHistory();

        $this->assertSame(0, $history['total']);
    }

    /** 14. Erreur API : ne casse pas le formulaire */
    public function test_history_failure_does_not_break_the_page(): void
    {
        $component = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', 999999); // agent inexistant, mais un id est bien fourni

        // Ne doit jamais lever d'exception ni renvoyer une page cassée.
        $history = $component->instance()->getAgentSalesHistory();
        $this->assertSame(0, $history['total']);
        $component->assertOk();
    }

    /** 15. Transaction proche de minuit : reste rattachée au bon jour (fuseau de l'app) */
    public function test_transaction_near_midnight_is_attributed_to_the_correct_day(): void
    {
        $this->vendre($this->agentA, 111_000, Carbon::yesterday()->endOfDay()->subSecond());
        $todaySale = $this->vendre($this->agentA, 222_000, Carbon::today()->startOfDay()->addSecond());

        $history = Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()
            ->getAgentSalesHistory();

        $this->assertSame(1, $history['total']);
        $this->assertSame($todaySale->id, $history['items'][0]->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
