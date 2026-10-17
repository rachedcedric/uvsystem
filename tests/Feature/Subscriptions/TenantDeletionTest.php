<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Filament\Platform\Resources\TenantResource\Pages\ListTenants;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDestroyer;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Suppression définitive d'une entreprise par le super admin : tout ce qui lui appartient disparaît, rien d'autre ne bouge,
 * et un échec n'efface rien (une seule transaction).
 */
class TenantDeletionTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    /** Une entreprise « vivante » : abonnement, utilisateur avec rôle/jeton/session/notification, agent, fournisseur, fichiers. */
    private function livingTenant(string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        app(SubscriptionManager::class)->startTrial($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $user = $this->userIn($tenant, 'master');
        $user->createToken('mobile');
        DB::table('sessions')->insert(['id' => 'sess-'.$tenant->id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'Test', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        TenantContext::runAs($tenant->id, function () {
            Agent::factory()->create();
            Fournisseur::factory()->create();
        });

        Storage::disk('local')->put($tenant->storagePath('exports/a.txt'), 'x');
        Storage::disk('public')->put($tenant->storagePath('branding/logo.png'), 'x');

        return $tenant;
    }

    /** @return array<string, int> nombre de lignes par table portant tenant_id, pour ce tenant */
    private function rowsOf(Tenant $tenant): array
    {
        $rows = [];

        foreach (Schema::getTableListing(schema: null, schemaQualified: false) as $table) {
            if ($table !== 'tenants' && Schema::hasColumn($table, 'tenant_id')) {
                $rows[$table] = DB::table($table)->where('tenant_id', $tenant->id)->count();
            }
        }

        return $rows;
    }

    private function userLinksOf(Tenant $tenant): int
    {
        $ids = DB::table('users')->where('tenant_id', $tenant->id)->pluck('id');
        $morph = (new User)->getMorphClass();

        return DB::table('model_has_roles')->where('model_type', $morph)->whereIn('model_id', $ids)->count()
            + DB::table('personal_access_tokens')->where('tokenable_type', $morph)->whereIn('tokenable_id', $ids)->count()
            + DB::table('sessions')->whereIn('user_id', $ids)->count()
            + DB::table('notifications')->where('notifiable_type', $morph)->whereIn('notifiable_id', $ids)->count();
    }

    public function test_deleting_an_enterprise_erases_all_of_its_data_and_nothing_else(): void
    {
        $doomed = $this->livingTenant('Entreprise à supprimer');
        $kept = $this->livingTenant('Entreprise conservée');
        $super = $this->superAdmin();

        $before = $this->rowsOf($doomed);
        $keptBefore = $this->rowsOf($kept);
        $keptLinks = $this->userLinksOf($kept);

        // Le test n'a de sens que si l'entreprise porte des données dans plusieurs tables, abonnement compris.
        $this->assertGreaterThanOrEqual(5, count(array_filter($before)));
        $this->assertGreaterThan(0, $before['tenant_subscriptions']);
        $this->assertGreaterThan(0, $before['agents']);
        $this->assertGreaterThan(0, $this->userLinksOf($doomed));

        $deleted = app(TenantDestroyer::class)->destroy($doomed, $super);

        $this->assertDatabaseMissing('tenants', ['id' => $doomed->id]);
        $this->assertSame(array_fill_keys(array_keys($before), 0), $this->rowsOf($doomed), 'plus aucune ligne dans aucune table');
        $this->assertSame(0, $this->userLinksOf($doomed), 'rôles, jetons, sessions et notifications supprimés');
        $this->assertSame(array_sum($before), array_sum($deleted), 'le compte rendu correspond aux lignes réellement supprimées');

        Storage::disk('local')->assertMissing($doomed->storagePath('exports/a.txt'));
        Storage::disk('public')->assertMissing($doomed->storagePath('branding/logo.png'));

        // L'autre entreprise n'a pas bougé : mêmes lignes, mêmes accès, mêmes fichiers.
        $this->assertDatabaseHas('tenants', ['id' => $kept->id]);
        $this->assertSame($keptBefore, $this->rowsOf($kept));
        $this->assertSame($keptLinks, $this->userLinksOf($kept));
        Storage::disk('local')->assertExists($kept->storagePath('exports/a.txt'));
        Storage::disk('public')->assertExists($kept->storagePath('branding/logo.png'));

        // Le compte du super admin de plateforme (sans entreprise) est intact.
        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    public function test_a_failure_deletes_nothing_because_everything_runs_in_one_transaction(): void
    {
        $tenant = $this->livingTenant('Entreprise protégée par une contrainte');
        $super = $this->superAdmin();

        // Une table SANS tenant_id qui référence l'entreprise en RESTRICT : le dernier ordre (supprimer l'entreprise) échoue,
        // alors que toutes les données ont déjà été supprimées dans la transaction. Tout doit être annulé.
        Schema::create('zz_blocker', function ($table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('tenants')->restrictOnDelete();
        });
        DB::table('zz_blocker')->insert(['owner_id' => $tenant->id]);

        $before = $this->rowsOf($tenant);
        $links = $this->userLinksOf($tenant);

        try {
            app(TenantDestroyer::class)->destroy($tenant, $super);
            $this->fail('La suppression aurait dû échouer.');
        } catch (QueryException) {
            // attendu
        }

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
        $this->assertSame($before, $this->rowsOf($tenant), 'aucune donnée perdue');
        $this->assertSame($links, $this->userLinksOf($tenant), 'aucun accès perdu');
        Storage::disk('local')->assertExists($tenant->storagePath('exports/a.txt'));
        Storage::disk('public')->assertExists($tenant->storagePath('branding/logo.png'));
    }

    public function test_only_a_platform_super_admin_can_delete_an_enterprise(): void
    {
        $tenant = $this->livingTenant('Entreprise visée');
        $master = $this->userIn($tenant, 'master');
        $other = $this->livingTenant('Autre entreprise');
        $otherMaster = $this->userIn($other, 'master');

        foreach ([$master, $otherMaster] as $intruder) {
            try {
                app(TenantDestroyer::class)->destroy($tenant, $intruder);
                $this->fail('Un utilisateur d\'entreprise ne doit pas pouvoir supprimer une entreprise.');
            } catch (AuthorizationException) {
                // attendu
            }

            $this->actingAs($intruder);
            Livewire::test(ListTenants::class)->assertForbidden();
        }

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_the_delete_button_needs_the_exact_name_of_the_enterprise(): void
    {
        $tenant = $this->livingTenant('Boutique Soleil');
        $super = $this->superAdmin();
        $this->actingAs($super);

        $list = Livewire::test(ListTenants::class);

        // Un autre nom, ou rien : refusé, rien n'est supprimé.
        $list->callTableAction('destroy', $tenant, data: ['confirmation' => 'Boutique Lune'])->assertHasTableActionErrors(['confirmation']);
        $list->callTableAction('destroy', $tenant, data: ['confirmation' => ''])->assertHasTableActionErrors(['confirmation']);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);

        // Le bon nom (casse et espaces ignorés) : l'entreprise est supprimée.
        $list->callTableAction('destroy', $tenant, data: ['confirmation' => '  boutique soleil '])->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
        $this->assertSame(0, $this->userLinksOf($tenant));
    }

    public function test_the_deletion_is_traced_and_the_legacy_enterprise_is_flagged(): void
    {
        // L'entreprise d'origine est la plus ancienne (ici celle que la base de test crée d'office, de numéro 1).
        $legacy = Tenant::query()->orderBy('id')->firstOrFail();
        $later = $this->livingTenant('Entreprise récente');
        $destroyer = app(TenantDestroyer::class);

        $this->assertTrue($destroyer->isLegacyOwner($legacy));
        $this->assertFalse($destroyer->isLegacyOwner($later));
        $this->assertStringContainsString('utilisateur(s)', $destroyer->describe($later));

        $destroyer->destroy($later, $this->superAdmin());

        $this->assertDatabaseHas('activity_log', ['log_name' => 'plateforme', 'description' => "Entreprise supprimée : Entreprise récente (#{$later->id})"]);
    }
}
