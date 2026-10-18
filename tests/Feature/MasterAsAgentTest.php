<?php

namespace Tests\Feature;

use App\Enums\SubscriptionCycle;
use App\Exceptions\SubscriptionLimitReached;
use App\Filament\Agent\Pages\AgentDashboard;
use App\Filament\Resources\AgentResource\Pages\CreateAgent;
use App\Filament\Resources\AgentResource\Pages\EditAgent;
use App\Livewire\Mobile\Crud;
use App\Models\Agent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Le master est un gestionnaire d'agents ET peut être agent lui-même (fiche liée à son compte). */
class MasterAsAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
        $this->tenant = Tenant::create(['name' => 'Société A']);
        $this->master = $this->userIn($this->tenant, 'master');
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

    private function agentData(array $overrides = []): array
    {
        return $overrides + ['name' => 'Agent '.uniqid(), 'phone' => '07'.random_int(10000000, 99999999), 'status' => 'active', 'debt_limit' => 500000, 'commission_rate' => 5];
    }

    // ------------------------------------------------------------------ le master gère ses agents

    public function test_the_master_keeps_every_right_to_manage_the_agents_of_his_company(): void
    {
        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create());
        $this->act($this->master);

        foreach (['view_agents', 'create_agents', 'edit_agents', 'delete_agents', 'export_agents'] as $permission) {
            $this->assertTrue($this->master->can($permission), $permission);
        }
        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue($this->master->can($ability, Agent::class));
        }
        foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $this->assertTrue($this->master->can($ability, $agent), $ability);
        }
    }

    // ------------------------------------------------------------------ le master est aussi agent

    public function test_the_master_links_a_new_agent_record_to_his_own_account(): void
    {
        $this->act($this->master);

        Livewire::test(CreateAgent::class)
            ->fillForm($this->agentData(['name' => 'Le Master Agent', 'user_id' => $this->master->id]))
            ->call('create')->assertHasNoFormErrors();

        $agent = Agent::where('name', 'Le Master Agent')->firstOrFail();
        $this->assertSame($this->master->id, $agent->user_id);
        $this->assertTrue($this->master->fresh()->agent()->exists());
        $this->assertTrue($this->master->fresh()->isMaster(), 'il garde son rôle de gestion');
        $this->assertTrue($this->master->can('create_agents'));
    }

    public function test_the_master_can_link_and_unlink_an_existing_record(): void
    {
        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create());
        $this->act($this->master);

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])->fillForm(['user_id' => $this->master->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($this->master->id, $agent->fresh()->user_id);

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])->fillForm(['user_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($agent->fresh()->user_id);
    }

    public function test_an_account_cannot_be_linked_to_two_agents(): void
    {
        $this->act($this->master);
        Livewire::test(CreateAgent::class)->fillForm($this->agentData(['user_id' => $this->master->id]))->call('create')->assertHasNoFormErrors();

        Livewire::test(CreateAgent::class)->fillForm($this->agentData(['user_id' => $this->master->id]))->call('create')->assertHasFormErrors(['user_id']);

        $this->assertSame(1, Agent::where('user_id', $this->master->id)->count());
    }

    public function test_an_account_of_another_company_can_neither_be_proposed_nor_linked(): void
    {
        $other = Tenant::create(['name' => 'Société B']);
        $foreign = $this->userIn($other, 'master');
        $this->act($this->master);

        Livewire::test(CreateAgent::class)->fillForm($this->agentData(['user_id' => $foreign->id]))->call('create')->assertHasFormErrors(['user_id']);

        $this->assertSame(0, Agent::withoutGlobalScopes()->where('user_id', $foreign->id)->count());
    }

    public function test_the_admin_can_link_an_account_too(): void
    {
        $admin = $this->userIn($this->tenant, 'admin');
        $this->act($admin);

        Livewire::test(CreateAgent::class)->assertFormFieldIsVisible('user_id')
            ->fillForm($this->agentData(['name' => 'Fiche admin', 'user_id' => $admin->id]))->call('create')->assertHasNoFormErrors();

        $this->assertSame($admin->id, Agent::where('name', 'Fiche admin')->value('user_id'));
    }

    public function test_roles_without_admin_rights_neither_see_nor_can_set_the_link(): void
    {
        foreach (['commercial'] as $role) { // le comptable ne peut pas ouvrir la page de création
            $user = $this->userIn($this->tenant, $role);
            $this->act($user);

            Livewire::test(CreateAgent::class)->assertFormFieldIsHidden('user_id');

            // Valeur forgée dans la requête : ignorée, jamais enregistrée.
            Livewire::test(CreateAgent::class)->set('data.user_id', $user->id)->fillForm($this->agentData(['name' => "Forgé {$role}"]))->call('create');
            $agent = Agent::where('name', "Forgé {$role}")->first();
            if ($agent) {
                $this->assertNull($agent->user_id, "{$role} ne peut pas lier un compte");
            }
        }
    }

    public function test_the_accountant_cannot_even_open_the_agent_creation_page(): void
    {
        $this->act($this->userIn($this->tenant, 'comptable'));

        Livewire::test(CreateAgent::class)->assertForbidden();
    }

    public function test_a_non_master_editing_an_agent_never_removes_an_existing_link(): void
    {
        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create(['user_id' => $this->master->id]));
        $this->act($this->userIn($this->tenant, 'admin'));

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])->fillForm(['name' => 'Renommé'])->call('save');

        $this->assertSame($this->master->id, $agent->fresh()->user_id, 'le lien reste intact');
        $this->assertSame('Renommé', $agent->fresh()->name);
    }

    // ------------------------------------------------------------------ l'espace agent du master

    public function test_the_linked_master_gets_his_agent_space_with_his_own_data_only(): void
    {
        $mine = TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create(['name' => 'Fiche du master', 'user_id' => $this->master->id]));
        TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create(['name' => 'Autre agent']));
        $this->act($this->master, 'agent');

        $this->assertTrue($this->master->canAccessPanel(Filament::getPanel('agent')));
        $component = Livewire::test(AgentDashboard::class)->assertSuccessful();
        $this->assertSame($mine->id, $component->get('agent')->id);
        $this->get(Filament::getPanel('agent')->getUrl())->assertOk();
    }

    public function test_the_menu_entry_exists_only_for_an_account_linked_to_an_agent(): void
    {
        $this->act($this->master);
        $labels = fn () => collect(Filament::getNavigation())->flatMap(fn ($g) => collect($g->getItems())->map(fn ($i) => $i->getLabel()))->all();

        $this->assertNotContains('Mon espace agent', $labels());

        TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create(['user_id' => $this->master->id]));
        $this->assertContains('Mon espace agent', $labels());

        $this->act($this->userIn($this->tenant, 'admin'));
        $this->assertNotContains('Mon espace agent', $labels());
    }

    public function test_the_master_as_agent_counts_in_the_plan_limit_like_any_agent(): void
    {
        SubscriptionPlan::query()->where('slug', 'essentiel')->update(['max_agents' => 1]);
        app(SubscriptionManager::class)->activate($this->tenant, SubscriptionPlan::where('slug', 'essentiel')->first(), SubscriptionCycle::Monthly);
        $this->act($this->master);
        Livewire::test(CreateAgent::class)->fillForm($this->agentData(['user_id' => $this->master->id]))->call('create');

        $this->assertThrows(fn () => TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create()), SubscriptionLimitReached::class);
    }

    // ------------------------------------------------------------------ mobile

    public function test_on_mobile_master_and_admin_can_link_the_account_but_a_commercial_cannot(): void
    {
        $this->act($this->master);
        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->set('data.name', 'Master mobile')->set('data.phone', '0701020304')->set('data.status', 'active')
            ->set('data.debt_limit', '100000')->set('data.commission_rate', '5')->set('data.user_id', (string) $this->master->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($this->master->id, Agent::where('name', 'Master mobile')->value('user_id'));

        $admin = $this->userIn($this->tenant, 'admin');
        $this->act($admin);
        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->set('data.name', 'Admin mobile')->set('data.phone', '0701020305')->set('data.status', 'active')
            ->set('data.debt_limit', '100000')->set('data.commission_rate', '5')->set('data.user_id', (string) $admin->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($admin->id, Agent::where('name', 'Admin mobile')->value('user_id'));

        $commercial = $this->userIn($this->tenant, 'commercial');
        $this->act($commercial);
        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->set('data.name', 'Commercial mobile')->set('data.phone', '0701020306')->set('data.status', 'active')
            ->set('data.debt_limit', '100000')->set('data.commission_rate', '5')->set('data.user_id', (string) $commercial->id)
            ->call('save');
        $this->assertNull(Agent::where('name', 'Commercial mobile')->value('user_id'), 'champ absent pour un rôle non administrateur : valeur forgée ignorée');
    }
}
