<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Commissions;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileCommissionsTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['view_commissions', 'create_commissions', 'export_commissions'];

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

    private function commission(string $date, float $volume = 1200000, float $amount = 6360): Commission
    {
        return Commission::create([
            'is_global' => true,
            'date_commission' => $date,
            'volume_total_jour' => $volume,
            'commission_globale' => $amount,
            'montant_vente' => $volume,
            'montant_commission' => $amount,
            'nombre_transactions' => 7,
            'seuil_min' => 1000000,
            'seuil_max' => 1499999,
        ]);
    }

    public function test_commission_list_shows_global_commissions_and_the_scale(): void
    {
        $c = $this->commission(today()->toDateString());

        Livewire::test(Commissions\Index::class)
            ->assertSee('Commission prévisionnelle')->assertSee('7 ventes')->assertSee('Barème');

        $this->get(route('m.commissions.show', $c))->assertOk()->assertSee('Palier appliqué')->assertSee('7');
    }

    public function test_list_can_be_filtered_by_period(): void
    {
        $this->commission(today()->toDateString());
        $this->commission(today()->subMonths(3)->toDateString(), 900000, 4000);

        Livewire::test(Commissions\Index::class)->set('period', 'month')->assertSee('1 journée')
            ->set('period', 'all')->assertSee('2 journées');
    }

    public function test_recalculation_validates_its_dates(): void
    {
        Livewire::test(Commissions\Index::class)
            ->set('recalcMode', 'periode')->set('recalcStart', '2026-10-10')->set('recalcEnd', '2026-10-01')
            ->call('recalculate')->assertHasErrors('recalcEnd');
    }

    public function test_recalculating_a_date_runs_the_commission_service_without_error(): void
    {
        Livewire::test(Commissions\Index::class)
            ->set('recalcMode', 'date')->set('recalcDate', today()->toDateString())
            ->call('recalculate')->assertHasNoErrors()->assertDispatched('m-toast');
    }

    public function test_exports_are_available_with_the_export_permission(): void
    {
        $this->commission(today()->toDateString());

        $pdf = $this->get(route('m.exports.commissions', ['pdf', 'p' => 'month']));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->get(route('m.exports.commissions', ['xlsx', 'p' => 'all']))->assertOk();
    }

    public function test_commission_screens_require_permissions(): void
    {
        $other = User::factory()->create();
        $other->assignRole(Role::firstOrCreate(['name' => 'commercial', 'guard_name' => 'web']));
        $this->actingAs($other);

        Livewire::test(Commissions\Index::class)->assertForbidden();
    }
}
