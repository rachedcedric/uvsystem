<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Livewire\Mobile\Crud;
use App\Models\Tenant;
use App\Models\User;
use App\Security\RoleAssignmentGuard;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chaque compte client a son admin : mêmes autorisations que le master. Seule règle qui les distingue : seul un master
 * nomme un master (et touche au compte d'un master). Appliquée côté serveur, sur le web comme sur le mobile.
 */
class TenantRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $master;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        $this->tenant = Tenant::create(['name' => 'Société A']);
        $this->master = $this->userIn($this->tenant, 'master');
        $this->admin = $this->userIn($this->tenant, 'admin');
    }

    private function userIn(Tenant $tenant, string $role): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    private function act(User $user, string $panel = 'admin'): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($panel));
    }

    private function roleId(string $name): int
    {
        return Role::findByName($name)->getKey();
    }

    // ------------------------------------------------------------------ permissions

    public function test_the_admin_has_exactly_the_master_permissions_and_neither_has_a_protected_one(): void
    {
        $master = Role::findByName('master')->permissions->pluck('name')->sort()->values()->all();
        $admin = Role::findByName('admin')->permissions->pluck('name')->sort()->values()->all();

        $this->assertSame($master, $admin, 'chaque compte client a son admin : mêmes autorisations que le master');
        $this->assertContains('assign_roles', $admin);
        foreach (User::FORBIDDEN_TENANT_PERMISSIONS as $protected) {
            $this->assertNotContains($protected, $admin, "{$protected} reste réservée à la plateforme");
        }
        $this->assertSame(Permission::whereNotIn('name', User::FORBIDDEN_TENANT_PERMISSIONS)->count(), count($admin));

        foreach (['comptable', 'commercial'] as $role) {
            $this->assertNotContains('assign_roles', Role::findByName($role)->permissions->pluck('name')->all());
        }
    }

    // ------------------------------------------------------------------ règle d'attribution

    public function test_who_can_hand_out_which_roles(): void
    {
        $this->assertSame(['master', 'admin', 'comptable', 'commercial', 'lecture_seule'], RoleAssignmentGuard::assignableFor($this->master));
        $this->assertSame(['admin', 'comptable', 'commercial', 'lecture_seule'], RoleAssignmentGuard::assignableFor($this->admin));
        $this->assertSame([], RoleAssignmentGuard::assignableFor($this->userIn($this->tenant, 'comptable')));
        $this->assertSame([], RoleAssignmentGuard::assignableFor($this->userIn($this->tenant, 'commercial')));
        $this->assertSame([], RoleAssignmentGuard::assignableFor(null));
    }

    public function test_the_guard_refuses_master_for_an_admin_and_platform_roles_for_everyone(): void
    {
        $target = $this->userIn($this->tenant, 'commercial');

        RoleAssignmentGuard::assertCanAssign($this->admin, $target, ['admin', 'comptable']);
        RoleAssignmentGuard::assertCanAssign($this->master, $target, ['master', 'admin']);
        $this->addToAssertionCount(2);

        $refuse = function (?User $actor, array $roles) use ($target) {
            try {
                RoleAssignmentGuard::assertCanAssign($actor, $target, $roles);
                $this->fail('attribution aurait dû être refusée : '.implode(',', $roles));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('roles', $e->errors());
            }
        };

        $refuse($this->admin, ['master']);
        $refuse($this->admin, ['admin', 'master']);
        $refuse($this->admin, ['super_admin']);
        $refuse($this->master, ['super_admin']);
        $refuse($this->master, ['n-importe-quoi']);
        $refuse($this->userIn($this->tenant, 'comptable'), ['commercial']);
        $refuse(null, ['commercial']);
    }

    public function test_an_admin_cannot_touch_the_roles_of_a_master(): void
    {
        foreach ([['admin'], ['master'], ['comptable']] as $roles) { // rétrograder le propriétaire, ou même « confirmer » son rôle
            try {
                RoleAssignmentGuard::assertCanAssign($this->admin, $this->master, $roles);
                $this->fail('un admin ne doit pas pouvoir modifier les rôles d\'un master : '.implode(',', $roles));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('roles', $e->errors());
            }
        }
    }

    public function test_the_company_always_keeps_at_least_one_master(): void
    {
        // Seul master : il ne peut pas se retirer le rôle.
        try {
            RoleAssignmentGuard::assertCanAssign($this->master, $this->master, ['admin']);
            $this->fail('le dernier master ne doit pas pouvoir être rétrogradé');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('au moins un master', $e->errors()['roles'][0]);
        }

        // Avec un second master, c'est permis.
        $second = $this->userIn($this->tenant, 'master');
        RoleAssignmentGuard::assertCanAssign($this->master, $second, ['admin']);
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------ comptes master protégés

    public function test_an_admin_cannot_edit_or_delete_a_master_account_but_manages_everyone_else(): void
    {
        $commercial = $this->userIn($this->tenant, 'commercial');
        $otherAdmin = $this->userIn($this->tenant, 'admin');
        $this->act($this->admin);

        $this->assertFalse($this->admin->can('update', $this->master), 'sinon un admin changerait le mot de passe du propriétaire');
        $this->assertFalse(UserResource::canEdit($this->master));
        $this->assertFalse($this->admin->can('delete', $this->master));
        $this->assertTrue($this->admin->can('update', $this->admin), 'son propre profil');
        $this->assertTrue($this->admin->can('update', $commercial));
        $this->assertTrue($this->admin->can('update', $otherAdmin));
        $this->assertTrue(UserResource::canDelete($commercial));
        $this->assertTrue(UserResource::canCreate());
    }

    public function test_a_master_manages_other_masters_but_nobody_deletes_a_master_account(): void
    {
        $second = $this->userIn($this->tenant, 'master');
        $this->act($this->master);

        $this->assertTrue($this->master->can('update', $second));
        $this->assertFalse($this->master->can('delete', $second));
        $this->assertFalse($this->master->can('delete', $this->master));
    }

    // ------------------------------------------------------------------ écrans web

    public function test_on_the_web_an_admin_creates_a_user_with_an_allowed_role(): void
    {
        $this->act($this->admin);

        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Nouveau comptable', 'email' => 'compta@example.test', 'password' => 'Un-Mot-De-Passe-12', 'password_confirmation' => 'Un-Mot-De-Passe-12',
            'roles' => [$this->roleId('comptable')],
        ])->call('create')->assertHasNoFormErrors();

        $this->assertTrue(User::where('email', 'compta@example.test')->firstOrFail()->hasRole('comptable'));
    }

    public function test_on_the_web_an_admin_can_never_create_or_promote_a_master(): void
    {
        $this->act($this->admin);

        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Faux master', 'email' => 'faux@example.test', 'password' => 'Un-Mot-De-Passe-12', 'password_confirmation' => 'Un-Mot-De-Passe-12',
            'roles' => [$this->roleId('master')],
        ])->call('create')->assertHasErrors(['roles']);
        $this->assertNull(User::where('email', 'faux@example.test')->first());

        $target = $this->userIn($this->tenant, 'commercial');
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])->set('data.roles', [$this->roleId('master')])->call('save')->assertHasErrors(['roles']);
        $this->assertFalse($target->fresh()->hasRole('master'));
        $this->assertTrue($target->fresh()->hasRole('commercial'), 'rien n\'a changé');
    }

    public function test_on_the_web_an_admin_cannot_open_the_edit_page_of_a_master(): void
    {
        $this->act($this->admin);

        Livewire::test(EditUser::class, ['record' => $this->master->getRouteKey()])->assertForbidden();
    }

    public function test_on_the_web_the_role_choices_offered_to_an_admin_never_include_master(): void
    {
        $target = $this->userIn($this->tenant, 'commercial');
        $this->act($this->admin);

        $options = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])->instance()->getForm('form')->getComponent('data.roles')->getOptions();
        $this->assertNotContains('master', array_values($options));
        $this->assertContains('admin', array_values($options));

        $this->act($this->master);
        $options = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])->instance()->getForm('form')->getComponent('data.roles')->getOptions();
        $this->assertContains('master', array_values($options));
    }

    // ------------------------------------------------------------------ mobile

    public function test_on_mobile_an_admin_sets_allowed_roles_but_not_master(): void
    {
        $this->act($this->admin);

        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs'])
            ->set('data.name', 'Mobile comptable')->set('data.email', 'mobile-compta@example.test')
            ->set('data.password', 'motdepasse1')->set('data.password_confirmation', 'motdepasse1')
            ->call('toggleChoice', 'roles', 'comptable')->call('save')->assertHasNoErrors();
        $this->assertTrue(User::where('email', 'mobile-compta@example.test')->firstOrFail()->hasRole('comptable'));

        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs'])
            ->set('data.name', 'Mobile faux master')->set('data.email', 'mobile-faux@example.test')
            ->set('data.password', 'motdepasse1')->set('data.password_confirmation', 'motdepasse1')
            ->set('data.roles', ['master'])->call('save')->assertHasErrors(['data.roles']);
        $this->assertNull(User::where('email', 'mobile-faux@example.test')->first());
    }

    public function test_on_mobile_an_admin_cannot_demote_or_edit_a_master(): void
    {
        $this->act($this->admin);

        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs', 'id' => (string) $this->master->id])->assertForbidden();
        $this->assertTrue($this->master->fresh()->hasRole('master'));
    }

    public function test_on_mobile_a_user_without_role_rights_never_wipes_the_roles_he_cannot_see(): void
    {
        $target = $this->userIn($this->tenant, 'commercial');
        $editor = $this->userIn($this->tenant, 'comptable');
        $editor->givePermissionTo(['view_users', 'edit_users']);
        $this->act($editor);

        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs', 'id' => (string) $target->id])->set('data.name', 'Renommé')->call('save')->assertHasNoErrors();

        $this->assertSame('Renommé', $target->fresh()->name);
        $this->assertTrue($target->fresh()->hasRole('commercial'), 'les rôles existants restent intacts');
    }

    // ------------------------------------------------------------------ isolation

    public function test_an_admin_cannot_reach_or_change_users_of_another_company(): void
    {
        $other = Tenant::create(['name' => 'Société B']);
        $foreign = $this->userIn($other, 'commercial');
        $this->act($this->admin);

        $this->assertNull(User::find($foreign->id), 'invisible : le périmètre de l\'entreprise s\'applique');
        $this->get('/master/users/'.$foreign->getRouteKey().'/edit')->assertNotFound();
        $this->assertTrue($foreign->fresh()->hasRole('commercial'));
    }
}
