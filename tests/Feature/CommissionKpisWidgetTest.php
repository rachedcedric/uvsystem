<?php

use App\Filament\Resources\CommissionResource\Pages\ListCommissions;
use App\Filament\Widgets\CommissionKpisWidget;
use App\Filament\Widgets\CommissionMonthlyChartWidget;
use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function commissionKpiAgent(): Agent
{
    $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

    AgentSolde::firstOrCreate(['agent_id' => $agent->id], [
        'total_uv_distribues' => 0,
        'total_rembourse' => 0,
        'solde_restant' => 0,
    ]);

    return $agent;
}

function commissionKpiApprovisionner(float $amount): void
{
    Approvisionnement::create([
        'user_id' => User::factory()->create()->id,
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'amount' => $amount,
        'date_approvisionnement' => now(),
    ]);
}

function commissionKpiVenteA(Carbon $instant, Agent $agent, float $amount): void
{
    commissionKpiApprovisionner($amount);

    Carbon::setTestNow($instant);
    try {
        (new UvDistributionService)->distribute(agent: $agent, amount: $amount);
    } finally {
        Carbon::setTestNow();
    }
}

describe('CommissionKpisWidget — 8 widgets, single source of truth', function () {
    it('shows Aujourd\'hui and Commission prévisionnelle as the same live projection before closure', function () {
        $agent = commissionKpiAgent();
        $jour = Carbon::create(2026, 7, 10, 10, 0);
        commissionKpiVenteA($jour, $agent, 1_200_000);

        Carbon::setTestNow(Carbon::create(2026, 7, 10, 14, 0));

        $widget = Livewire::test(CommissionKpisWidget::class);
        $widget->assertSuccessful()
            ->assertSee("Aujourd'hui")
            ->assertSee('Commission prévisionnelle')
            ->assertSee('1 800 FCFA'); // palier par défaut pour 1 200 000 (voir seeder de test)
    });

    it('matches the closed commission value once the business day has been calculated', function () {
        $agent = commissionKpiAgent();
        $jour = Carbon::create(2026, 7, 11, 10, 0);
        commissionKpiVenteA($jour, $agent, 1_200_000);

        app(CommissionService::class)->verifierEtCorrigerCommission(Carbon::create(2026, 7, 11), 'manual');

        // "Hier" vu depuis le 12/07 : doit correspondre exactement à la
        // ligne clôturée en base, pas à un recalcul indépendant.
        Carbon::setTestNow(Carbon::create(2026, 7, 12, 9, 0));

        $commissionStockee = (float) Commission::where('is_global', true)->whereDate('date_commission', '2026-07-11')->first()->commission_globale;

        Livewire::test(CommissionKpisWidget::class)
            ->assertSuccessful()
            ->assertSee("Commission d'hier")
            ->assertSee(number_format($commissionStockee, 0, ',', ' ').' FCFA');
    });

    it('shows Volume du jour as the raw sales volume, distinct from the commission amount', function () {
        $agent = commissionKpiAgent();
        $jour = Carbon::create(2026, 7, 13, 10, 0);
        commissionKpiVenteA($jour, $agent, 1_200_000);

        Carbon::setTestNow(Carbon::create(2026, 7, 13, 14, 0));

        Livewire::test(CommissionKpisWidget::class)
            ->assertSuccessful()
            ->assertSee('Volume du jour (en cours)')
            ->assertSee('1 200 000 FCFA'); // volume brut, pas la commission (1 800 FCFA)
    });

    it('labels the next cutoff as today or tomorrow at the configured hour', function () {
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 9, 0));

        Livewire::test(CommissionKpisWidget::class)
            ->assertSuccessful()
            ->assertSee('Prochain calcul')
            ->assertSee("Aujourd'hui à 18h02");

        Carbon::setTestNow(Carbon::create(2026, 7, 14, 19, 0));

        Livewire::test(CommissionKpisWidget::class)
            ->assertSee('Demain à 18h02');
    });
});

describe('CommissionMonthlyChartWidget — zero-filled days', function () {
    it('includes every day of the month so far, with 0 for days without a closed commission', function () {
        $agent = commissionKpiAgent();
        commissionKpiVenteA(Carbon::create(2026, 7, 1, 10, 0), $agent, 1_200_000);
        app(CommissionService::class)->verifierEtCorrigerCommission(Carbon::create(2026, 7, 1), 'manual');

        // commissionKpiVenteA() réinitialise Carbon::setTestNow() dans son
        // "finally" : figer "maintenant" APRÈS l'avoir appelée, jamais avant.
        Carbon::setTestNow(Carbon::create(2026, 7, 5, 10, 0));

        $widget = new CommissionMonthlyChartWidget;
        $data = (fn () => $this->getData())->call($widget);

        expect($data['labels'])->toHaveCount(5) // du 01 au 05 juillet inclus
            ->and($data['datasets'][0]['data'][0])->toBe(1800.0)
            ->and($data['datasets'][0]['data'][1])->toBe(0.0)
            ->and($data['datasets'][0]['data'][4])->toBe(0.0);
    });
});

describe('ListCommissions — header widgets registered', function () {
    it('includes CommissionKpisWidget and CommissionMonthlyChartWidget', function () {
        $page = new ListCommissions;

        $widgets = (fn () => $this->getHeaderWidgets())->call($page);

        expect($widgets)->toContain(CommissionKpisWidget::class)
            ->and($widgets)->toContain(CommissionMonthlyChartWidget::class);
    });
});
