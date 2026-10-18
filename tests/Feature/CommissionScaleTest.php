<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CommissionScaleUpdater;
use App\Services\CommissionService;
use App\Support\CommissionScale;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Barème des commissions fourni aux masters (14 paliers de 1 000 000 à 74 999 999 FCFA) : les nouveaux masters le reçoivent à
 * leur création, les masters existants sont mis à jour sans chevauchement avec l'ancien barème.
 */
class CommissionScaleTest extends TestCase
{
    use RefreshDatabase;

    /** Le barème exact fourni : [min, max, commission]. */
    private const EXPECTED = [
        [1000000, 1499999, 4135], [1500000, 1999999, 5470], [2000000, 2499999, 6630], [2500000, 2999999, 7020],
        [3000000, 3999999, 8935], [4000000, 4999999, 12445], [5000000, 7499900, 17550], [7500000, 9999999, 19840],
        [10000000, 14999999, 26755], [15000000, 19999999, 34270], [20000000, 29999999, 39080], [30000000, 44999999, 67525],
        [45000000, 59999999, 72845], [60000000, 74999999, 74925],
    ];

    /** Compte rendu attendu pour un master au barème d'avant : 7 paliers ont déjà les mêmes seuils, 7 sont à créer, 3 à désactiver. */
    private const OLD_SCALE_REPORT = ['created' => 7, 'updated' => 7, 'deactivated' => 3];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
    }

    /** Ancien barème d'un master (9 paliers) + un palier personnalisé : l'état des masters avant la mise à jour. */
    private function tenantWithOldScale(string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $now = now();
        $old = [
            [1000000, 1499999, 6360], [1500000, 1999999, 8420], [2000000, 2499999, 10200], [2500000, 2999999, 11700],
            [3000000, 3999999, 14895], [4000000, 4999999, 19150], [5000000, 9999999, 31915], [10000000, 19999999, 52725],
            [20000000, 29999999, 60125], [30000000, 99999999, 90000], // dernier : palier personnalisé du master
        ];

        foreach ($old as [$min, $max, $commission]) {
            DB::table('paliers_commission')->insert(['tenant_id' => $tenant->id, 'seuil_min' => $min, 'seuil_max' => $max, 'commission' => $commission, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        return $tenant;
    }

    /** @return list<array{int, int, int}> paliers actifs d'une entreprise, triés */
    private function activeScale(Tenant $tenant): array
    {
        return DB::table('paliers_commission')->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('seuil_min')->get()
            ->map(fn ($row) => [(int) $row->seuil_min, (int) $row->seuil_max, (int) $row->commission])->all();
    }

    private function rowsOf(Tenant $tenant): array
    {
        return DB::table('paliers_commission')->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray();
    }

    private function counts(array $reportForTenant): array
    {
        return collect($reportForTenant)->only(['created', 'updated', 'deactivated'])->all();
    }

    private function commissionFor(Tenant $tenant, float $volume): float
    {
        return TenantContext::runAs($tenant->id, fn () => app(CommissionService::class)->calculerCommissionGlobale($volume));
    }

    public function test_the_scale_constant_is_exactly_the_provided_schedule(): void
    {
        $this->assertCount(14, CommissionScale::PALIERS);
        $this->assertSame(self::EXPECTED, array_map(fn ($p) => [$p['seuil_min'], $p['seuil_max'], $p['commission']], CommissionScale::PALIERS));
    }

    public function test_a_new_master_receives_the_new_scale_at_creation(): void
    {
        $tenant = Tenant::create(['name' => 'Nouveau master']);
        TenantContext::runAs($tenant->id, fn () => app(TenantProvisioner::class)->seedReferenceData());

        $this->assertSame(self::EXPECTED, $this->activeScale($tenant));
    }

    public function test_existing_masters_are_updated_and_old_tiers_are_deactivated_not_deleted(): void
    {
        $a = $this->tenantWithOldScale('Master A');
        $b = $this->tenantWithOldScale('Master B');

        $report = app(CommissionScaleUpdater::class)->apply();

        foreach ([$a, $b] as $tenant) {
            $this->assertSame(self::EXPECTED, $this->activeScale($tenant), 'exactement le nouveau barème, rien d\'autre d\'actif');
            $this->assertSame(self::OLD_SCALE_REPORT, $this->counts($report[$tenant->id]));
        }

        // Rien n'est supprimé : les anciens paliers qui ne font pas partie du barème sont conservés, désactivés
        // (dont le palier personnalisé du master).
        $this->assertSame(
            [[5000000, 9999999, 31915], [10000000, 19999999, 52725], [30000000, 99999999, 90000]],
            DB::table('paliers_commission')->where('tenant_id', $a->id)->where('is_active', false)->orderBy('seuil_min')->get()
                ->map(fn ($r) => [(int) $r->seuil_min, (int) $r->seuil_max, (int) $r->commission])->all(),
        );
        $this->assertSame(17, DB::table('paliers_commission')->where('tenant_id', $a->id)->count(), '14 paliers actifs + 3 anciens conservés');
    }

    public function test_the_update_is_idempotent_and_a_dry_run_changes_nothing(): void
    {
        $tenant = $this->tenantWithOldScale('Master dry-run');
        $before = $this->rowsOf($tenant);

        $preview = app(CommissionScaleUpdater::class)->apply($tenant->id, dryRun: true);

        $this->assertSame(self::OLD_SCALE_REPORT, $this->counts($preview[$tenant->id]));
        $this->assertEquals($before, $this->rowsOf($tenant), 'la simulation ne modifie rien');

        app(CommissionScaleUpdater::class)->apply($tenant->id);
        $after = $this->rowsOf($tenant);

        $second = app(CommissionScaleUpdater::class)->apply($tenant->id);
        $this->assertSame(['created' => 0, 'updated' => 0, 'deactivated' => 0], $this->counts($second[$tenant->id]));
        $this->assertEquals($after, $this->rowsOf($tenant), 'un second passage ne change plus rien');
    }

    public function test_a_single_master_can_be_updated_without_touching_the_others(): void
    {
        $a = $this->tenantWithOldScale('Master A');
        $b = $this->tenantWithOldScale('Master B');
        $bBefore = $this->rowsOf($b);

        app(CommissionScaleUpdater::class)->apply($a->id);

        $this->assertSame(self::EXPECTED, $this->activeScale($a));
        $this->assertEquals($bBefore, $this->rowsOf($b), 'l\'autre master n\'a pas bougé');
    }

    public function test_commissions_follow_the_new_scale_at_every_boundary(): void
    {
        $tenant = $this->tenantWithOldScale('Master calcul');
        app(CommissionScaleUpdater::class)->apply($tenant->id);

        foreach ([
            [999999, 0.0], // sous le premier palier : aucune commission
            [1000000, 4135.0], [1499999, 4135.0], [1500000, 5470.0], [2999999, 7020.0], [3000000, 8935.0],
            [4999999, 12445.0], [5000000, 17550.0], [7499900, 17550.0], [7500000, 19840.0], [9999999, 19840.0],
            [10000000, 26755.0], [29999999, 39080.0], [30000000, 67525.0], [59999999, 72845.0],
            [60000000, 74925.0], [74999999, 74925.0],
            [75000000, 74925.0], [200000000, 74925.0], // au-delà du dernier palier : la commission du dernier palier
        ] as [$volume, $expected]) {
            $this->assertSame($expected, $this->commissionFor($tenant, $volume), number_format($volume, 0, ',', ' ').' FCFA');
        }
    }

    public function test_the_scale_has_one_gap_between_7_499_900_and_7_500_000_as_provided(): void
    {
        // Le barème fourni s'arrête à 7 499 900 (et non 7 499 999) : un volume de 7 499 901 à 7 499 999 FCFA ne tombe dans aucun
        // palier et ne génère donc aucune commission. Ce test DOCUMENTE cet écart ; s'il est corrigé (7 499 999), le mettre à jour.
        $tenant = $this->tenantWithOldScale('Master écart');
        app(CommissionScaleUpdater::class)->apply($tenant->id);

        $this->assertSame(0.0, $this->commissionFor($tenant, 7499950));
        $this->assertSame(17550.0, $this->commissionFor($tenant, 7499900));
        $this->assertSame(19840.0, $this->commissionFor($tenant, 7500000));
    }

    public function test_the_artisan_command_simulates_then_applies(): void
    {
        $tenant = $this->tenantWithOldScale('Master commande');

        $this->artisan('commissions:apply-scale', ['--dry-run' => true, '--tenant' => $tenant->id])
            ->expectsOutputToContain('SIMULATION')
            ->assertExitCode(0);
        $this->assertNotSame(self::EXPECTED, $this->activeScale($tenant));

        $this->artisan('commissions:apply-scale', ['--tenant' => $tenant->id])->assertExitCode(0);
        $this->assertSame(self::EXPECTED, $this->activeScale($tenant));
    }

    public function test_the_bareme_screen_reads_the_masters_scale_instead_of_a_copy(): void
    {
        $tenant = $this->tenantWithOldScale('Master écran');
        app(CommissionScaleUpdater::class)->apply($tenant->id);

        $html = TenantContext::runAs($tenant->id, fn () => view('filament.modals.bareme-commissions')->render());

        $this->assertStringContainsString('4 135', $html);
        $this->assertStringContainsString('74 925', $html);
        $this->assertStringContainsString('1 000 000 — 1 499 999', $html);
        $this->assertStringNotContainsString('6 360', $html, 'plus aucune trace de l\'ancien barème');
        $this->assertStringContainsString('inférieurs à 1 000 000 FCFA', $html);
        $this->assertStringContainsString('Au-delà de 74 999 999 FCFA', $html);

        // Plus aucune copie écrite en dur de l'ancien barème dans les vues.
        foreach (['resources/views/filament/modals/bareme-commissions.blade.php', 'resources/views/exports/commissions-pdf.blade.php'] as $view) {
            $source = file_get_contents(base_path($view));
            $this->assertStringNotContainsString('31 915', $source, $view);
            $this->assertStringNotContainsString('6 360', $source, $view);
        }
    }

    public function test_the_update_only_writes_to_the_tiers_table(): void
    {
        $tenant = $this->tenantWithOldScale('Master garde-fou');
        $users = User::withoutGlobalScopes()->count();
        $tenants = Tenant::count();

        app(CommissionScaleUpdater::class)->apply($tenant->id);

        $this->assertSame($users, User::withoutGlobalScopes()->count());
        $this->assertSame($tenants, Tenant::count());
    }
}
