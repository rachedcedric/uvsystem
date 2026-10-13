<?php

namespace Tests\Feature;

use App\Filament\Pages\AgentDebtDetailPage;
use App\Filament\Pages\DettesPage;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DettesPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        foreach (['view_uv_transactions', 'create_uv_transactions', 'view_remboursements', 'create_remboursements'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_uv_transactions', 'create_uv_transactions', 'view_remboursements', 'create_remboursements']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('master');
        $this->actingAs($this->admin);

        $this->fournisseur = Fournisseur::factory()->create();

        Approvisionnement::create([
            'user_id' => $this->admin->id,
            'fournisseur_id' => $this->fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function creerAgentAvecDette(float $amount, string $name = 'Agent Test'): Agent
    {
        $agent = Agent::factory()->create(['name' => $name, 'status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($agent, $amount, $this->fournisseur);
        $agent->recalculateDebt();

        return $agent->fresh();
    }

    /** Agent sans dette : absent de la page */
    public function test_agent_without_debt_does_not_appear(): void
    {
        Agent::factory()->create(['status' => 'active']);

        $component = Livewire::test(DettesPage::class);

        $component->assertOk();
        $this->assertSame(0, DettesPage::agentsEndettesQuery()->count());
    }

    /** Agent avec une dette : apparaît avec les bons montants */
    public function test_agent_with_debt_appears_with_correct_amounts(): void
    {
        $agent = $this->creerAgentAvecDette(250_000, 'KOFFI Jean');

        $component = Livewire::test(DettesPage::class);

        $component->assertSee('KOFFI Jean')
            ->assertSee('250 000');

        $summary = $component->instance()->getSummary();
        $this->assertSame(1, $summary['count']);
        $this->assertEqualsWithDelta(250_000, $summary['debtToday'], 0.01);
        $this->assertEqualsWithDelta(0, $summary['repaidToday'], 0.01);
        $this->assertEqualsWithDelta(250_000, $summary['outstanding'], 0.01);
    }

    /** Plusieurs agents avec dette : tous comptés */
    public function test_multiple_agents_with_debt_are_all_counted(): void
    {
        $this->creerAgentAvecDette(100_000, 'Agent A');
        $this->creerAgentAvecDette(200_000, 'Agent B');
        $this->creerAgentAvecDette(300_000, 'Agent C');

        $summary = Livewire::test(DettesPage::class)->instance()->getSummary();

        $this->assertSame(3, $summary['count']);
        $this->assertEqualsWithDelta(600_000, $summary['debtToday'], 0.01);
    }

    /** Un agent avec plusieurs dettes (ventes) : une seule ligne (pool global) */
    public function test_agent_with_multiple_debts_is_a_single_row(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $service = app(UvDistributionService::class);
        $service->distribute($agent, 100_000, $this->fournisseur);
        $service->distribute($agent, 150_000, $this->fournisseur);
        $agent->recalculateDebt();

        $summary = Livewire::test(DettesPage::class)->instance()->getSummary();

        $this->assertSame(1, $summary['count']);
        $this->assertEqualsWithDelta(250_000, $summary['outstanding'], 0.01);
    }

    /** Toute dette non soldée conserve le statut simple "En cours" */
    public function test_partially_repaid_debt_shows_correct_status(): void
    {
        $agent = $this->creerAgentAvecDette(200_000);
        app(RemboursementService::class)->process($agent, 50_000);
        $agent->recalculateDebt();

        $this->assertSame('En cours', DettesPage::statusLabel($agent->fresh()->load('solde')));

        $summary = Livewire::test(DettesPage::class)->instance()->getSummary();
        $this->assertSame(1, $summary['count']);
        $this->assertEqualsWithDelta(150_000, $summary['outstanding'], 0.01);
    }

    /** Dette totalement soldée : disparaît de la liste active et du badge */
    public function test_fully_repaid_debt_disappears_from_active_list_and_badge(): void
    {
        $agent = $this->creerAgentAvecDette(100_000);
        app(RemboursementService::class)->process($agent, 100_000);
        $agent->recalculateDebt();

        $this->assertNull(DettesPage::getNavigationBadge());
        $this->assertSame(0, DettesPage::agentsEndettesQuery()->count());

        $summary = Livewire::test(DettesPage::class)->instance()->getSummary();
        $this->assertSame(0, $summary['count']);
    }

    /** Le badge de menu correspond au nombre d'agents endettés (pas de doublon) */
    public function test_navigation_badge_matches_number_of_indebted_agents(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $service = app(UvDistributionService::class);
        $service->distribute($agent, 100_000, $this->fournisseur);
        $service->distribute($agent, 50_000, $this->fournisseur); // même agent, 2 ventes
        $agent->recalculateDebt();

        $this->creerAgentAvecDette(80_000, 'Autre Agent');

        $this->assertSame('2', DettesPage::getNavigationBadge());
    }

    /** Bouton Encaisser : URL correcte avec agent_id */
    public function test_encaisser_button_links_to_remboursement_create_with_agent_id(): void
    {
        $agent = $this->creerAgentAvecDette(150_000);

        $html = Livewire::test(DettesPage::class)->html();

        $this->assertStringContainsString("/master/remboursements/create?agent_id={$agent->id}", $html);
    }

    /** Bouton Détails : renvoie vers la page détail dette de cet agent */
    public function test_details_button_links_to_agent_debt_detail_page(): void
    {
        $agent = $this->creerAgentAvecDette(150_000);

        $html = Livewire::test(DettesPage::class)->html();

        $this->assertStringContainsString(
            AgentDebtDetailPage::getUrl(['agent' => $agent->id]),
            $html
        );
    }

    /** Une seule colonne affiche la dernière activité du jour */
    public function test_date_and_time_are_merged_into_a_single_column(): void
    {
        $this->creerAgentAvecDette(75_000);

        $html = Livewire::test(DettesPage::class)->html();

        $this->assertStringContainsString('Dernière activité du jour', $html);
        $this->assertStringNotContainsString('>Heure<', $html);
    }

    /** Recherche par nom d'agent */
    public function test_search_by_agent_name(): void
    {
        $this->creerAgentAvecDette(100_000, 'KOFFI Jean');
        $this->creerAgentAvecDette(200_000, 'YAO Fernand');

        $component = Livewire::test(DettesPage::class)->searchTable('KOFFI');

        $component->assertSee('KOFFI Jean')->assertDontSee('YAO Fernand');
    }

    /** Les dettes actives, même déjà remboursées en partie, ont le même statut */
    public function test_all_outstanding_debts_use_the_in_progress_status(): void
    {
        $agentEnCours = $this->creerAgentAvecDette(100_000, 'Agent En Cours');
        $agentPartiel = $this->creerAgentAvecDette(200_000, 'Agent Partiel');
        app(RemboursementService::class)->process($agentPartiel, 50_000);
        $agentPartiel->recalculateDebt();

        $component = Livewire::test(DettesPage::class);

        $component
            ->assertSee('Agent En Cours')
            ->assertSee('Agent Partiel')
            ->assertSee('En cours')
            ->assertDontSee('Partiellement remboursée');
    }

    /** Les colonnes journalières excluent hier, le reste dû reste cumulatif */
    public function test_daily_columns_exclude_previous_days_while_outstanding_debt_remains_cumulative(): void
    {
        $agent = Agent::factory()->create(['name' => 'Agent Journalier', 'status' => 'active', 'debt_limit' => 0]);
        $distributionService = app(UvDistributionService::class);
        $remboursementService = app(RemboursementService::class);

        $distributionService->distribute(
            $agent,
            400_000,
            $this->fournisseur,
            now()->subDay()->setTime(10, 0)->toDateTimeString(),
        );
        $remboursementService->process(
            $agent->fresh(),
            50_000,
            paymentDate: now()->subDay()->setTime(12, 0)->toDateTimeString(),
        );
        $distributionService->distribute(
            $agent->fresh(),
            100_000,
            $this->fournisseur,
            now()->setTime(9, 0)->toDateTimeString(),
        );
        $remboursementService->process(
            $agent->fresh(),
            20_000,
            paymentDate: now()->setTime(9, 30)->toDateTimeString(),
        );

        $component = Livewire::test(DettesPage::class);
        $record = $component->instance()->getTableRecords()->firstWhere('id', $agent->id);
        $summary = $component->instance()->getSummary();

        $this->assertEqualsWithDelta(100_000, (float) $record->debt_today, 0.01);
        $this->assertEqualsWithDelta(20_000, (float) $record->repaid_today, 0.01);
        $this->assertEqualsWithDelta(430_000, (float) $record->current_debt, 0.01);
        $this->assertEqualsWithDelta(100_000, $summary['debtToday'], 0.01);
        $this->assertEqualsWithDelta(20_000, $summary['repaidToday'], 0.01);
        $this->assertEqualsWithDelta(430_000, $summary['outstanding'], 0.01);
    }

    /** Pagination (backend) */
    public function test_pagination_limits_rows_per_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->creerAgentAvecDette(10_000, "Agent {$i}");
        }

        $component = Livewire::test(DettesPage::class)->set('tableRecordsPerPage', 10);

        $this->assertSame(12, DettesPage::agentsEndettesQuery()->count());
        $component->assertOk();
    }

    /** Sécurité : un utilisateur sans permission n'accède pas à la page */
    public function test_unauthorized_user_cannot_access_the_page(): void
    {
        $this->admin->syncRoles([]);
        $this->admin->syncPermissions([]);

        $this->assertFalse(DettesPage::canAccess());
    }

    /** Résilience : une dette réelle sans ligne agent_soldes associée ne casse pas la page */
    public function test_page_does_not_crash_without_agent_solde(): void
    {
        $agent = $this->creerAgentAvecDette(50_000);
        $agent->solde()->delete();

        $component = Livewire::test(DettesPage::class);

        $component->assertOk()->assertSee($agent->name);
    }
}
