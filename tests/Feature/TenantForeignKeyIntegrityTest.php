<?php

namespace Tests\Feature;

use App\Filament\Pages\ChargesPage;
use App\Filament\Resources\AgentSoldeResource\Pages\CreateAgentSolde;
use App\Filament\Resources\ApprovisionnementResource\Pages\CreateApprovisionnement;
use App\Livewire\Mobile\Crud;
use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Commission;
use App\Models\Fournisseur;
use App\Models\SousDistributeur;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CrossTenantReference;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/**
 * Un enregistrement du tenant A ne peut JAMAIS référencer un parent du tenant B (pas de contrainte composite
 * SQL : le contrôle est applicatif, centralisé dans BelongsToTenant), qu'il vienne de Filament, de l'API,
 * d'un service, d'un import ou d'une modification.
 */
class TenantForeignKeyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $userA;

    private User $userB;

    private Agent $agentA;

    private Agent $agentB;

    private Fournisseur $fournA;

    private Fournisseur $fournB;

    private ChargeCategorie $catA;

    private ChargeCategorie $catB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);

        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
        $this->userA = $this->master($this->a);
        $this->userB = $this->master($this->b);

        foreach ([[$this->a, 'A'], [$this->b, 'B']] as [$tenant, $label]) {
            TenantContext::runAs($tenant->id, function () use ($label) {
                $this->{'agent'.$label} = Agent::factory()->create(['status' => 'active', 'name' => "Agent {$label}"]);
                $this->{'fourn'.$label} = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true, 'name' => "Fournisseur {$label}"]);
                $this->{'cat'.$label} = ChargeCategorie::create(['code' => 'c', 'name' => "Cat {$label}", 'is_active' => true]);
            });
        }
    }

    private function master(Tenant $tenant): User
    {
        return TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
    }

    private function asTenantA(callable $fn): mixed
    {
        return TenantContext::runAs($this->a->id, $fn);
    }

    private function assertRefused(callable $attempt, string $field): void
    {
        try {
            $attempt();
            $this->fail("Une référence inter-tenant sur « {$field} » aurait dû être refusée.");
        } catch (CrossTenantReference $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString("n'appartient pas à votre société", $e->errors()[$field][0]);
        }
    }

    // ---- injection d'un parent étranger à la CRÉATION ----------------------------------------------------------

    public function test_creating_a_child_with_a_parent_of_another_tenant_is_refused_without_writing_anything(): void
    {
        $before = [
            'approvisionnements' => DB::table('approvisionnements')->count(),
            'sous_distributeurs' => DB::table('sous_distributeurs')->count(),
            'charges' => DB::table('charges')->count(),
            'agent_soldes' => DB::table('agent_soldes')->count(),
        ];

        $this->assertRefused(fn () => $this->asTenantA(fn () => Approvisionnement::create([
            'user_id' => $this->userA->id, 'fournisseur_id' => $this->fournB->id, 'amount' => 1000, 'date_approvisionnement' => now(),
        ])), 'fournisseur_id');

        $this->assertRefused(fn () => $this->asTenantA(fn () => SousDistributeur::create([
            'agent_id' => $this->agentB->id, 'nom' => 'Z', 'telephone' => '0700000001', 'type' => 'standard', 'statut' => 'actif',
        ])), 'agent_id');

        $this->assertRefused(fn () => $this->asTenantA(fn () => Charge::create([
            'category_id' => $this->catB->id, 'libelle' => 'x', 'montant' => 1000, 'date_charge' => today(), 'created_by' => $this->userA->id,
        ])), 'category_id');

        $this->assertRefused(fn () => $this->asTenantA(fn () => AgentSolde::forceCreate(['agent_id' => $this->agentB->id])), 'agent_id');

        // un compte d'un autre tenant ne peut pas non plus être désigné comme auteur
        $this->assertRefused(fn () => $this->asTenantA(fn () => Charge::create([
            'category_id' => $this->catA->id, 'libelle' => 'x', 'montant' => 1000, 'date_charge' => today(), 'created_by' => $this->userB->id,
        ])), 'created_by');

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Aucune ligne ne doit être écrite dans {$table}.");
        }
    }

    public function test_a_refused_reference_rolls_back_the_whole_operation(): void
    {
        $agents = Agent::withoutGlobalScopes()->count();

        try {
            DB::transaction(function () {
                $this->asTenantA(function () {
                    Agent::factory()->create(['name' => 'Écrit puis annulé']);
                    SousDistributeur::create(['agent_id' => $this->agentB->id, 'nom' => 'Z', 'telephone' => '0700000002', 'type' => 'standard', 'statut' => 'actif']);
                });
            });
            $this->fail('Aurait dû lever CrossTenantReference.');
        } catch (CrossTenantReference) {
            // attendu
        }

        $this->assertSame($agents, Agent::withoutGlobalScopes()->count(), 'Pas d\'écriture partielle : l\'agent créé juste avant est annulé.');
    }

    // ---- MODIFICATION avec un parent étranger ------------------------------------------------------------------

    public function test_updating_a_child_to_point_at_a_foreign_parent_is_refused_and_leaves_the_row_untouched(): void
    {
        $charge = $this->asTenantA(fn () => Charge::create([
            'category_id' => $this->catA->id, 'libelle' => 'Loyer', 'montant' => 1000, 'date_charge' => today(), 'created_by' => $this->userA->id,
        ]));

        $this->assertRefused(fn () => $this->asTenantA(function () use ($charge) {
            $charge->category_id = $this->catB->id;
            $charge->libelle = 'modifié en même temps';
            $charge->save();
        }), 'category_id');

        $fresh = Charge::withoutGlobalScopes()->find($charge->id);
        $this->assertSame($this->catA->id, (int) $fresh->category_id);
        $this->assertSame('Loyer', $fresh->libelle, 'Aucun champ de la même sauvegarde ne doit être écrit.');
    }

    public function test_legitimate_writes_still_work(): void
    {
        $charge = $this->asTenantA(fn () => Charge::create([
            'category_id' => $this->catA->id, 'libelle' => 'Loyer', 'montant' => 1000, 'date_charge' => today(), 'created_by' => $this->userA->id,
        ]));
        $other = $this->asTenantA(fn () => ChargeCategorie::create(['code' => 'autre', 'name' => 'Autre A', 'is_active' => true]));

        $this->asTenantA(function () use ($charge, $other) {
            $charge->update(['category_id' => $other->id, 'libelle' => 'Renommé']);          // parent du même tenant
            $charge->update(['updated_by' => null]);                                          // clé nulle
            SousDistributeur::create(['agent_id' => $this->agentA->id, 'nom' => 'OK', 'telephone' => '0700000003', 'type' => 'standard', 'statut' => 'actif']);
            $this->agentA->delete();                                                           // parent supprimé logiquement…
            Approvisionnement::create(['user_id' => $this->userA->id, 'fournisseur_id' => $this->fournA->id, 'amount' => 5, 'date_approvisionnement' => now()]);
        });

        $this->assertSame('Renommé', Charge::withoutGlobalScopes()->find($charge->id)->libelle);
        $this->assertSame(1, SousDistributeur::withoutGlobalScopes()->where('agent_id', $this->agentA->id)->count());
    }

    public function test_every_belongs_to_relation_is_detectable_by_the_guard(): void
    {
        // Le garde découvre les parents par réflexion sur le type de retour BelongsTo : une relation sans ce
        // type échapperait au contrôle. On le garantit sur tout le code des modèles.
        foreach (glob(app_path('Models/*.php')) as $file) {
            preg_match_all('/function\s+(\w+)\s*\([^)]*\)(\s*:\s*[\w\\\\]+)?\s*\{[^}]*belongsTo\(/s', file_get_contents($file), $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $this->assertStringContainsString('BelongsTo', $match[2] ?? '', basename($file).'::'.$match[1].'() doit déclarer « : BelongsTo ».');
            }
        }

        // Parents effectivement protégés (exemples structurants)
        $protected = fn (string $class) => collect((new ReflectionClass($class))->getMethod('tenantParentReferences')->invoke(null))->pluck(0)->all();
        $this->assertEqualsCanonicalizing(['fournisseur_id', 'user_id'], $protected(Approvisionnement::class));
        $this->assertEqualsCanonicalizing(['category_id', 'created_by', 'updated_by'], $protected(Charge::class));
        $this->assertEqualsCanonicalizing(['agent_id'], $protected(SousDistributeur::class));
        $this->assertContains('commission_globale_id', $protected(Commission::class));
    }

    // ---- par les surfaces d'entrée -------------------------------------------------------------------------------

    public function test_filament_forms_refuse_a_foreign_parent_with_an_explicit_error(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->userA);

        Livewire::test(CreateApprovisionnement::class)
            ->fillForm(['fournisseur_id' => $this->fournB->id, 'amount' => 1000, 'date_approvisionnement' => now()->toDateTimeString()])
            ->call('create')
            ->assertHasErrors(['fournisseur_id']);

        // Les sous-distributeurs n'existent plus pour un master : le formulaire est fermé (la garde du modèle reste testée plus haut).
        $this->get('/master/sous-distributeurs/create')->assertForbidden();

        Livewire::test(CreateAgentSolde::class)->fillForm(['agent_id' => $this->agentB->id])->call('create')->assertHasErrors(['agent_id']);

        Livewire::test(ChargesPage::class)
            ->callTableAction('add', data: ['category_id' => $this->catB->id, 'libelle' => 'Piège', 'montant' => 1000, 'date_charge' => today()->toDateString()])
            ->assertHasErrors(['category_id']);

        $this->assertSame(0, Approvisionnement::withoutGlobalScopes()->where('fournisseur_id', $this->fournB->id)->count());
        $this->assertSame(0, SousDistributeur::withoutGlobalScopes()->where('agent_id', $this->agentB->id)->count());
        $this->assertSame(0, Charge::withoutGlobalScopes()->where('category_id', $this->catB->id)->count());
        $this->assertSame(0, AgentSolde::withoutGlobalScopes()->where('agent_id', $this->agentB->id)->where('tenant_id', $this->a->id)->count());
    }

    public function test_the_api_and_the_mobile_crud_refuse_a_foreign_parent(): void
    {
        Sanctum::actingAs($this->userA);
        $this->postJson('/api/transactions', ['agent_id' => $this->agentB->id, 'amount' => 1000])->assertStatus(422);
        $this->postJson('/api/transactions', ['agent_id' => $this->agentA->id, 'fournisseur_id' => $this->fournB->id, 'amount' => 1000])->assertStatus(422);

        $this->actingAs($this->userA);
        $form = Livewire::test(Crud\Form::class, ['resource' => 'charges']);
        $form->set('data.category_id', $this->catB->id)->set('data.libelle', 'x')->set('data.montant', '1000')->set('data.date_charge', today()->toDateString())->call('save');
        $this->assertSame(0, Charge::withoutGlobalScopes()->where('category_id', $this->catB->id)->count());
    }

    // ---- SUPPRESSION avec un identifiant étranger ----------------------------------------------------------------

    public function test_deleting_with_a_foreign_id_is_refused_and_nothing_is_deleted(): void
    {
        Sanctum::actingAs($this->userA);

        $this->deleteJson("/api/agents/{$this->agentB->id}")->assertNotFound();
        $this->deleteJson("/api/fournisseurs/{$this->fournB->id}")->assertNotFound();

        $this->assertNull(Agent::withoutGlobalScopes()->find($this->agentB->id)->deleted_at);
        $this->assertNull(Fournisseur::withoutGlobalScopes()->find($this->fournB->id)->deleted_at);

        // et un propriétaire légitime, lui, peut supprimer le sien
        $this->deleteJson("/api/fournisseurs/{$this->fournA->id}")->assertOk();
        $this->assertNotNull(Fournisseur::withoutGlobalScopes()->withTrashed()->find($this->fournA->id)->deleted_at);
    }
}
