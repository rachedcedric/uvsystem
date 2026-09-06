<?php

use App\Filament\Pages\ChargesPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DettesPage;
use App\Filament\Resources\ApprovisionnementResource;
use App\Filament\Resources\RemboursementResource;
use App\Filament\Resources\UvTransactionResource;
use App\Filament\Widgets\MainKpisWidget;
use App\Filament\Widgets\RevenueChargesNetWidget;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Commission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function dashboardMaster(): User
{
    Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('master');

    return $user;
}

describe('Dashboard — 9 widgets', function () {
    it('includes RevenueChargesNetWidget right after the 6 main KPIs', function () {
        $widgets = (new Dashboard)->getWidgets();

        expect($widgets)->toContain(MainKpisWidget::class)
            ->and($widgets)->toContain(RevenueChargesNetWidget::class);

        $mainIndex = array_search(MainKpisWidget::class, $widgets, true);
        $revenueIndex = array_search(RevenueChargesNetWidget::class, $widgets, true);

        expect($revenueIndex)->toBe($mainIndex + 1);
    });
});

describe('MainKpisWidget — clickable stats', function () {
    it('links Ventes/Approvisionnements/Dettes/Remboursements to their existing pages', function () {
        $user = dashboardMaster();

        Livewire::actingAs($user)
            ->test(MainKpisWidget::class)
            ->assertSuccessful()
            ->assertSeeHtml('href="'.UvTransactionResource::getUrl('index').'"')
            ->assertSeeHtml('href="'.ApprovisionnementResource::getUrl('index').'"')
            ->assertSeeHtml('href="'.DettesPage::getUrl().'"')
            ->assertSeeHtml('href="'.RemboursementResource::getUrl('index').'"');
    });
});

describe('RevenueChargesNetWidget — reuses ChargeStatisticsService, no duplicate formula', function () {
    it('shows revenue = commissions generated, charges, and net for the current month, and links to the Charges page', function () {
        $user = dashboardMaster();
        $now = Carbon::now();

        Commission::create([
            'agent_id' => null,
            'is_global' => true,
            'date_commission' => $now->copy()->startOfMonth()->addDays(2),
            'volume_total_jour' => 1_200_000,
            'commission_globale' => 1800,
            'volume_agent' => 0,
            'part_agent' => 0,
            'pourcentage_contribution' => 100,
            'nombre_transactions' => 1,
            'montant_vente' => 1_200_000,
            'montant_commission' => 1800,
            'seuil_min' => 0,
            'seuil_max' => 0,
            'statut' => Commission::STATUT_CALCULATED,
        ]);

        $categorie = ChargeCategorie::create(['code' => 'LOYER', 'name' => 'Loyer']);
        Charge::create([
            'category_id' => $categorie->id,
            'libelle' => 'Loyer du mois',
            'montant' => 500,
            'date_charge' => $now->copy()->startOfMonth()->addDay(),
        ]);

        Livewire::actingAs($user)
            ->test(RevenueChargesNetWidget::class)
            ->assertSuccessful()
            ->assertSee('Total Revenu')
            ->assertSee('Total Charges')
            ->assertSee('Total Revenu net')
            ->assertSee('1 800 FCFA')
            ->assertSee('500 FCFA')
            ->assertSee('1 300 FCFA')
            ->assertSeeHtml('href="'.ChargesPage::getUrl().'"');
    });
});
