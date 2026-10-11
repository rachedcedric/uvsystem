<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AlertesWidget;
use App\Filament\Widgets\MainKpisWidget;
use App\Filament\Widgets\QuickActionsWidget;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Refonte visuelle du tableau de bord de l'espace entreprise : même données, mêmes droits, mise en page modernisée.
 * Ces tests vérifient surtout que rien n'a été ouvert ni inventé : raccourcis filtrés par permission, chiffres
 * propres à chaque entreprise, aucune requête par ligne dans le tableau d'alertes.
 */
class DashboardUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
        $this->tenantA = Tenant::create(['name' => 'Boutique Soleil']);
        $this->tenantB = Tenant::create(['name' => 'Société B']);
    }

    private function userIn(Tenant $tenant, string $role, string $name = 'Awa Koné'): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role, $name) {
            $user = User::factory()->create(['name' => $name]);
            $user->assignRole($role);

            return $user;
        });
    }

    private function act(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Agent actif proche de sa limite de dette (la dette est posée sans passer par le recalcul des soldes).
     * Montants à 0,5 près : SQLite range 95000.00 en entier et ferait une division entière dans la requête d'alertes
     * (ratio 0), ce que MySQL ne fait pas.
     */
    private function debtor(Tenant $tenant, string $name, float $debt = 95000.5): Agent
    {
        return TenantContext::runAs($tenant->id, function () use ($name, $debt) {
            $agent = Agent::factory()->create(['name' => $name, 'status' => 'active', 'debt_limit' => 100000.5]);
            $agent->forceFill(['current_debt' => $debt])->saveQuietly();

            return $agent;
        });
    }

    // ---------------------------------------------------------------------------------------------- rendu

    public function test_the_dashboard_renders_for_each_tenant_role(): void
    {
        foreach (['master', 'admin', 'comptable', 'commercial'] as $role) {
            $user = $this->userIn($this->tenantA, $role);

            $this->actingAs($user)->get('/master')
                ->assertOk()
                ->assertSee('css/filament-dashboard.css', false)
                ->assertSee('Boutique Soleil');
        }
    }

    public function test_the_header_greets_the_user_with_the_company_name_only(): void
    {
        $a = $this->userIn($this->tenantA, 'master', 'Awa Koné');
        $b = $this->userIn($this->tenantB, 'master', 'Ibrahim Traoré');

        $this->actingAs($a)->get('/master')->assertSee('Bonjour Awa')->assertDontSee('Société B')->assertDontSee('Ibrahim');
        $this->actingAs($b)->get('/master')->assertSee('Bonjour Ibrahim')->assertDontSee('Boutique Soleil');
    }

    public function test_the_curated_widget_order_keeps_the_kpi_blocks_first_and_removes_nothing(): void
    {
        $widgets = (new Dashboard)->getWidgets();

        $this->assertSame(QuickActionsWidget::class, $widgets[0]);
        $this->assertSame(MainKpisWidget::class, $widgets[1]);
        $this->assertCount(count($widgets), array_unique($widgets));
        foreach (['RevenueChargesNetWidget', 'MonthlyPerformanceWidget', 'CaChartWidget', 'TopAgentsWidget', 'MonthlyChartWidget', 'AlertesWidget', 'RealTimeActivityWidget', 'NotificationsRecentesWidget'] as $short) {
            $this->assertContains("App\\Filament\\Widgets\\{$short}", $widgets, $short);
        }
    }

    // ---------------------------------------------------------------------------------- actions rapides

    public function test_quick_actions_only_offer_what_the_role_may_do(): void
    {
        $master = $this->userIn($this->tenantA, 'master');
        $this->act($master);
        $masterLabels = array_column(QuickActionsWidget::actions(), 'label');

        foreach (['Nouvelle vente', 'Nouveau remboursement', 'Approvisionnement', 'Nouvel agent'] as $label) {
            $this->assertContains($label, $masterLabels);
        }

        foreach (['admin', 'comptable', 'commercial'] as $role) {
            $user = $this->userIn($this->tenantA, $role);
            $this->act($user);

            foreach (QuickActionsWidget::actions() as $action) {
                $this->assertNotSame('', $action['url']);
            }

            // Un raccourci n'existe jamais sans le droit de création correspondant.
            $labels = array_column(QuickActionsWidget::actions(), 'label');
            $this->assertSame(in_array('Nouvel agent', $labels, true), $user->can('create', Agent::class), "$role / Nouvel agent");
            $this->assertSame(in_array('Nouvelle vente', $labels, true), $user->can('create', UvTransaction::class), "$role / Nouvelle vente");
        }
    }

    public function test_quick_actions_are_hidden_when_the_user_may_do_nothing(): void
    {
        $user = $this->userIn($this->tenantA, 'commercial');
        $user->syncRoles([]);
        $user->syncPermissions([]);
        $this->act($user);

        $this->assertSame([], QuickActionsWidget::actions());
        $this->assertFalse(QuickActionsWidget::canView());
    }

    public function test_quick_actions_are_empty_without_authentication(): void
    {
        $this->assertSame([], QuickActionsWidget::actions());
    }

    // --------------------------------------------------------------------------------------- isolation

    public function test_kpis_are_computed_per_company_and_show_zero_when_empty(): void
    {
        $a = $this->userIn($this->tenantA, 'master');
        $b = $this->userIn($this->tenantB, 'master');

        TenantContext::runAs($this->tenantA->id, function () use ($a) {
            $agent = Agent::factory()->create();
            $fournisseur = Fournisseur::factory()->create();
            UvTransaction::withoutEvents(fn () => UvTransaction::create([
                'agent_id' => $agent->id, 'fournisseur_id' => $fournisseur->id, 'user_id' => $a->id,
                'transaction_type' => 'distribution', 'amount' => 777000, 'transaction_date' => now(),
                'status' => 'completed', 'reference' => 'V-UITEST',
            ]));
        });

        $this->act($a);
        $htmlA = Livewire::test(MainKpisWidget::class)->assertSuccessful()->html();

        $this->act($b);
        $htmlB = Livewire::test(MainKpisWidget::class)->assertSuccessful()->html();

        $this->assertStringContainsString('777', preg_replace('/[\s\x{202F}\x{00A0}]+/u', '', $htmlA));
        $this->assertStringNotContainsString('777', preg_replace('/[\s\x{202F}\x{00A0}]+/u', '', $htmlB));
        $this->assertMatchesRegularExpression('/0\s*FCFA/', $htmlB);
    }

    public function test_alerts_only_list_the_agents_of_the_current_company(): void
    {
        $this->debtor($this->tenantA, 'Agent Alpha');
        $this->debtor($this->tenantB, 'Agent Bravo');

        $this->act($this->userIn($this->tenantA, 'master'));

        Livewire::test(AlertesWidget::class)
            ->loadTable()
            ->assertSee('Agent Alpha')
            ->assertDontSee('Agent Bravo')
            ->assertSee('Élevé');
    }

    // ------------------------------------------------------------------------------- requêtes / N+1

    public function test_the_alerts_table_runs_the_same_number_of_queries_whatever_the_agent_count(): void
    {
        $count = function (int $agents): int {
            TenantContext::runAs($this->tenantA->id, function () use ($agents) {
                Agent::query()->delete();
                for ($i = 0; $i < $agents; $i++) {
                    $this->debtor($this->tenantA, "Agent $i", 90000.5);
                }
            });

            $this->act($this->userIn($this->tenantA, 'master'));

            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(AlertesWidget::class)->loadTable()->assertSuccessful()->assertSee('Agent '.($agents - 1));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $few = $count(2);
        $many = $count(8);

        $this->assertSame($few, $many, "requêtes avec 2 agents: $few, avec 8 agents: $many");
    }

    // ------------------------------------------------------------------------- contenu et feuille de style

    public function test_headings_are_plain_text_without_emoji(): void
    {
        $this->act($this->userIn($this->tenantA, 'master'));

        $this->get('/master')->assertOk()->assertSee('Boutique Soleil');

        $emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u';
        $quick = Livewire::test(QuickActionsWidget::class)->assertSee('Actions rapides')->html();
        $kpis = Livewire::test(MainKpisWidget::class)->assertSee("Vue d'ensemble")->html();
        $alerts = Livewire::test(AlertesWidget::class)->assertSee('Alertes et actions requises')->html();

        foreach ([$quick, $kpis, $alerts] as $html) {
            $this->assertDoesNotMatchRegularExpression($emoji, strip_tags($html));
        }
    }

    public function test_the_stylesheet_is_scoped_to_the_dashboard_and_covers_dark_mode(): void
    {
        $css = file_get_contents(public_path('css/filament-dashboard.css'));

        $this->assertNotFalse($css);
        $this->assertStringContainsString('.dark .fi-dashboard-page', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);

        // Aucune règle ne sort de .fi-dashboard-page (hors variables, requêtes média et commentaires).
        $stripped = preg_replace('/@media[^{]*\{/', '', preg_replace('#/\*.*?\*/#s', '', $css));
        preg_match_all('/(^|\})\s*([^{}@][^{}]*)\{/m', $stripped, $matches);
        foreach ($matches[2] as $selectorList) {
            foreach (explode(',', $selectorList) as $selector) {
                $this->assertStringContainsString('.fi-dashboard-page', trim($selector), "sélecteur hors périmètre : $selector");
            }
        }
    }
}
