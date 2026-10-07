<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tenant;
use App\Tenancy\MigrationBlocked;
use App\Tenancy\MigrationPreflight;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contrôle préalable de la migration pour les tables héritées « clients* » : détecter, compter, vérifier les
 * relations, établir ce qui est démontrable, afficher l'ambigu, BLOQUER, exiger une validation humaine — et ne
 * JAMAIS attribuer, supprimer, archiver ni déplacer ces données.
 */
class MigrationPreflightTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Ces tests créent et défont des tables : sur MySQL/MariaDB un DDL valide implicitement la transaction de
        // test, donc tables et données persisteraient et pollueraient les tests suivants. SQLite annule le DDL.
        // Sur MariaDB, ce comportement est validé par la procédure manuelle décrite dans docs/SAAS-MULTITENANT.md.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL transactionnel requis (SQLite) : validé manuellement sur MariaDB.');
        }

        TenantContext::setFallback(null);
        Storage::fake('local');
    }

    /** Recrée la structure du module « clients » telle qu'elle existe dans la base locale (clés étrangères comprises). */
    private function createLegacyTables(): void
    {
        Schema::create('client_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agent_id')->constrained('agents');
            $t->foreignId('client_category_id')->constrained('client_categories');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('client_soldes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients');
            $t->decimal('solde_restant', 15, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('client_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients');
            $t->foreignId('agent_id')->nullable()->constrained('agents');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->decimal('amount', 15, 2);
            $t->timestamps();
        });
    }

    /**
     * Jeu de données : preuves nettes, preuves absentes et preuves contradictoires.
     *  - catégorie 1 : utilisée par des clients du tenant d'origine ; catégorie 2 : orpheline (aucune preuve)
     *  - client 1 : propre ; client 2 : une vente le relie à l'agent d'UN AUTRE tenant (contradiction)
     */
    private function legacyData(int $originTenant, int $otherTenant): void
    {
        $agent = fn (int $tenant) => DB::table('agents')->insertGetId(array_merge(
            Agent::factory()->make()->getAttributes(),
            ['tenant_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]
        ));
        $ts = ['created_at' => now(), 'updated_at' => now()];

        $agentOrigin = $agent($originTenant);
        $agentOther = $agent($otherTenant);
        $cat1 = DB::table('client_categories')->insertGetId(['name' => 'Cat 1'] + $ts);
        $cat2 = DB::table('client_categories')->insertGetId(['name' => 'Cat orpheline'] + $ts);
        $c1 = DB::table('clients')->insertGetId(['agent_id' => $agentOrigin, 'client_category_id' => $cat1, 'name' => 'Client 1'] + $ts);
        $c2 = DB::table('clients')->insertGetId(['agent_id' => $agentOrigin, 'client_category_id' => $cat1, 'name' => 'Client 2'] + $ts);
        DB::table('client_soldes')->insert([['client_id' => $c1, 'solde_restant' => 10] + $ts, ['client_id' => $c2, 'solde_restant' => 20] + $ts]);
        $t1 = DB::table('client_transactions')->insertGetId(['client_id' => $c1, 'agent_id' => $agentOrigin, 'amount' => 5] + $ts);
        $t2 = DB::table('client_transactions')->insertGetId(['client_id' => $c1, 'agent_id' => null, 'amount' => 6] + $ts);
        $t3 = DB::table('client_transactions')->insertGetId(['client_id' => $c2, 'agent_id' => $agentOther, 'amount' => 7] + $ts);

        $this->ids = compact('cat1', 'cat2', 'c1', 'c2', 't1', 't2', 't3');
    }

    private function snapshot(): array
    {
        return collect(['client_categories', 'clients', 'client_soldes', 'client_transactions'])
            ->mapWithKeys(fn ($t) => [$t => md5(json_encode(DB::table($t)->orderBy('id')->get()))])->all();
    }

    private function twoTenants(): array
    {
        $origin = Tenant::query()->orderBy('id')->first();

        return [$origin->id, Tenant::create(['name' => 'Autre société'])->id];
    }

    // ============================================================ détection, comptage, relations, preuves

    public function test_it_detects_counts_checks_relations_and_separates_proven_from_ambiguous_rows(): void
    {
        [$origin, $other] = $this->twoTenants();
        $this->createLegacyTables();
        $this->legacyData($origin, $other);

        $legacy = MigrationPreflight::inspect()['legacy'];

        $this->assertSame(['client_categories', 'client_soldes', 'client_transactions', 'clients'], array_keys($legacy), '1. existence détectée');
        $this->assertSame([2, 2, 3, 2], array_values(array_map(fn ($t) => $t['rows'], $legacy)), '2. lignes comptées');
        $this->assertEquals(['client_id' => 'clients', 'agent_id' => 'agents', 'user_id' => 'users'], $legacy['client_transactions']['foreign_keys'], '3. relations vérifiées');
        $this->assertSame(['user_id' => 0], $legacy['client_transactions']['owner_columns_filled'], '4. aucun propriétaire renseigné (user_id vide)');

        // 5. démontrable comme appartenant au tenant d'origine
        $this->assertSame(1, $legacy['client_categories']['demonstrable_tenant_1']);
        $this->assertSame(2, $legacy['client_soldes']['demonstrable_tenant_1']);
        $this->assertSame(2, $legacy['client_transactions']['demonstrable_tenant_1']);
        $this->assertSame(1, $legacy['clients']['demonstrable_tenant_1']);

        // 6. impossible à attribuer : affiché avec les identifiants
        $this->assertSame([$this->ids['cat2']], $legacy['client_categories']['ambiguous_ids']);
        $this->assertStringContainsString('aucune preuve', $legacy['client_categories']['ambiguous_reasons'][0]);
        $this->assertSame([$this->ids['c2']], $legacy['clients']['ambiguous_ids'], 'client relié à l\'agent d\'un autre tenant : preuves contradictoires');
        $this->assertSame([$this->ids['t3']], $legacy['client_transactions']['ambiguous_ids']);
    }

    public function test_an_unambiguous_legacy_set_with_clear_ownership_is_demonstrated(): void
    {
        [$origin] = $this->twoTenants();
        $this->createLegacyTables();
        $agent = DB::table('agents')->insertGetId(array_merge(Agent::factory()->make()->getAttributes(), ['tenant_id' => $origin, 'created_at' => now(), 'updated_at' => now()]));
        $ts = ['created_at' => now(), 'updated_at' => now()];
        $cat = DB::table('client_categories')->insertGetId(['name' => 'C'] + $ts);
        $client = DB::table('clients')->insertGetId(['agent_id' => $agent, 'client_category_id' => $cat, 'name' => 'K'] + $ts);
        DB::table('client_soldes')->insert(['client_id' => $client] + $ts);

        $verdict = MigrationPreflight::verdict();

        $this->assertSame(0, collect($verdict['report']['legacy'])->sum('ambiguous'));
        $this->assertTrue($verdict['allowed']);
    }

    // ============================================================ blocage

    public function test_the_verdict_blocks_and_nothing_is_modified(): void
    {
        [$origin, $other] = $this->twoTenants();
        $this->createLegacyTables();
        $this->legacyData($origin, $other);
        $before = $this->snapshot();

        $verdict = MigrationPreflight::verdict();
        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('client_categories', $verdict['reasons'][0]);

        try {
            MigrationPreflight::assertCanMigrate();
            $this->fail('La migration aurait dû être bloquée.');
        } catch (MigrationBlocked $e) {
            $this->assertStringContainsString('MIGRATION BLOQUÉE', $e->getMessage());
            $this->assertStringContainsString('Impossible de déterminer avec certitude le tenant propriétaire', $e->getMessage());
            $this->assertStringContainsString('Validation humaine requise', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::hasColumn('clients', 'tenant_id'));
    }

    public function test_the_command_prints_the_report_and_exits_blocked(): void
    {
        [$origin, $other] = $this->twoTenants();
        $this->createLegacyTables();
        $this->legacyData($origin, $other);

        $this->artisan('tenancy:preflight')
            ->expectsOutputToContain('Tenants trouvés : 2')
            ->expectsOutputToContain('impossible à attribuer')
            ->expectsOutputToContain('Migration autorisée : NON')
            ->expectsOutputToContain('MIGRATION BLOQUÉE')
            ->assertFailed();
    }

    // ============================================================ validation humaine

    public function test_a_human_validation_is_required_motivated_and_tied_to_the_exact_data(): void
    {
        [$origin, $other] = $this->twoTenants();
        $this->createLegacyTables();
        $this->legacyData($origin, $other);

        // sans motif : refusé
        $this->artisan('tenancy:preflight', ['--accept-unattributed' => true])->assertFailed();
        $this->assertFalse(MigrationPreflight::verdict()['allowed']);

        // motif donné mais confirmation refusée : refusé
        $this->artisan('tenancy:preflight', ['--accept-unattributed' => true, '--note' => 'Relu avec le responsable des données'])
            ->expectsConfirmation('Confirmer cette validation humaine ?', 'no')
            ->assertFailed();
        $this->assertFalse(MigrationPreflight::verdict()['allowed']);

        // validation complète : la migration est autorisée, SANS attribution
        $this->artisan('tenancy:preflight', ['--accept-unattributed' => true, '--note' => 'Relu avec le responsable des données'])
            ->expectsConfirmation('Confirmer cette validation humaine ?', 'yes')
            ->expectsOutputToContain('Migration autorisée : OUI')
            ->assertSuccessful();
        $this->assertTrue(MigrationPreflight::verdict()['allowed']);

        // les données changent : la validation donnée ne vaut plus rien
        DB::table('client_categories')->insert(['name' => 'Nouvelle orpheline', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(MigrationPreflight::verdict()['allowed'], 'Toute modification des données invalide la validation.');
    }

    public function test_validating_never_attributes_deletes_archives_or_moves_clients_data(): void
    {
        [$origin, $other] = $this->twoTenants();
        $this->createLegacyTables();
        $this->legacyData($origin, $other);
        $before = $this->snapshot();

        MigrationPreflight::acknowledge('Relu avec le responsable des données');

        $this->assertSame($before, $this->snapshot(), 'Contenu strictement identique.');
        foreach (['client_categories', 'clients', 'client_soldes', 'client_transactions'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table} ne reçoit aucun tenant_id.");
            $this->assertTrue(Schema::hasTable($table), "{$table} n'est ni supprimée ni renommée.");
        }
    }

    // ============================================================ la vraie migration

    public function test_the_real_migration_is_blocked_before_any_change_then_runs_without_touching_clients(): void
    {
        // État d'avant le multi-tenant : on défait les deux migrations tenant et celles qui les suivent.
        Artisan::call('migrate:rollback', ['--step' => 5]);
        $this->assertFalse(Schema::hasTable('tenants'));

        $this->createLegacyTables();
        $ts = ['created_at' => now(), 'updated_at' => now()];
        $agent = DB::table('agents')->insertGetId(array_merge(Agent::factory()->make()->getAttributes(), $ts));
        $cat = DB::table('client_categories')->insertGetId(['name' => 'Orpheline'] + $ts);   // aucune preuve → ambigu
        $cat2 = DB::table('client_categories')->insertGetId(['name' => 'Utilisée'] + $ts);
        $client = DB::table('clients')->insertGetId(['agent_id' => $agent, 'client_category_id' => $cat2, 'name' => 'K'] + $ts);
        $before = $this->snapshot();

        // 1. bloquée AVANT toute modification
        try {
            Artisan::call('migrate');
            $this->fail('La migration aurait dû être bloquée.');
        } catch (MigrationBlocked $e) {
            $this->assertStringContainsString('MIGRATION BLOQUÉE', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('tenants'), 'Aucune table créée.');
        $this->assertFalse(Schema::hasColumn('agents', 'tenant_id'), 'Aucune colonne ajoutée.');
        $this->assertSame($before, $this->snapshot());

        // 2. après validation humaine : elle s'exécute, rattache le cœur, et ne touche PAS aux clients*
        MigrationPreflight::acknowledge('Relu avec le responsable des données');

        // SQLite reconstruit la table agents pour poser NOT NULL pendant que clients la référence : on diffère les
        // contraintes (limite de SQLite en test ; MySQL/MariaDB modifie la colonne en place, vérifié sur MariaDB).
        DB::statement('PRAGMA defer_foreign_keys = ON');
        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('tenants'));
        $this->assertSame(1, (int) DB::table('agents')->where('id', $agent)->value('tenant_id'), 'Le cœur est rattaché au tenant d\'origine.');
        $this->assertSame($before, $this->snapshot(), 'Les tables clients* sont strictement inchangées.');
        foreach (['client_categories', 'clients', 'client_soldes', 'client_transactions'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table} n'a jamais été attribuée.");
        }
    }

    // ============================================================ sauvegarde obligatoire

    public function test_a_verifiable_backup_is_required_before_migrating_real_data(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.name' => 'uv-test']);
        TenantContext::runAs(1, fn () => Agent::factory()->create());

        $verdict = MigrationPreflight::verdict();
        $this->assertFalse($verdict['allowed']);
        $this->assertStringStartsWith('SAUVEGARDE', $verdict['reasons'][0]);

        $dir = sys_get_temp_dir();
        $tooSmall = tempnam($dir, 'bkp');
        file_put_contents($tooSmall, 'x');
        $this->assertFalse(MigrationPreflight::recordBackup($tooSmall)['ok'], 'Trop petit pour une sauvegarde.');
        $this->assertFalse(MigrationPreflight::recordBackup('/inexistant.sql')['ok']);

        $old = tempnam($dir, 'bkp');
        file_put_contents($old, str_repeat('INSERT INTO t VALUES (1);', 200));
        touch($old, time() - 5 * 86400);
        $this->assertFalse(MigrationPreflight::recordBackup($old)['ok'], 'Sauvegarde trop ancienne.');

        $good = tempnam($dir, 'bkp');
        file_put_contents($good, str_repeat('INSERT INTO t VALUES (1);', 200));
        $this->assertTrue(MigrationPreflight::recordBackup($good)['ok']);
        $this->assertTrue(MigrationPreflight::verdict()['allowed']);

        // le fichier disparaît ou change : la sauvegarde n'est plus « vérifiable »
        file_put_contents($good, 'tronqué');
        $this->assertFalse(MigrationPreflight::verdict()['allowed']);

        foreach ([$tooSmall, $old, $good] as $f) {
            @unlink($f);
        }
    }
}
