<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Approvisionnements;
use App\Livewire\Mobile\Remboursements;
use App\Livewire\Mobile\Ventes;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\BusinessPeriodResolver;
use App\Services\Mobile\PeriodStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobilePeriodStatsTest extends TestCase
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
        $this->user->assignRole(Role::where('name', 'master')->first() ?? Role::first());
        $this->actingAs($this->user);

        $this->agent = Agent::factory()->create();
        $this->fournisseur = Fournisseur::factory()->create();
    }

    private function vente(float $amount, $date, string $status = 'completed', string $type = 'distribution'): void
    {
        UvTransaction::withoutEvents(fn () => UvTransaction::create([
            'agent_id' => $this->agent->id, 'fournisseur_id' => $this->fournisseur->id, 'user_id' => $this->user->id,
            'transaction_type' => $type, 'amount' => $amount, 'transaction_date' => $date, 'status' => $status,
            'reference' => 'V-'.strtoupper(\Illuminate\Support\Str::random(6)),
        ]));
    }

    public function test_ventes_cards_use_the_business_period_and_ignore_cancelled_sales_and_returns(): void
    {
        $range = app(BusinessPeriodResolver::class)->today();
        $this->vente(100000, $range['start']->copy()->addMinute());
        $this->vente(50000, $range['start']->copy()->addMinutes(2));
        $this->vente(999999, $range['start']->copy()->addMinutes(3), 'cancelled');
        $this->vente(777777, $range['start']->copy()->addMinutes(4), 'completed', 'retour');
        $this->vente(20000, now()->subMonths(3));

        $stats = app(PeriodStats::class)->summary('ventes');

        $this->assertSame(150000.0, $stats['today']['amount']);
        $this->assertSame(2, $stats['today']['count']);
        $this->assertSame(170000.0, $stats['all']['amount']);
        $this->assertSame(['today', 'week', 'month', 'all'], array_keys($stats));

        Livewire::test(Ventes\Index::class)
            ->assertSee('Cette semaine')->assertSee('Total cumulé')->assertSee('Exporter en PDF ou Excel');
    }

    public function test_remboursements_and_approvisionnements_cards_and_week_trend(): void
    {
        Remboursement::withoutEvents(function () {
            foreach ([[40000, now()], [60000, now()->subWeek()], [10000, now()->subMonths(2)]] as [$amount, $date]) {
                Remboursement::create([
                    'agent_id' => $this->agent->id, 'user_id' => $this->user->id, 'amount' => $amount,
                    'payment_date' => $date, 'payment_method' => 'cash', 'reference' => 'R-'.strtoupper(\Illuminate\Support\Str::random(6)),
                ]);
            }
        });
        Approvisionnement::withoutEvents(function () {
            foreach ([300000, 200000] as $amount) {
                Approvisionnement::create([
                    'user_id' => $this->user->id, 'fournisseur_id' => $this->fournisseur->id, 'amount' => $amount,
                    'date_approvisionnement' => now(), 'reference' => 'A-'.strtoupper(\Illuminate\Support\Str::random(6)),
                ]);
            }
        });

        $stats = app(PeriodStats::class);
        $remb = $stats->summary('remboursements');
        $this->assertSame(40000.0, $remb['today']['amount']);
        $this->assertSame(110000.0, $remb['all']['amount']);
        $this->assertSame(3, $remb['all']['count']);
        $this->assertSame(-33.3, $stats->weekTrend('remboursements'));
        $this->assertSame(500000.0, $stats->summary('approvisionnements')['today']['amount']);
        $this->assertNull($stats->weekTrend('ventes'));

        Livewire::test(Remboursements\Index::class)->assertSee('Total cumulé')->assertSee('3 remboursements')->assertSee('−33,3');
        Livewire::test(Approvisionnements\Index::class)->assertSee('2 approvisionnements')->assertSee('Exporter en PDF ou Excel');
    }

    public function test_yesterday_and_month_evolution_have_explicit_zero_days(): void
    {
        $now = \Illuminate\Support\Carbon::parse('2026-10-15 10:00:00');
        \Illuminate\Support\Carbon::setTestNow($now);

        Remboursement::withoutEvents(function () {
            foreach ([['2026-10-14 09:00', 70000], ['2026-10-14 15:00', 30000], ['2026-10-03 12:00', 50000], ['2026-09-30 12:00', 99000]] as $i => [$date, $amount]) {
                Remboursement::create([
                    'agent_id' => $this->agent->id, 'user_id' => $this->user->id, 'amount' => $amount,
                    'payment_date' => $date, 'payment_method' => 'cash', 'reference' => 'R-DAY'.$i,
                ]);
            }
        });

        $stats = app(PeriodStats::class);
        $this->assertSame(['amount' => 100000.0, 'count' => 2], $stats->yesterday('remboursements'));

        $daily = $stats->monthDaily('remboursements');
        $this->assertCount(15, $daily);
        $this->assertSame('2026-10-01', $daily[0]['date']);
        $this->assertSame(0.0, $daily[1]['value']);
        $this->assertSame(50000.0, $daily[2]['value']);
        $this->assertSame(100000.0, $daily[13]['value']);
        $this->assertTrue($daily[14]['today']);
        $this->assertSame(150000.0, collect($daily)->sum('value'));

        Livewire::test(Remboursements\Index::class)
            ->assertSee('Évolution')->assertSee('Hier')->assertSee('Moy.');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_period_exports_download_pdf_and_excel_for_every_module(): void
    {
        $this->vente(100000, app(BusinessPeriodResolver::class)->today()['start']->copy()->addMinute());
        Remboursement::withoutEvents(fn () => Remboursement::create([
            'agent_id' => $this->agent->id, 'user_id' => $this->user->id, 'amount' => 40000, 'payment_date' => now(), 'payment_method' => 'cash', 'reference' => 'R-EXPORT1',
        ]));
        Approvisionnement::withoutEvents(fn () => Approvisionnement::create([
            'user_id' => $this->user->id, 'fournisseur_id' => $this->fournisseur->id, 'amount' => 300000, 'date_approvisionnement' => now(), 'reference' => 'A-EXPORT1',
        ]));

        foreach (PeriodStats::MODULES as $module) {
            foreach (array_keys(PeriodStats::PERIODS) as $period) {
                $pdf = $this->get(route('m.exports.period', [$module, $period, 'pdf']));
                $pdf->assertOk();
                $this->assertStringStartsWith('%PDF', $pdf->streamedContent(), "$module/$period pdf");

                $xlsx = $this->get(route('m.exports.period', [$module, $period, 'xlsx']));
                $xlsx->assertOk();
                $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'), "$module/$period xlsx");
            }
        }

        $this->get('/m/exports/periode/ventes/year/pdf')->assertNotFound();
        $this->get('/m/exports/periode/charges/all/pdf')->assertNotFound();
    }

    public function test_pdf_is_capped_but_keeps_full_totals_and_warns(): void
    {
        config(['mobile.pdf_max_rows' => 3]);
        $start = app(BusinessPeriodResolver::class)->today()['start'];

        foreach (range(1, 5) as $i) {
            $this->vente(10000 * $i, $start->copy()->addMinutes($i));
        }

        $response = $this->get(route('m.exports.period', ['ventes', 'today', 'pdf']));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->streamedContent());

        // Le même modèle, rendu en HTML, doit annoncer la troncature et le total complet (150 000).
        $html = view('exports.period-pdf', [
            'title' => 'Rapport des ventes UV', 'columns' => [['label' => 'A'], ['label' => 'Montant', 'right' => true]],
            'rows' => [['x', '1'], ['y', '2'], ['z', '3']], 'count' => 5, 'total' => '150 000', 'truncated' => 3,
            'period' => "Aujourd'hui", 'from' => 'a', 'to' => 'b', 'generatedAt' => now(), 'generatedBy' => 'Test',
        ])->render();
        $this->assertStringContainsString('Seules les 3 lignes les plus récentes sur 5', $html);
        $this->assertStringContainsString('150 000', $html);
    }

    public function test_a_pdf_failure_is_logged_and_returns_a_clear_error_not_a_blank_500(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('error')->once()->withArgs(fn ($m) => str_contains($m, 'Export PDF'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->andThrow(new \RuntimeException('Allowed memory size exhausted'));

        $this->get(route('m.exports.period', ['ventes', 'all', 'pdf']))
            ->assertStatus(500)->assertJsonPath('message', fn ($m) => str_contains($m, "Le PDF n'a pas pu être généré"));
    }

    public function test_period_exports_require_the_export_permission(): void
    {
        $role = Role::firstOrCreate(['name' => 'lecteur', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::whereIn('name', ['view_uv_transactions', 'view_remboursements'])->get());
        $viewer = User::factory()->create();
        $viewer->assignRole($role);
        $this->actingAs($viewer);

        $this->get(route('m.exports.period', ['ventes', 'all', 'pdf']))->assertForbidden();
        $this->get(route('m.exports.period', ['remboursements', 'all', 'xlsx']))->assertForbidden();
        Livewire::test(Ventes\Index::class)->assertDontSee('Exporter en PDF ou Excel');
    }
}
