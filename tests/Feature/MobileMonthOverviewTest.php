<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Charges;
use App\Livewire\Mobile\Commissions;
use App\Livewire\Mobile\Home;
use App\Livewire\Mobile\HomeMonth;
use App\Livewire\Mobile\Ventes;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Commission;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\BusinessPeriodResolver;
use App\Services\Mobile\MonthOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileMonthOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Agent $agent;

    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole('master');
        $this->actingAs($this->user);
        $this->agent = Agent::factory()->create(['name' => 'Awa Test']);
        $this->fournisseur = Fournisseur::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(float $amount, $date, string $status = 'completed', ?Agent $agent = null): void
    {
        UvTransaction::withoutEvents(fn () => UvTransaction::create([
            'agent_id' => ($agent ?? $this->agent)->id, 'fournisseur_id' => $this->fournisseur->id, 'user_id' => $this->user->id,
            'transaction_type' => 'distribution', 'amount' => $amount, 'transaction_date' => $date, 'status' => $status,
            'reference' => 'V-'.Str::upper(Str::random(6)),
        ]));
    }

    private function commission(float $amount, string $date): void
    {
        Commission::create([
            'is_global' => true, 'montant_vente' => 0, 'montant_commission' => 0, 'seuil_min' => 0, 'seuil_max' => 1, 'volume_agent' => 0,
            'part_agent' => 0, 'pourcentage_contribution' => 0, 'commission_globale' => $amount, 'volume_total_jour' => 100000,
            'nombre_transactions' => 1, 'date_commission' => $date, 'statut' => 'calculee', 'calculated_at' => now(),
        ]);
    }

    public function test_monthly_performance_compares_with_the_previous_month_and_ignores_cancelled_sales(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->sale(300000, '2026-10-05 09:00:00');
        $this->sale(100000, '2026-10-06 09:00:00');
        $this->sale(999999, '2026-10-07 09:00:00', 'cancelled');
        $this->sale(200000, '2026-09-10 09:00:00');
        Approvisionnement::withoutEvents(fn () => Approvisionnement::create(['user_id' => $this->user->id, 'fournisseur_id' => $this->fournisseur->id, 'amount' => 5000000, 'date_approvisionnement' => '2026-10-02 08:00:00', 'reference' => 'A-T1']));
        Remboursement::withoutEvents(fn () => Remboursement::create(['agent_id' => $this->agent->id, 'user_id' => $this->user->id, 'amount' => 70000, 'payment_date' => '2026-10-08 12:00:00', 'payment_method' => 'cash', 'reference' => 'R-T1']));
        $this->commission(9000, '2026-10-05');
        $this->commission(6000, '2026-09-20');

        $perf = collect((new MonthOverview)->performance())->keyBy('key');

        $this->assertSame(400000.0, $perf['ca']['now']);
        $this->assertSame(200000.0, $perf['ca']['before']);
        $this->assertSame(100.0, MonthOverview::pct($perf['ca']['now'], $perf['ca']['before']));
        $this->assertSame(2.0, $perf['transactions']['now']);
        $this->assertSame(5000000.0, $perf['appro']['now']);
        $this->assertSame(70000.0, $perf['rembs']['now']);
        $this->assertSame(9000.0, $perf['commissions']['now']);
        $this->assertSame(6000.0, $perf['commissions']['before']);
        $this->assertSame('down', $perf['dettes']['good']);
        $this->assertSame('vs début du mois', $perf['dettes']['vs']);

        // Règle du bureau : base nulle → 100 % si la valeur est positive, 0 % sinon.
        $this->assertSame(100.0, MonthOverview::pct(5, 0));
        $this->assertSame(0.0, MonthOverview::pct(0, 0));
        $this->assertSame(-50.0, MonthOverview::pct(50, 100));
    }

    public function test_daily_series_have_explicit_zero_days_and_commissions_exclude_future_days(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        $this->sale(100000, '2026-10-02 09:00:00');
        $this->sale(50000, '2026-10-02 15:00:00');
        $this->sale(70000, '2026-10-04 08:00:00');
        $this->commission(4000, '2026-10-03');

        $overview = new MonthOverview;
        $sales = $overview->dailySales();
        $this->assertCount(4, $sales);
        $this->assertSame([0.0, 150000.0, 0.0, 70000.0], array_column($sales, 'value'));
        $this->assertTrue($sales[3]['today']);

        $this->assertSame([0.0, 0.0, 4000.0, 0.0], array_column($overview->dailyCommissions(), 'value'));
    }

    public function test_top_five_agents_today_is_ranked_limited_and_business_day_based(): void
    {
        $start = app(BusinessPeriodResolver::class)->today()['start'];

        foreach (range(1, 6) as $i) {
            $this->sale(10000 * $i, $start->copy()->addMinutes($i), 'completed', Agent::factory()->create(['name' => "Agent $i"]));
        }
        $this->sale(5000000, $start->copy()->addMinutes(9), 'cancelled');
        $this->sale(1, $start->copy()->subDays(3));

        $top = (new MonthOverview)->topAgentsToday();
        $this->assertCount(5, $top);
        $this->assertSame(['Agent 6', 'Agent 5', 'Agent 4', 'Agent 3', 'Agent 2'], $top->pluck('name')->all());
        $this->assertSame(60000.0, $top->first()['amount']);

        Livewire::test(Ventes\Index::class)->assertSee('Top 5 des agents')->assertSee('Agent 6')
            ->set('search', 'Agent 2')->assertDontSee('Top 5 des agents');
    }

    public function test_home_month_blocks_follow_permissions(): void
    {
        $this->sale(250000, now());
        Livewire::withoutLazyLoading()->test(HomeMonth::class)
            ->assertSee('Revenu du mois')->assertSee('Revenu net')->assertSee('Performance mensuelle')->assertSee('Évolution du CA');

        $limited = User::factory()->create();
        $limited->givePermissionTo('view_statistics');
        $this->actingAs($limited);
        Livewire::withoutLazyLoading()->test(HomeMonth::class)->assertDontSee('Revenu du mois')->assertSee('Performance mensuelle');

        $none = User::factory()->create();
        $this->actingAs($none);
        Livewire::withoutLazyLoading()->test(HomeMonth::class)->assertDontSee('Revenu du mois')->assertDontSee('Performance mensuelle');
    }

    public function test_commissions_page_shows_yesterday_and_the_monthly_charts(): void
    {
        Livewire::test(Commissions\Index::class)
            ->assertSee('Hier')->assertSee('Évolution des commissions')->assertSee('Évolution du CA');
    }

    public function test_charges_cards_and_exports_for_every_period_and_format(): void
    {
        $category = ChargeCategorie::create(['name' => 'Loyer', 'code' => 'LOYER', 'is_active' => true]);
        foreach ([[12000, now()], [8000, now()->subMonth()->startOfMonth()->addDays(2)], [3000, now()->subYear()]] as $i => [$amount, $date]) {
            Charge::create(['category_id' => $category->id, 'libelle' => "Charge $i", 'montant' => $amount, 'date_charge' => $date->toDateString(), 'heure_charge' => '10:00:00', 'created_by' => $this->user->id]);
        }
        $this->commission(5000, now()->toDateString());

        Livewire::test(Charges\Index::class)->assertSee('Total revenu')->assertSee('Total charges')->assertSee('Exporter en PDF ou Excel')->assertSee('CSV');

        foreach (array_keys(\App\Support\Mobile\ChargePeriods::LABELS) as $period) {
            $pdf = $this->get(route('m.exports.charges', [$period, 'pdf']));
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->streamedContent(), "$period pdf");
            $this->get(route('m.exports.charges', [$period, 'xlsx']))->assertOk();
            $csv = $this->get(route('m.exports.charges', [$period, 'csv']));
            $csv->assertOk();
        }

        $this->get('/m/exports/charges/decade/pdf')->assertNotFound();

        // Le total de l'export « Tout » reprend celui de la carte.
        $this->assertSame(23000.0, \App\Support\Mobile\ChargePeriods::summary('all')['charges']);
        $this->assertSame(12000.0, \App\Support\Mobile\ChargePeriods::summary('today')['charges']);
    }

    public function test_charges_export_requires_the_export_permission(): void
    {
        $role = Role::firstOrCreate(['name' => 'lecteur-charges', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::whereIn('name', ['view_charges'])->get());
        $viewer = User::factory()->create();
        $viewer->assignRole($role);
        $this->actingAs($viewer);

        $this->get(route('m.exports.charges', ['all', 'pdf']))->assertForbidden();
        Livewire::test(Charges\Index::class)->assertDontSee('Exporter en PDF ou Excel');
    }

    public function test_home_renders_with_the_month_section(): void
    {
        $this->get('/m')->assertOk();
        Livewire::test(Home::class)->assertOk();
    }
}
