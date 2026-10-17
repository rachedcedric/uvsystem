<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Agents\Show;
use App\Livewire\Mobile\Crud;
use App\Models\Agent;
use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Fournisseur;
use App\Models\PalierCommission;
use App\Models\SousDistributeur;
use App\Models\User;
use App\Support\Mobile\Crud\Registry;
use App\Support\Mobile\Crud\Resources\CategoriesCaisse;
use App\Support\Mobile\Crud\Resources\CategoriesCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileCrudTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'view_agents', 'create_agents', 'edit_agents', 'delete_agents',
        'view_fournisseurs', 'create_fournisseurs', 'edit_fournisseurs', 'delete_fournisseurs',
        'view_users', 'create_users', 'edit_users', 'delete_users', 'assign_roles',
        'view_caisse', 'create_caisse', 'edit_caisse', 'delete_caisse', 'edit_charges',
    ];

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        foreach (['master', 'admin', 'commercial'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        Role::findByName('master')->syncPermissions(self::PERMISSIONS);

        $this->master = User::factory()->create(['name' => 'Chef Master']);
        $this->master->assignRole('master');
        $this->actingAs($this->master);
    }

    public function test_supplier_can_be_created_edited_and_deleted(): void
    {
        Livewire::test(Crud\Form::class, ['resource' => 'fournisseurs'])
            ->set('data.name', 'Banque Atlantique')
            ->set('data.type', 'BANQUE')
            ->set('data.contact', '0701020304')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $f = Fournisseur::firstOrFail();
        $this->assertSame('Banque Atlantique', $f->name);
        $this->assertTrue((bool) $f->is_active);

        Livewire::test(Crud\Form::class, ['resource' => 'fournisseurs', 'id' => (string) $f->id])
            ->assertSet('data.name', 'Banque Atlantique')
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertFalse((bool) $f->fresh()->is_active);

        Livewire::test(Crud\Index::class, ['resource' => 'fournisseurs'])->assertSee('Banque Atlantique')->assertSee('Inactif');
        Livewire::test(Crud\Show::class, ['resource' => 'fournisseurs', 'id' => (string) $f->id])->call('delete');
        $this->assertSame(0, Fournisseur::count());
    }

    public function test_validation_uses_french_messages_and_required_fields(): void
    {
        Livewire::test(Crud\Form::class, ['resource' => 'fournisseurs'])
            ->call('save')
            ->assertHasErrors(['data.name' => 'required'])
            ->assertSee('Ce champ est obligatoire.');
    }

    public function test_agent_form_enforces_unique_phone_and_applies_defaults(): void
    {
        Agent::factory()->create(['phone' => '0700000001']);

        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->assertSet('data.debt_limit', '500000')
            ->assertSet('data.status', 'active')
            ->set('data.name', 'Doublon')->set('data.phone', '0700000001')
            ->call('save')
            ->assertHasErrors(['data.phone' => 'unique']);

        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->set('data.name', 'Nouvel Agent')->set('data.phone', '0700000002')
            ->set('data.debt_limit', '750000')->set('data.commission_rate', '4.5')
            ->call('save')->assertHasNoErrors();

        $agent = Agent::where('phone', '0700000002')->firstOrFail();
        $this->assertEquals(750000, $agent->debt_limit);
        $this->assertEquals(4.5, $agent->commission_rate);
    }

    public function test_commission_tier_requires_max_above_min(): void
    {
        Livewire::test(Crud\Form::class, ['resource' => 'paliers'])
            ->set('data.seuil_min', '500000')->set('data.seuil_max', '100000')->set('data.commission', '2000')
            ->call('save')->assertHasErrors(['data.seuil_max']);

        Livewire::test(Crud\Form::class, ['resource' => 'paliers'])
            ->set('data.seuil_min', '100000')->set('data.seuil_max', '500000')->set('data.commission', '2000')
            ->call('save')->assertHasNoErrors();

        $this->assertEquals(2000, PalierCommission::where('seuil_min', 100000)->where('seuil_max', 500000)->latest('id')->firstOrFail()->commission);
    }

    public function test_settings_screens_are_restricted_to_admins(): void
    {
        $commercial = User::factory()->create();
        $commercial->assignRole('commercial');
        $this->actingAs($commercial);

        Livewire::test(Crud\Index::class, ['resource' => 'paliers'])->assertForbidden();
        Livewire::test(Crud\Index::class, ['resource' => 'modes-paiement'])->assertForbidden();
    }

    public function test_user_password_must_follow_the_company_policy(): void
    {
        foreach (['motdepasse1234', 'Motdepasse12345', 'Court#1a'] as $weak) {
            Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs'])
                ->set('data.name', 'Faible')->set('data.email', 'faible@example.com')
                ->set('data.password', $weak)->set('data.password_confirmation', $weak)
                ->call('save')->assertHasErrors(['data.password']);
        }

        $this->assertNull(User::where('email', 'faible@example.com')->first());
    }

    public function test_user_is_created_with_roles_and_cannot_delete_self(): void
    {
        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs'])
            ->set('data.name', 'Awa Comptable')->set('data.email', 'awa@example.com')
            ->set('data.password', 'Motdepasse#1234')->set('data.password_confirmation', 'autre')
            ->call('save')->assertHasErrors(['data.password_confirmation']);

        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs'])
            ->set('data.name', 'Awa Comptable')->set('data.email', 'awa@example.com')
            ->set('data.password', 'Motdepasse#1234')->set('data.password_confirmation', 'Motdepasse#1234')
            ->call('toggleChoice', 'roles', 'commercial')
            ->call('save')->assertHasNoErrors();

        $user = User::where('email', 'awa@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('commercial'));
        $this->assertTrue(Hash::check('Motdepasse#1234', $user->password));

        Livewire::test(Crud\Show::class, ['resource' => 'utilisateurs', 'id' => (string) $this->master->id])->call('delete')->assertForbidden();
    }

    public function test_editing_an_existing_user_shows_and_keeps_their_roles(): void
    {
        $user = User::factory()->create(['name' => 'Ancien Nom']);
        $user->assignRole('commercial');

        // Régression : l'écran de modification plantait (erreur 500 « Array to string conversion »).
        $form = Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs', 'id' => (string) $user->id])
            ->assertOk()
            ->assertSet('data.roles', ['commercial']);

        $form->set('data.name', 'Nouveau Nom')->call('save')->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Nouveau Nom', $user->name);
        $this->assertTrue($user->hasRole('commercial'));
        $this->assertCount(1, $user->getRoleNames());

        $this->get(route('m.crud.edit', ['utilisateurs', $user->id]))->assertOk();
    }

    public function test_only_a_master_can_grant_the_master_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo(['view_users', 'create_users', 'edit_users', 'assign_roles']);
        $this->actingAs($admin);

        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs'])
            ->set('data.name', 'Intrus')->set('data.email', 'intrus@example.com')
            ->set('data.password', 'Motdepasse#1234')->set('data.password_confirmation', 'Motdepasse#1234')
            ->call('toggleChoice', 'roles', 'master')
            ->call('save')->assertHasErrors(['data.roles']);

        $this->assertNull(User::where('email', 'intrus@example.com')->first());
    }

    public function test_caisse_category_name_must_be_unique_and_used_ones_are_kept(): void
    {
        $existing = CaisseCategorie::create(['name' => 'Transport', 'is_active' => true, 'sort_order' => 1]);

        Livewire::test(Crud\Form::class, ['resource' => 'categories-caisse'])
            ->set('data.name', ' transport ')->set('data.sort_order', '1')
            ->call('save')->assertHasErrors(['data.name']);

        $this->assertTrue(CategoriesCaisse::canDelete($this->master, $existing));

        CaisseMouvement::create(['type' => 'entree', 'amount' => 1000, 'libelle' => 'x', 'caisse_categorie_id' => $existing->id, 'date_mouvement' => now()]);
        $this->assertFalse(CategoriesCaisse::canDelete($this->master, $existing->fresh()));
    }

    public function test_charge_category_in_use_cannot_be_deleted(): void
    {
        $cat = ChargeCategorie::create(['code' => 'loyer', 'name' => 'Loyer', 'is_active' => true]);
        $this->assertTrue(CategoriesCharges::canDelete($this->master, $cat));

        Charge::create(['category_id' => $cat->id, 'libelle' => 'Loyer octobre', 'montant' => 100000, 'date_charge' => today(), 'created_by' => $this->master->id]);
        $this->assertFalse(CategoriesCharges::canDelete($this->master, $cat));
    }

    public function test_sub_distributor_form_and_pages_render(): void
    {
        $agent = Agent::factory()->create(['name' => 'Agent Parent']);

        Livewire::test(Crud\Form::class, ['resource' => 'sous-distributeurs'])
            ->set('data.agent_id', (string) $agent->id)->set('data.nom', 'Sous Moussa')->set('data.telephone', '0712121212')
            ->call('save')->assertHasNoErrors();

        $sd = SousDistributeur::firstOrFail();
        $this->get(route('m.crud.index', 'sous-distributeurs'))->assertOk()->assertSee('Sous Moussa');
        $this->get(route('m.crud.show', ['sous-distributeurs', $sd->id]))->assertOk()->assertSee('Agent Parent');
        $this->get(route('m.crud.edit', ['sous-distributeurs', $sd->id]))->assertOk();
    }

    public function test_agent_screens_expose_create_edit_and_delete(): void
    {
        $agent = Agent::factory()->create(['name' => 'Awa Koné']);

        $this->get(route('m.agents'))->assertOk()->assertSee('Nouvel agent');
        Livewire::test(Show::class, ['agent' => $agent])->assertSee('Supprimer l')->call('delete');
        $this->assertSoftDeleted($agent);
    }

    public function test_every_registered_resource_renders_its_pages(): void
    {
        foreach (Registry::all() as $resource) {
            if ($resource::slug() === 'agents' || $resource::hidden()) {
                continue; // écrans dédiés, couverts par MobileSmokeTest
            }
            $this->get(route('m.crud.index', $resource::slug()))->assertOk();
            $this->get(route('m.crud.create', $resource::slug()))->assertOk();
        }
    }
}
