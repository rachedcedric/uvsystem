<?php

use App\Filament\Resources\UvTransactionResource\Pages\ListUvTransactions;
use App\Filament\Widgets\Top5AgentsTodayWidget;
use App\Filament\Widgets\VentesUvStatsWidget;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function ventesUvAdmin(): User
{
    foreach (['view_uv_transactions', 'create_uv_transactions'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    $role->givePermissionTo(['view_uv_transactions', 'create_uv_transactions']);

    $admin = User::factory()->create();
    $admin->assignRole('master');

    return $admin;
}

function approvisionnerPourVentesUv(User $admin, Fournisseur $fournisseur, float $amount): void
{
    Approvisionnement::create([
        'user_id' => $admin->id,
        'fournisseur_id' => $fournisseur->id,
        'amount' => $amount,
        'date_approvisionnement' => now(),
    ]);
}

function venteUvA(Carbon $instant, Agent $agent, float $amount): UvTransaction
{
    return (new UvDistributionService)->distribute(agent: $agent, amount: $amount, transactionDate: $instant->toDateTimeString());
}

describe('VentesUvStatsWidget — 4 widgets honoring the 18:02 business-day cutoff', function () {
    it('splits sales at the cutoff boundary between today/yesterday and accumulates week/month/cumulative correctly', function () {
        $admin = ventesUvAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();
        approvisionnerPourVentesUv($admin, $fournisseur, 1_000_000_000);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

        // 17:59:59 le 10/03/2026 : journée métier du 10/03 (ne compte plus
        // "aujourd'hui" une fois qu'on se place au 11/03).
        venteUvA(Carbon::create(2026, 3, 10, 17, 59, 59), $agent, 100_000);
        // 18:02:00 le 10/03/2026 : bascule sur la journée métier du 11/03.
        venteUvA(Carbon::create(2026, 3, 10, 18, 2, 0), $agent, 200_000);
        // 11/03/2026 10:00 : journée métier du 11/03 également.
        venteUvA(Carbon::create(2026, 3, 11, 10, 0, 0), $agent, 50_000);

        Carbon::setTestNow(Carbon::create(2026, 3, 11, 12, 0, 0));

        $component = Livewire::actingAs($admin)->test(VentesUvStatsWidget::class);
        $component->assertSuccessful()
            ->assertSee("Ventes Aujourd'hui")
            ->assertSee('250 000 FCFA') // 200 000 (18:02 le 10/03) + 50 000 (11/03) = journée métier du 11/03
            ->assertSee('Cette semaine')
            ->assertSee('Ce mois')
            ->assertSee('Total cumulé')
            ->assertSee('350 000 FCFA'); // total cumulé des 3 ventes
    });
});

describe('Top5AgentsTodayWidget — ranks agents by today\'s business-day volume', function () {
    it('shows only today\'s business-day sales, sorted descending, limited to 5', function () {
        $admin = ventesUvAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();
        approvisionnerPourVentesUv($admin, $fournisseur, 1_000_000_000);

        Carbon::setTestNow(Carbon::create(2026, 4, 1, 10, 0, 0));

        $agents = collect(range(1, 6))->map(fn ($i) => Agent::factory()->create(['name' => "Agent {$i}", 'status' => 'active', 'debt_limit' => 0]));

        foreach ($agents as $i => $agent) {
            venteUvA(now(), $agent, ($i + 1) * 10_000);
        }

        // Vente d'hier pour l'agent 1 : ne doit pas gonfler le total "aujourd'hui".
        venteUvA(Carbon::create(2026, 3, 31, 10, 0, 0), $agents[0], 500_000);

        $widget = new Top5AgentsTodayWidget;
        $top = $widget->getTopAgents();

        expect($top)->toHaveCount(5)
            ->and($top->first()['name'])->toBe('Agent 6')
            ->and($top->first()['amount'])->toEqualWithDelta(60_000.0, 0.01)
            ->and($top->pluck('name'))->not->toContain('Agent 1'); // Agent 1 (10 000 aujourd'hui) hors du top 5
    });
});

describe('ListUvTransactions — footer widget placement and cleaned-up history columns', function () {
    it('registers Top5AgentsTodayWidget as a footer widget (below the history table)', function () {
        $page = new ListUvTransactions;

        $footerWidgets = (fn () => $this->getFooterWidgets())->call($page);

        expect($footerWidgets)->toContain(Top5AgentsTodayWidget::class);
    });

    it('no longer shows a "Solde Avant" column and labels the date column "Date et heure"', function () {
        $admin = ventesUvAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();
        approvisionnerPourVentesUv($admin, $fournisseur, 1_000_000);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        venteUvA(now(), $agent, 50_000);

        $html = Livewire::test(ListUvTransactions::class)->html();

        expect($html)->not->toContain('Solde Avant')
            ->toContain('Date et heure')
            ->toContain('Solde Après');
    });

    it('uses 25 rows by default in the compact transaction history', function () {
        $admin = ventesUvAdmin();
        $this->actingAs($admin);

        $component = Livewire::test(ListUvTransactions::class);

        expect($component->instance()->getTableRecordsPerPage())->toBe(25);
    });
});
