<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Analytics;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\Mobile\StatisticsService;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['view_statistics', 'view_reports', 'export_reports', 'view_agents', 'view_dashboard'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web'])->syncPermissions(self::PERMISSIONS);
        $this->user = User::factory()->create();
        $this->user->assignRole('master');
        $this->actingAs($this->user);
    }

    private function seedSales(): Agent
    {
        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => Fournisseur::factory()->create()->id,
            'amount' => 5000000,
            'date_approvisionnement' => now(),
        ]);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 1000000, 'name' => 'Awa Koné']);
        $service = new UvDistributionService;
        $service->distribute($agent, 100000);
        $service->distribute($agent, 50000);

        return $agent->fresh();
    }

    public function test_statistics_include_the_whole_last_day_and_exclude_cancelled_sales(): void
    {
        $agent = $this->seedSales();
        $cancelled = (new UvDistributionService)->distribute($agent, 30000);
        (new UvDistributionService)->cancel($cancelled);

        // Une vente enregistrée en fin de journée doit compter dans « aujourd'hui ».
        \App\Models\UvTransaction::where('amount', 50000)->update(['transaction_date' => now()->setTime(23, 30)]);

        [$from, $to] = StatisticsService::range('day');
        $overview = (new StatisticsService($from, $to))->overview();

        $this->assertEquals(150000, $overview['ca']);
        $this->assertSame(2, $overview['ventes']);
        $this->assertEquals(75000, $overview['ticket_moyen']);
    }

    public function test_statistics_screen_renders_for_every_period_and_agent_filter(): void
    {
        $agent = $this->seedSales();

        foreach (['day', 'week', 'month', 'quarter', 'year', 'custom'] as $period) {
            Livewire::test(Analytics\Statistics::class)->set('period', $period)->assertOk()->assertSee('Chiffre d\'affaires', false);
        }

        Livewire::test(Analytics\Statistics::class)->set('agentId', $agent->id)->assertSee('Awa Koné')->assertSee('Meilleurs agents');
    }

    public function test_statistics_series_switches_to_monthly_buckets_on_long_periods(): void
    {
        $this->seedSales();

        $daily = (new StatisticsService(now()->subDays(10), now()))->series();
        $monthly = (new StatisticsService(now()->subDays(200), now()))->series();

        $this->assertCount(11, $daily);
        $this->assertGreaterThanOrEqual(6, count($monthly));
        $this->assertLessThan(15, count($monthly));
    }

    public function test_scoring_screens_use_the_analytics_service(): void
    {
        $agent = Agent::factory()->create(['name' => 'Moussa Traoré']);
        $this->mock(AnalyticsService::class, function ($mock) use ($agent) {
            $mock->shouldReceive('getAgentScoring')->andReturn(new Collection([[
                'id' => $agent->id, 'name' => $agent->name, 'phone' => $agent->phone, 'score' => 82, 'grade' => 'A',
                'details' => ['volume' => 20, 'regularite' => 18, 'paiement' => 24, 'dette' => 20, 'total' => 82],
                'recommendations' => ['Relancer le paiement'],
            ]]));
        });

        Livewire::test(Analytics\Scoring::class)->assertSee('Moussa Traoré')->assertSee('82/100')->assertSee('Grade A')
            ->set('grade', 'poor')->assertDontSee('Moussa Traoré');

        Livewire::test(Analytics\ScoringAgent::class, ['agent' => $agent])
            ->assertSee('82')->assertSee('Volume')->assertSee('Relancer le paiement');
    }

    public function test_reports_screen_builds_export_links_from_the_filters(): void
    {
        $agent = $this->seedSales();

        Livewire::test(Analytics\Reports::class)
            ->set('agentId', $agent->id)->set('type', 'distribution')
            ->assertSeeHtml('exports/rapport/transactions/pdf')->assertSeeHtml('agent='.$agent->id)
            ->set('report', 'remboursements')->assertSeeHtml('exports/rapport/remboursements/xlsx')->assertDontSeeHtml('exports/rapport/remboursements/pdf');
    }

    public function test_report_exports_download_pdf_and_excel(): void
    {
        $agent = $this->seedSales();

        $pdf = $this->get(route('m.exports.report', ['transactions', 'pdf', 'agent' => $agent->id, 'from' => now()->startOfMonth()->toDateString()]));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));

        $this->get(route('m.exports.report', ['transactions', 'xlsx']))->assertOk();
        $this->get(route('m.exports.report', ['remboursements', 'xlsx']))->assertOk();
        $this->get(route('m.exports.report', ['agents', 'pdf', 'debt' => 1]))->assertOk();
        $this->get(route('m.exports.report', ['remboursements', 'pdf']))->assertNotFound();
        $this->get(route('m.exports.report', ['inconnu', 'pdf']))->assertNotFound();
    }

    public function test_exports_require_the_export_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']));
        $viewer->givePermissionTo('view_reports');
        $this->actingAs($viewer);

        Livewire::test(Analytics\Reports::class)->assertSee('pas le droit');
        $this->get(route('m.exports.report', ['transactions', 'xlsx']))->assertForbidden();
    }
}
