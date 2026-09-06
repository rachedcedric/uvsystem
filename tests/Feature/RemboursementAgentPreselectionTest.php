<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RemboursementAgentPreselectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view_uv_transactions', 'create_uv_transactions', 'view_remboursements', 'create_remboursements'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $role->givePermissionTo(['view_uv_transactions', 'create_uv_transactions', 'view_remboursements', 'create_remboursements']);

        $user = User::factory()->create();
        $user->assignRole('master');
        $this->actingAs($user);
    }

    /** Depuis une notification de rappel : /admin/remboursements/create?agent_id=XX présélectionne l'agent */
    public function test_agent_id_query_param_preselects_the_agent(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'name' => 'KOFFI Jean']);

        $response = $this->get('/admin/remboursements/create?agent_id='.$agent->id);

        $response->assertOk();
        $response->assertSee('KOFFI Jean');
        // La valeur présélectionnée est bien dans l'état initial du formulaire Livewire.
        $this->assertStringContainsString(
            '&quot;agent_id&quot;:'.$agent->id,
            $response->getContent()
        );
    }

    /** Un agent_id invalide (inexistant) ne casse pas le formulaire */
    public function test_invalid_agent_id_query_param_is_ignored_safely(): void
    {
        $response = $this->get('/admin/remboursements/create?agent_id=999999');

        $response->assertOk();
        $this->assertStringNotContainsString('&quot;agent_id&quot;:999999', $response->getContent());
    }

    /** Un agent inactif n'est jamais présélectionné (mêmes règles que le select) */
    public function test_inactive_agent_id_query_param_is_ignored(): void
    {
        $agent = Agent::factory()->create(['status' => 'inactive']);

        $response = $this->get('/admin/remboursements/create?agent_id='.$agent->id);

        $response->assertOk();
        $this->assertStringNotContainsString('&quot;agent_id&quot;:'.$agent->id, $response->getContent());
    }

    /** Sans le paramètre, comportement normal (aucun agent présélectionné) */
    public function test_no_query_param_means_no_preselection(): void
    {
        $response = $this->get('/admin/remboursements/create');

        $response->assertOk();
        $this->assertStringContainsString('&quot;agent_id&quot;:null', $response->getContent());
    }
}
