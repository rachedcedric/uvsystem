<?php

namespace Tests\Feature;

use App\Filament\Widgets\CaChartWidget;
use App\Filament\Widgets\CommissionKpisWidget;
use App\Filament\Widgets\CommissionMonthlyChartWidget;
use App\Filament\Widgets\MainKpisWidget;
use App\Filament\Widgets\MonthlyChartWidget;
use App\Filament\Widgets\MonthlyPerformanceWidget;
use App\Filament\Widgets\NotificationsRecentesWidget;
use App\Filament\Widgets\RealTimeActivityWidget;
use App\Filament\Widgets\RemboursementsStatsWidget;
use App\Filament\Widgets\VentesUvStatsWidget;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\PalierCommission;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardWidgetsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_with_no_data(): void
    {
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('master');

        $response = $this->actingAs($user)->get('/master');

        $response->assertOk()
            ->assertSee('css/filament-compact.css', false);
    }

    /**
     * Régression : les widgets utilisés uniquement sur d'autres pages
     * (Remboursements, Ventes UV...) doivent rester résolubles par Livewire
     * même si le Dashboard n'en affiche qu'un sous-ensemble curé.
     */
    public function test_resource_pages_with_their_own_widgets_load(): void
    {
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('master');
        $this->actingAs($user);

        // Ces widgets ne sont pas dans App\Filament\Pages\Dashboard::getWidgets()
        // (ils vivent sur d'autres pages) : ils doivent quand même rester
        // résolubles par Livewire, ce qui exige que discoverWidgets() reste
        // actif sur le panel en plus de la liste curée du Dashboard.
        Livewire::test(RemboursementsStatsWidget::class)->assertOk();
        Livewire::test(VentesUvStatsWidget::class)->assertOk();
    }

    public function test_transaction_widgets_use_the_compact_layout(): void
    {
        Livewire::test(VentesUvStatsWidget::class)
            ->assertSeeHtml('compact-stat-card');
        Livewire::test(RemboursementsStatsWidget::class)
            ->assertSeeHtml('compact-stat-card');
        Livewire::test(CommissionKpisWidget::class)
            ->assertSeeHtml('compact-stat-card');
        Livewire::test(CommissionMonthlyChartWidget::class)
            ->assertSeeHtml('max-height: 200px');
    }

    public function test_notification_widgets_use_the_project_alignment(): void
    {
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('master');

        $columnSpan = (fn () => $this->columnSpan)->call(new NotificationsRecentesWidget);

        $this->assertSame('full', $columnSpan);

        $this->actingAs($user)
            ->get('/master/notifications')
            ->assertOk()
            ->assertSee('md:grid-cols-2', false)
            ->assertSee('xl:grid-cols-4', false);
    }

    public function test_dashboard_loads_with_seeded_data(): void
    {
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('master');

        $fournisseur = Fournisseur::factory()->create();

        Approvisionnement::create([
            'user_id' => $user->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 5_000_000,
            'date_approvisionnement' => now(),
        ]);

        $agent = Agent::factory()->create(['status' => 'active']);

        $uvTransaction = UvTransaction::create([
            'agent_id' => $agent->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 1_500_000,
            'transaction_type' => 'distribution',
            'transaction_date' => now(),
            'status' => 'completed',
        ]);

        Remboursement::factory()->create([
            'agent_id' => $agent->id,
            'user_id' => $user->id,
            'amount' => 200_000,
            'payment_date' => now(),
            'status' => 'completed',
        ]);

        PalierCommission::create([
            'seuil_min' => 1_000_000,
            'seuil_max' => 1_999_999,
            'commission' => 5000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/master');
        $response->assertOk();

        $this->actingAs($user);

        Livewire::test(MainKpisWidget::class)
            ->assertSee('Solde UV Disponible')
            ->assertSee('Ventes aujourd\'hui')
            ->assertSee('Commission prévisionnelle');

        Livewire::test(RealTimeActivityWidget::class)
            ->assertSee('Activité récente')
            ->assertSee($uvTransaction->reference);

        Livewire::test(CaChartWidget::class)
            ->assertSee('Chiffre d\'affaires');

        Livewire::test(MonthlyPerformanceWidget::class)
            ->assertSee('Performance mensuelle');

        Livewire::test(MonthlyChartWidget::class)
            ->assertSee('Évolution du CA');
    }
}
