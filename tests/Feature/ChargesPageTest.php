<?php

namespace Tests\Feature;

use App\Filament\Pages\ChargesPage;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Commission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChargesPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ChargeCategorie $category;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 10, 0));

        foreach (['view_charges', 'create_charges', 'edit_charges', 'delete_charges', 'export_charges'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_charges', 'create_charges', 'edit_charges', 'delete_charges', 'export_charges']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('master');
        $this->actingAs($this->admin);

        $this->category = ChargeCategorie::create(['code' => 'internet', 'name' => 'Internet']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function chargeFixture(array $overrides = []): Charge
    {
        return Charge::create(array_merge([
            'category_id' => $this->category->id,
            'libelle' => 'Facture Internet',
            'montant' => 30000,
            'date_charge' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_page_renders_successfully(): void
    {
        Livewire::test(ChargesPage::class)->assertOk();
    }

    public function test_adding_a_charge_updates_the_table_and_the_totals(): void
    {
        Livewire::test(ChargesPage::class)
            ->callTableAction('add', data: [
                'category_id' => $this->category->id,
                'libelle' => 'Loyer bureau',
                'montant' => 150000,
                'date_charge' => now()->toDateString(),
            ])
            ->assertOk();

        $this->assertDatabaseHas('charges', [
            'libelle' => 'Loyer bureau',
            'montant' => 150000.00,
            'created_by' => $this->admin->id,
        ]);

        $component = Livewire::test(ChargesPage::class);
        $component->assertSee('Loyer bureau')->assertSee('150 000');

        $summary = $component->instance()->getSummary();
        $this->assertEqualsWithDelta(150000, $summary['charges'], 0.01);
        $this->assertEqualsWithDelta(-150000, $summary['net'], 0.01);
    }

    public function test_editing_a_charge_recomputes_totals(): void
    {
        $charge = $this->chargeFixture(['montant' => 30000]);

        Livewire::test(ChargesPage::class)
            ->callTableAction('edit', $charge, data: [
                'category_id' => $this->category->id,
                'libelle' => $charge->libelle,
                'montant' => 50000,
                'date_charge' => now()->toDateString(),
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(50000.0, (float) $charge->fresh()->montant, 0.01);
        $this->assertSame($this->admin->id, $charge->fresh()->updated_by);

        $summary = Livewire::test(ChargesPage::class)->instance()->getSummary();
        $this->assertEqualsWithDelta(50000, $summary['charges'], 0.01);
    }

    public function test_deleting_a_charge_recomputes_totals(): void
    {
        $charge = $this->chargeFixture(['montant' => 40000]);

        Livewire::test(ChargesPage::class)
            ->callTableAction('delete', $charge)
            ->assertOk();

        $this->assertSoftDeleted('charges', ['id' => $charge->id]);

        $summary = Livewire::test(ChargesPage::class)->instance()->getSummary();
        $this->assertEqualsWithDelta(0, $summary['charges'], 0.01);
    }

    public function test_default_period_is_the_current_month(): void
    {
        $summary = Livewire::test(ChargesPage::class)->instance()->getSummary();

        $this->assertSame('Mois en cours : du 01/06/2026 au 30/06/2026', $summary['period_label']);
    }

    public function test_today_filter_excludes_a_charge_from_yesterday(): void
    {
        $this->chargeFixture(['libelle' => 'Hier', 'date_charge' => Carbon::yesterday()->toDateString()]);
        $this->chargeFixture(['libelle' => 'Aujourd hui', 'date_charge' => now()->toDateString()]);

        $component = Livewire::test(ChargesPage::class)
            ->filterTable('period', ['value' => 'today']);

        $component->assertSee('Aujourd hui')->assertDontSee('Hier');
    }

    public function test_period_filter_also_recomputes_the_summary_tiles(): void
    {
        Commission::create([
            'agent_id' => null,
            'is_global' => true,
            'date_commission' => Carbon::yesterday()->toDateString(),
            'volume_total_jour' => 0,
            'commission_globale' => 99999,
            'volume_agent' => 0,
            'part_agent' => 0,
            'pourcentage_contribution' => 100,
            'nombre_transactions' => 0,
            'montant_vente' => 0,
            'montant_commission' => 99999,
            'seuil_min' => 0,
            'seuil_max' => 0,
            'statut' => Commission::STATUT_CALCULATED,
        ]);

        $component = Livewire::test(ChargesPage::class)
            ->filterTable('period', ['value' => 'today']);

        $summary = $component->instance()->getSummary();
        // La commission d'hier ne doit pas apparaître dans le résumé "Aujourd'hui".
        $this->assertEqualsWithDelta(0, $summary['revenue'], 0.01);
    }

    public function test_search_by_libelle(): void
    {
        $this->chargeFixture(['libelle' => 'Facture EDF']);
        $this->chargeFixture(['libelle' => 'Achat papeterie']);

        $component = Livewire::test(ChargesPage::class)->searchTable('EDF');

        $component->assertSee('Facture EDF')->assertDontSee('Achat papeterie');
    }

    public function test_category_filter_narrows_the_table_but_not_the_summary_tiles(): void
    {
        $autre = ChargeCategorie::create(['code' => 'transport', 'name' => 'Transport']);
        $this->chargeFixture(['libelle' => 'Internet du mois', 'montant' => 30000, 'category_id' => $this->category->id]);
        $this->chargeFixture(['libelle' => 'Essence', 'montant' => 20000, 'category_id' => $autre->id]);

        $unfiltered = Livewire::test(ChargesPage::class)->instance()->getSummary();
        $this->assertEqualsWithDelta(50000, $unfiltered['charges'], 0.01);

        $component = Livewire::test(ChargesPage::class)
            ->filterTable('category_id', $this->category->id);

        $component->assertSee('Internet du mois')->assertDontSee('Essence');

        // Le filtre catégorie ne doit jamais réduire les tuiles de résumé.
        $filteredSummary = $component->instance()->getSummary();
        $this->assertEqualsWithDelta(50000, $filteredSummary['charges'], 0.01);
    }

    public function test_amount_is_displayed_in_fcfa_format(): void
    {
        $this->chargeFixture(['montant' => 1000000]);

        Livewire::test(ChargesPage::class)->assertSee('1 000 000 FCFA');
    }

    public function test_empty_state_is_shown_when_no_charge_exists(): void
    {
        Livewire::test(ChargesPage::class)
            ->assertSee('Aucune charge enregistrée')
            ->assertSee('Ajoutez votre première charge d\'exploitation pour suivre votre revenu net.');
    }

    public function test_user_without_view_permission_cannot_access_the_page(): void
    {
        $this->admin->syncRoles([]);
        $this->admin->syncPermissions([]);

        $this->assertFalse(ChargesPage::canAccess());
    }

    public function test_user_without_create_permission_does_not_see_the_add_action(): void
    {
        $limited = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'viewer_charges', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_charges']);
        $limited->assignRole('viewer_charges');
        $this->actingAs($limited);

        Livewire::test(ChargesPage::class)->assertTableActionHidden('add');

        // Vérification backend : refusé même si l'action était appelée directement.
        $this->assertFalse($limited->can('create', Charge::class));
    }

    public function test_user_without_delete_permission_cannot_delete_a_charge(): void
    {
        $charge = $this->chargeFixture();

        $limited = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'no_delete_charges', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_charges', 'edit_charges']);
        $limited->assignRole('no_delete_charges');
        $this->actingAs($limited);

        Livewire::test(ChargesPage::class)->assertTableActionHidden('delete', $charge);
        $this->assertFalse($limited->can('delete', $charge));
    }

    public function test_export_actions_are_hidden_without_export_permission(): void
    {
        $limited = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'no_export_charges', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_charges']);
        $limited->assignRole('no_export_charges');
        $this->actingAs($limited);

        Livewire::test(ChargesPage::class)
            ->assertTableActionHidden('exportExcel')
            ->assertTableActionHidden('exportCsv')
            ->assertTableActionHidden('exportPdf');
    }
}
