<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\UvDistributionService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Commandes Artisan : chacune ne traite que le tenant pour lequel elle s'exécute (V2), les commandes de
 * démonstration sont inutilisables dangereusement (V3), les fichiers générés sont séparés par tenant (V4, V5)
 * et aucun fichier ne change de propriétaire (V5).
 */
class TenantCommandsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $masterA;

    private User $masterB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        Carbon::setTestNow('2026-10-06 10:00:00');

        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
        $this->masterA = $this->master($this->a);
        $this->masterB = $this->master($this->b);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::forceConsole(null);
        parent::tearDown();
    }

    private function master(Tenant $tenant): User
    {
        return TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
    }

    /** Un approvisionnement puis une vente dans le tenant ; retourne l'agent. */
    private function activity(Tenant $tenant, User $master, int $supply, int $sale): Agent
    {
        $agent = TenantContext::runAs($tenant->id, function () use ($master, $supply) {
            $fournisseur = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true]);
            Approvisionnement::create(['user_id' => $master->id, 'fournisseur_id' => $fournisseur->id, 'amount' => $supply, 'date_approvisionnement' => now()]);

            return Agent::factory()->create(['status' => 'active', 'debt_limit' => 9_000_000]);
        });

        $this->actingAs($master);
        (new UvDistributionService)->distribute($agent->fresh(), $sale);
        $this->app['auth']->forgetGuards();

        return $agent;
    }

    private function asSystem(callable $fn): mixed
    {
        $this->app['auth']->forgetGuards();
        TenantContext::forceConsole(true);

        try {
            return $fn();
        } finally {
            TenantContext::forceConsole(null);
        }
    }

    // ============================================================ V2 : backfill du solde historique

    public function test_a_backfill_run_for_tenant_a_never_modifies_tenant_b(): void
    {
        $this->activity($this->a, $this->masterA, 1_000_000, 200_000);
        $this->activity($this->b, $this->masterB, 5_000_000, 100_000);

        // on corrompt volontairement les soldes historiques des DEUX tenants
        DB::table('approvisionnements')->update(['solde_uv_avant' => -1, 'solde_uv_apres' => -1]);
        DB::table('uv_transactions')->update(['solde_uv_avant' => -1, 'solde_uv_apres' => -1]);

        TenantContext::runAs($this->a->id, fn () => Artisan::call('master-solde:backfill', ['--force' => true]));

        $apres = fn (string $table, Tenant $t) => DB::table($table)->where('tenant_id', $t->id)->orderBy('id')->pluck('solde_uv_apres')->map(fn ($v) => (float) $v)->all();

        $this->assertSame([1_000_000.0], $apres('approvisionnements', $this->a));
        $this->assertSame([800_000.0], $apres('uv_transactions', $this->a), 'A est reconstruit sur SA seule chronologie.');
        $this->assertSame([-1.0], $apres('approvisionnements', $this->b), 'B ne doit pas être touché.');
        $this->assertSame([-1.0], $apres('uv_transactions', $this->b));
    }

    public function test_the_scheduled_backfill_rebuilds_each_tenant_on_its_own_chronology(): void
    {
        $this->activity($this->a, $this->masterA, 1_000_000, 200_000);
        $this->activity($this->b, $this->masterB, 5_000_000, 100_000);
        DB::table('approvisionnements')->update(['solde_uv_apres' => -1]);
        DB::table('uv_transactions')->update(['solde_uv_apres' => -1]);

        $this->asSystem(fn () => Artisan::call('master-solde:backfill', ['--force' => true]));

        $apres = fn (string $table, Tenant $t) => DB::table($table)->where('tenant_id', $t->id)->pluck('solde_uv_apres')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([800_000.0], $apres('uv_transactions', $this->a));
        $this->assertSame([4_900_000.0], $apres('uv_transactions', $this->b), 'Le solde de B ne contient rien venant de A.');
    }

    // ============================================================ V2 : contrôle d'intégrité

    public function test_the_integrity_check_with_fix_for_tenant_a_never_modifies_tenant_b(): void
    {
        $agentA = $this->activity($this->a, $this->masterA, 1_000_000, 200_000);
        $agentB = $this->activity($this->b, $this->masterB, 5_000_000, 100_000);
        DB::table('agent_soldes')->update(['total_uv_distribues' => 1]); // incohérence chez les deux

        TenantContext::runAs($this->a->id, fn () => Artisan::call('security:verify-integrity', ['--fix' => true]));

        $total = fn (Agent $agent) => (float) DB::table('agent_soldes')->where('agent_id', $agent->id)->value('total_uv_distribues');
        $this->assertSame(200_000.0, $total($agentA), 'A est corrigé.');
        $this->assertSame(1.0, $total($agentB), 'B garde son incohérence : rien n\'a été écrit chez lui.');

        $this->asSystem(fn () => Artisan::call('security:verify-integrity', ['--fix' => true]));
        $this->assertSame(100_000.0, $total($agentB), 'Le passage planifié corrige B dans son propre contexte.');
        $this->assertSame(200_000.0, $total($agentA));
    }

    public function test_the_integrity_fix_targets_the_agent_and_not_the_balance_row_id(): void
    {
        $agent = $this->activity($this->a, $this->masterA, 1_000_000, 200_000);

        // En production les identifiants dérivent : on recrée la ligne de solde pour que son id ≠ id de l'agent.
        DB::table('agent_soldes')->where('agent_id', $agent->id)->delete();
        DB::table('agent_soldes')->insert([
            'agent_id' => $agent->id, 'tenant_id' => $this->a->id, 'total_uv_distribues' => 1,
            'total_rembourse' => 0, 'solde_restant' => 200_000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotSame($agent->id, (int) DB::table('agent_soldes')->where('agent_id', $agent->id)->value('id'));

        TenantContext::runAs($this->a->id, fn () => Artisan::call('security:verify-integrity', ['--fix' => true]));

        $this->assertSame(200_000.0, (float) DB::table('agent_soldes')->where('agent_id', $agent->id)->value('total_uv_distribues'),
            'La correction vise l\'agent concerné, pas l\'identifiant de la ligne de solde.');
    }

    // ============================================================ V3 : commandes de démonstration

    private function demoData(): void
    {
        TenantContext::runAs(1, fn () => Agent::factory()->count(2)->create());
    }

    public function test_demo_commands_refuse_production_and_delete_nothing(): void
    {
        $this->demoData();
        config(['app.url' => 'http://localhost']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame(1, Artisan::call('demo:clear', ['--tenant' => 1, '--force' => true]));
        $this->assertStringContainsString('réservé au développement', Artisan::output());
        $this->assertSame(1, Artisan::call('demo:setup', ['--tenant' => 1]));
        $this->assertSame(1, Artisan::call('demo:setup', ['--tenant' => 1, '--fresh' => true]));

        $this->assertSame(2, Agent::withoutGlobalScopes()->where('tenant_id', 1)->count());
    }

    public function test_demo_commands_also_refuse_staging_and_a_deployed_url(): void
    {
        $this->demoData();

        $this->app->detectEnvironment(fn () => 'staging');
        $this->assertSame(1, Artisan::call('demo:clear', ['--tenant' => 1, '--force' => true]));

        // APP_ENV mal réglé sur « local » sur un vrai serveur : l'adresse publique les arrête
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.url' => 'https://uvsystem.pro']);
        $this->assertSame(1, Artisan::call('demo:clear', ['--tenant' => 1, '--force' => true]));
        $this->assertStringContainsString('ne ressemble pas à un poste de développement', Artisan::output());

        $this->assertSame(2, Agent::withoutGlobalScopes()->where('tenant_id', 1)->count());
    }

    public function test_demo_commands_refuse_as_soon_as_several_tenants_exist(): void
    {
        // setUp a créé les tenants A et B en plus du tenant d'origine : plusieurs sociétés
        config(['app.url' => 'http://localhost']);
        $this->activity($this->a, $this->masterA, 1_000_000, 100_000);

        $this->assertSame(1, Artisan::call('demo:clear', ['--tenant' => $this->a->id, '--force' => true]));
        $this->assertStringContainsString('Plusieurs tenants', Artisan::output());
        $this->assertSame(1, Artisan::call('demo:setup', ['--tenant' => $this->a->id]));

        $this->assertSame(1, Agent::withoutGlobalScopes()->where('tenant_id', $this->a->id)->count());
        $this->assertSame(1, UvTransaction::withoutGlobalScopes()->where('tenant_id', $this->a->id)->count());
    }

    public function test_demo_commands_require_an_explicit_tenant(): void
    {
        $this->demoData();
        config(['app.url' => 'http://localhost']);

        $this->assertSame(1, Artisan::call('demo:clear', ['--force' => true]));
        $this->assertStringContainsString('--tenant=ID obligatoire', Artisan::output());
        $this->assertSame(2, Agent::withoutGlobalScopes()->where('tenant_id', 1)->count());
    }

    public function test_demo_clear_in_a_safe_environment_only_clears_the_requested_tenant(): void
    {
        // environnement sûr : un seul tenant (on retire A et B créés par setUp)
        DB::table('users')->whereIn('tenant_id', [$this->a->id, $this->b->id])->delete();
        $this->a->delete();
        $this->b->delete();
        config(['app.url' => 'http://localhost']);
        $this->demoData();

        $this->assertSame(0, Artisan::call('demo:clear', ['--tenant' => 1, '--force' => true]));
        $this->assertSame(0, Agent::withoutGlobalScopes()->count());
    }

    // ============================================================ V4 : rapports isolés par tenant

    public function test_daily_reports_are_written_in_each_tenants_folder_and_never_overwrite_each_other(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->activity($this->a, $this->masterA, 1_000_000, 200_000);
        $this->activity($this->b, $this->masterB, 5_000_000, 700_000);

        $day = now()->toDateString();
        $file = fn (Tenant $t) => "tenants/{$t->id}/reports/rapport_quotidien_{$day}.pdf";

        TenantContext::runAs($this->a->id, fn () => Artisan::call('uv:daily-report', ['--date' => $day, '--save' => true]));
        $hashA = md5(Storage::disk('local')->get($file($this->a)));

        TenantContext::runAs($this->b->id, fn () => Artisan::call('uv:daily-report', ['--date' => $day, '--save' => true]));

        Storage::disk('local')->assertExists($file($this->a));
        Storage::disk('local')->assertExists($file($this->b));
        $this->assertSame($hashA, md5(Storage::disk('local')->get($file($this->a))), 'Le rapport de A n\'a pas été écrasé par celui de B.');
        $this->assertNotSame($hashA, md5(Storage::disk('local')->get($file($this->b))));
        $this->assertSame([], Storage::disk('local')->files('reports'), 'Plus aucun rapport dans un dossier commun.');
    }

    public function test_scheduled_reports_also_use_the_tenant_folder(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->activity($this->a, $this->masterA, 1_000_000, 200_000);
        $this->activity($this->b, $this->masterB, 5_000_000, 700_000);

        $this->asSystem(fn () => Artisan::call('reports:generate', ['type' => 'daily', '--format' => 'pdf']));

        $this->assertNotEmpty(Storage::disk('local')->files("tenants/{$this->a->id}/reports"));
        $this->assertNotEmpty(Storage::disk('local')->files("tenants/{$this->b->id}/reports"));
        $this->assertSame([], Storage::disk('local')->files('reports'));
    }

    // ============================================================ V5 : analytics:show

    public function test_analytics_exports_are_separated_by_tenant_and_aggregate_only_that_tenant(): void
    {
        Storage::fake('local');
        $this->activity($this->a, $this->masterA, 4_000_000, 300_000);
        $this->activity($this->b, $this->masterB, 9_000_000, 700_000);

        $this->asSystem(fn () => Artisan::call('analytics:show', ['type' => 'kpis', '--export' => true]));

        $read = function (Tenant $t) {
            $files = Storage::disk('local')->files("tenants/{$t->id}/exports");
            $this->assertCount(1, $files, "Un export JSON pour le tenant #{$t->id}.");

            return Storage::disk('local')->get($files[0]);
        };

        $jsonA = $read($this->a);
        $jsonB = $read($this->b);
        $this->assertNotSame($jsonA, $jsonB, 'Chaque export ne contient que les chiffres de son tenant.');
        $this->assertSame([], Storage::disk('local')->files('exports'), 'Aucun export commun à plusieurs sociétés.');
    }

    // ============================================================ V5 : relocate-files ne change jamais de propriétaire

    public function test_relocating_legacy_files_never_moves_a_file_to_the_wrong_owner(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $origin = Tenant::query()->orderBy('id')->first(); // tenant d'origine (créé par la migration)
        $before = $origin->created_at->getTimestamp() - 86_400;

        // exports hérités : un ancien (antérieur au multi-tenant), un récent (propriétaire non démontrable)
        $disk->put('exports/old.xlsx', 'ancien');
        touch($disk->path('exports/old.xlsx'), $before);
        $disk->put('exports/recent.xlsx', 'récent');
        // destination déjà occupée : jamais écrasée
        $disk->put('exports/dup.xlsx', 'ancien doublon');
        touch($disk->path('exports/dup.xlsx'), $before);
        $disk->put("tenants/{$origin->id}/exports/dup.xlsx", 'déjà là');

        // justificatifs hérités : un par tenant d'origine, un référencé par la charge d'un AUTRE tenant
        $disk->put('charges/origine.pdf', 'pdf-origine');
        $disk->put('charges/autre.pdf', 'pdf-autre');
        $chargeOrigin = TenantContext::runAs($origin->id, function () {
            $user = User::factory()->create();
            $cat = ChargeCategorie::create(['code' => 'o', 'name' => 'O', 'is_active' => true]);

            return Charge::create(['category_id' => $cat->id, 'libelle' => 'o', 'montant' => 1, 'date_charge' => today(), 'created_by' => $user->id, 'justificatif_path' => 'charges/origine.pdf']);
        });
        $chargeOther = TenantContext::runAs($this->b->id, function () {
            $cat = ChargeCategorie::create(['code' => 'b', 'name' => 'B', 'is_active' => true]);

            return Charge::create(['category_id' => $cat->id, 'libelle' => 'b', 'montant' => 1, 'date_charge' => today(), 'created_by' => $this->masterB->id, 'justificatif_path' => 'charges/autre.pdf']);
        });

        $this->assertSame(0, Artisan::call('tenancy:relocate-files', ['--apply' => true]));

        $disk->assertExists("tenants/{$origin->id}/exports/old.xlsx");
        $disk->assertMissing('exports/old.xlsx');
        $disk->assertExists('exports/recent.xlsx');                                  // non démontrable : reste en place
        $this->assertSame('déjà là', $disk->get("tenants/{$origin->id}/exports/dup.xlsx")); // jamais écrasé
        $disk->assertExists('exports/dup.xlsx');

        $disk->assertExists("tenants/{$origin->id}/charges/origine.pdf");
        $this->assertSame("tenants/{$origin->id}/charges/origine.pdf", Charge::withoutGlobalScopes()->find($chargeOrigin->id)->justificatif_path);

        $disk->assertExists('charges/autre.pdf');                                    // fichier d'un autre tenant : intact
        $this->assertSame('charges/autre.pdf', Charge::withoutGlobalScopes()->find($chargeOther->id)->justificatif_path);
        $disk->assertMissing("tenants/{$origin->id}/charges/autre.pdf");
    }

    public function test_relocating_for_a_tenant_other_than_the_origin_is_refused(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/old.xlsx', 'x');

        $this->assertSame(1, Artisan::call('tenancy:relocate-files', ['tenant' => $this->b->id, '--apply' => true]));
        $this->assertStringContainsString("appartiennent au tenant d'origine", Artisan::output());
        Storage::disk('local')->assertExists('exports/old.xlsx');
        Storage::disk('local')->assertMissing("tenants/{$this->b->id}/exports/old.xlsx");
    }

    public function test_legacy_justificatif_paths_are_only_served_to_the_origin_tenant(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('charges/legacy.pdf', 'pdf');
        $origin = Tenant::query()->orderBy('id')->first();

        $make = fn (Tenant $t) => TenantContext::runAs($t->id, function () {
            $cat = ChargeCategorie::create(['code' => 'x', 'name' => 'X', 'is_active' => true]);
            $u = User::factory()->create();
            $u->assignRole('master');

            return [Charge::create(['category_id' => $cat->id, 'libelle' => 'x', 'montant' => 1, 'date_charge' => today(), 'created_by' => $u->id, 'justificatif_path' => 'charges/legacy.pdf']), $u];
        });

        [$chargeOrigin, $userOrigin] = $make($origin);
        [$chargeB, $userB] = $make($this->b);

        $this->actingAs($userOrigin)->get("/charges/justificatif/{$chargeOrigin->id}/download")->assertOk();
        // un tenant qui n'est pas celui d'origine ne peut pas se faire servir un chemin « hérité »
        $this->actingAs($userB)->get("/charges/justificatif/{$chargeB->id}/download")->assertNotFound();
    }
}
