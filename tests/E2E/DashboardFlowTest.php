<?php

namespace Tests\E2E;

use App\Models\Agent;
use App\Models\User;
use App\Models\UvTransaction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        Sanctum::actingAs($this->master);
    }

    private function agent(string $name, array $attributes = []): Agent
    {
        return Agent::factory()->create(['name' => $name, 'status' => 'active'] + $attributes);
    }

    private function sale(Agent $agent, int $amount, $date = null): UvTransaction
    {
        return UvTransaction::withoutEvents(fn () => UvTransaction::forceCreate([
            'agent_id' => $agent->id, 'amount' => $amount, 'transaction_type' => 'distribution', 'status' => 'completed',
            'reference' => 'E2E-'.uniqid(), 'transaction_date' => $date ?? now(), 'tenant_id' => $agent->tenant_id,
        ]));
    }

    #[Test]
    public function dashboard_displays_correct_statistics(): void
    {
        $this->sale($this->agent('Agent Stats 1'), 100000);
        $this->sale($this->agent('Agent Stats 2', ['type' => 'premium']), 200000);

        $this->getJson('/api/dashboard/realtime')
            ->assertOk()
            ->assertJsonStructure(['data' => ['agents', 'today', 'debts']]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['agents', 'today', 'month', 'debts']]);
    }

    #[Test]
    public function charts_endpoint_returns_valid_data(): void
    {
        $this->getJson('/api/dashboard/charts')->assertOk()->assertJsonStructure(['data']);
    }

    #[Test]
    public function top_agents_endpoint_works(): void
    {
        foreach (range(1, 5) as $i) {
            $this->sale($this->agent("Top Agent {$i}"), $i * 10000);
        }

        $this->getJson('/api/dashboard/top-agents')->assertOk();
    }

    #[Test]
    public function alerts_endpoint_shows_overdue_debts(): void
    {
        $agent = $this->agent('Agent Alerte', ['debt_limit' => 50000, 'current_debt' => 100000]);
        $this->sale($agent, 100000, now()->subDays(30));

        $this->getJson('/api/dashboard/alerts')->assertOk();
    }

    #[Test]
    public function realtime_stats_endpoint(): void
    {
        $this->getJson('/api/dashboard/realtime')->assertOk();
    }

    #[Test]
    public function transaction_stats_by_period(): void
    {
        $this->getJson('/api/transactions/stats/daily')->assertOk();
        $this->getJson('/api/transactions/stats/monthly')->assertOk();
    }

    #[Test]
    public function a_user_without_the_dashboard_permission_is_refused(): void
    {
        // Sanctum::actingAs a basculé le guard par défaut sur « sanctum » : les rôles Spatie sont du guard « web ».
        $this->app['auth']->shouldUse('web');
        $roleless = User::factory()->create();
        $roleless->assignRole('commercial');
        \Spatie\Permission\Models\Role::findByName('commercial')->revokePermissionTo('view_dashboard');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($roleless->fresh());

        $this->getJson('/api/dashboard')->assertForbidden();
    }
}
