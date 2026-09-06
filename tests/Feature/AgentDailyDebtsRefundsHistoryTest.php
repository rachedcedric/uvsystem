<?php

namespace Tests\Feature;

use App\Filament\Resources\RemboursementResource\Pages\CreateRemboursement;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgentDailyDebtsRefundsHistoryTest extends TestCase
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
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-06-15 12:00:00'));

        foreach (['view_uv_transactions', 'create_uv_transactions', 'view_remboursements', 'create_remboursements'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_uv_transactions', 'create_uv_transactions', 'view_remboursements', 'create_remboursements']);

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

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    protected function creerDette(Agent $agent, float $amount, ?\Carbon\Carbon $at = null): void
    {
        $t = app(UvDistributionService::class)->distribute($agent, $amount, $this->fournisseur);
        if ($at) {
            $t->forceFill(['transaction_date' => $at])->saveQuietly();
        }
        $agent->recalculateDebt();
    }

    protected function rembourser(Agent $agent, float $amount, ?\Carbon\Carbon $at = null): void
    {
        $r = app(RemboursementService::class)->process($agent, $amount);
        if ($at) {
            $r->forceFill(['payment_date' => $at])->saveQuietly();
        }
        $agent->recalculateDebt();
    }

    /** 1. Aucun agent sélectionné */
    public function test_no_agent_selected(): void
    {
        $component = Livewire::test(CreateRemboursement::class);
        $history = $component->instance()->getAgentDailyHistory();

        $this->assertNull($history['agentId']);
        $component->assertSee('Sélectionnez un agent pour afficher son historique du jour.');
    }

    /** 2. Agent sans transaction aujourd'hui */
    public function test_agent_without_transactions_today(): void
    {
        $component = Livewire::test(CreateRemboursement::class)->set('data.agent_id', $this->agentA->id);
        $component->assertSee("Aucune opération de remboursement ou de dette enregistrée aujourd'hui pour cet agent.", false);

        $history = $component->instance()->getAgentDailyHistory();
        $this->assertSame(0, $history['total']);
    }

    /** 3. Agent avec une dette */
    public function test_agent_with_one_debt(): void
    {
        $this->creerDette($this->agentA, 150_000);

        $history = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()->getAgentDailyHistory();

        $this->assertSame(1, $history['total']);
        $this->assertEqualsWithDelta(150_000, $history['summary']['totalDebtsCreated'], 0.01);
        $this->assertEqualsWithDelta(150_000, $history['summary']['remainingDebt'], 0.01);
        $this->assertSame('dette', $history['items'][0]['type']);
        $this->assertEqualsWithDelta(150_000, $history['items'][0]['resteDuApres'], 0.01);
        $this->assertSame('en_cours', $history['items'][0]['detteStatus']);
    }

    /** 4. Agent avec plusieurs dettes */
    public function test_agent_with_multiple_debts(): void
    {
        $this->creerDette($this->agentA, 100_000, now()->subMinutes(20));
        $this->creerDette($this->agentA, 50_000, now()->subMinutes(5));

        $history = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()->getAgentDailyHistory();

        $this->assertSame(2, $history['total']);
        $this->assertEqualsWithDelta(150_000, $history['summary']['totalDebtsCreated'], 0.01);
        // La plus récente en premier.
        $this->assertEqualsWithDelta(50_000, (float) $history['items'][0]['model']->amount, 0.01);
    }

    /** 5. Agent avec remboursement */
    public function test_agent_with_a_refund(): void
    {
        $this->creerDette($this->agentA, 150_000);
        \Carbon\Carbon::setTestNow(now()->addMinutes(5));
        $this->rembourser($this->agentA, 50_000);

        $history = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()->getAgentDailyHistory();

        $this->assertSame(2, $history['total']);
        $this->assertEqualsWithDelta(50_000, $history['summary']['totalRefunds'], 0.01);
        $this->assertEqualsWithDelta(100_000, $history['summary']['remainingDebt'], 0.01);
        $this->assertSame('remboursement', $history['items'][0]['type']);
        $this->assertEqualsWithDelta(100_000, $history['items'][0]['resteDuApres'], 0.01);
    }

    /** 6. Remboursement partiel + 7. dette soldée après remboursement */
    public function test_partial_then_full_refund_marks_debt_as_soldee(): void
    {
        $this->creerDette($this->agentA, 150_000);
        \Carbon\Carbon::setTestNow(now()->addMinutes(5));
        $this->rembourser($this->agentA, 50_000); // partiel
        \Carbon\Carbon::setTestNow(now()->addMinutes(5));
        $this->rembourser($this->agentA, 100_000); // solde le reste

        $history = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()->getAgentDailyHistory();

        $this->assertSame(3, $history['total']);
        $this->assertEqualsWithDelta(0, $history['summary']['remainingDebt'], 0.01);

        // Dernier remboursement en tête, reste dû = 0.
        $this->assertEqualsWithDelta(0, $history['items'][0]['resteDuApres'], 0.01);

        // La ligne "dette" doit maintenant apparaître comme soldée
        // (recalculée d'après l'état réel après toutes les opérations).
        $detteEntry = collect($history['items'])->firstWhere('type', 'dette');
        $this->assertSame('soldee', $detteEntry['detteStatus']);
    }

    /** 8. Changement rapide entre deux agents */
    public function test_switching_between_agents_replaces_history(): void
    {
        $this->creerDette($this->agentA, 200_000);
        $this->creerDette($this->agentB, 75_000);

        $component = Livewire::test(CreateRemboursement::class)->set('data.agent_id', $this->agentA->id);
        $historyA = $component->instance()->getAgentDailyHistory();
        $this->assertEqualsWithDelta(200_000, $historyA['summary']['remainingDebt'], 0.01);

        $component->set('data.agent_id', $this->agentB->id);
        $historyB = $component->instance()->getAgentDailyHistory();
        $this->assertEqualsWithDelta(75_000, $historyB['summary']['remainingDebt'], 0.01);
        $this->assertSame($this->agentB->id, $historyB['items'][0]['model']->agent_id);
    }

    /** 9 + 10. Nouvelle transaction => historique et totaux mis à jour sans reload */
    public function test_creating_a_remboursement_refreshes_history_immediately(): void
    {
        $this->creerDette($this->agentA, 300_000);

        $component = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->set('data.amount', 120_000)
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now());

        $component->call('create');

        $history = $component->instance()->getAgentDailyHistory();
        $this->assertSame(2, $history['total']);
        $this->assertEqualsWithDelta(120_000, $history['summary']['totalRefunds'], 0.01);
        $this->assertEqualsWithDelta(180_000, $history['summary']['remainingDebt'], 0.01);
        $this->assertSame($this->agentA->id, $component->get('data.agent_id'));
    }

    /** 11. Transactions proches de minuit */
    public function test_transaction_near_midnight(): void
    {
        $this->creerDette($this->agentA, 10_000, \Carbon\Carbon::yesterday()->endOfDay()->subSecond());
        $this->creerDette($this->agentA, 20_000, \Carbon\Carbon::today()->startOfDay()->addSecond());

        $history = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()->getAgentDailyHistory();

        $this->assertSame(1, $history['total']);
        $this->assertEqualsWithDelta(20_000, (float) $history['items'][0]['model']->amount, 0.01);
    }

    /** 12. Aucun doublon (une même opération n'apparaît qu'une fois) */
    public function test_no_duplicate_entries(): void
    {
        $this->creerDette($this->agentA, 100_000);
        $this->rembourser($this->agentA, 40_000);

        $history = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()->getAgentDailyHistory();

        $ids = collect($history['items'])->map(fn ($e) => $e['type'].'-'.$e['model']->id);
        $this->assertSame($ids->count(), $ids->unique()->count());
    }

    /** 13. Erreur API : ne casse pas la page (agent inexistant) */
    public function test_nonexistent_agent_does_not_break_page(): void
    {
        $component = Livewire::test(CreateRemboursement::class)->set('data.agent_id', 999999);

        $history = $component->instance()->getAgentDailyHistory();
        $this->assertSame(0, $history['total']);
        $component->assertOk();
    }

    /** Un remboursement (dette FCFA) n'apparaît jamais comme une vente d'un autre agent */
    public function test_other_agent_operations_are_not_shown(): void
    {
        $this->creerDette($this->agentB, 500_000);
        $this->rembourser($this->agentB, 100_000);

        $history = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agentA->id)
            ->instance()->getAgentDailyHistory();

        $this->assertSame(0, $history['total']);
    }
}
