<?php

namespace Tests\E2E;

use App\Models\Agent;
use App\Models\User;
use App\Models\UvTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->master = User::where('email', 'master@uv-system.com')->first()
            ?? User::factory()->create();
    }

    /** @test */
    public function dashboard_displays_correct_statistics(): void
    {
        $this->actingAs($this->master);

        // Setup: créer données de test
        $agent1 = Agent::create([
            'name' => 'Agent Stats 1',
            'phone' => '0611111111',
            'type' => 'standard',
            'status' => 'active',
        ]);

        $agent2 = Agent::create([
            'name' => 'Agent Stats 2',
            'phone' => '0622222222',
            'type' => 'premium',
            'status' => 'active',
        ]);

        // Créer transactions
        UvTransaction::create([
            'agent_id' => $agent1->id,
            'amount' => 100000,
            'type' => 'distribution',
            'transaction_date' => now(),
        ]);

        UvTransaction::create([
            'agent_id' => $agent2->id,
            'amount' => 200000,
            'type' => 'distribution',
            'transaction_date' => now(),
        ]);

        // Vérifier dashboard
        $response = $this->getJson('/api/dashboard');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'total_uv',
                'total_amount',
                'total_agents',
                'total_debt',
            ],
        ]);
    }

    /** @test */
    public function charts_endpoint_returns_valid_data(): void
    {
        $this->actingAs($this->master);

        $response = $this->getJson('/api/dashboard/charts');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'daily',
                'monthly',
            ],
        ]);
    }

    /** @test */
    public function top_agents_endpoint_works(): void
    {
        $this->actingAs($this->master);

        // Créer agents avec transactions
        for ($i = 1; $i <= 5; $i++) {
            $agent = Agent::create([
                'name' => "Top Agent $i",
                'phone' => "061111111$i",
                'type' => 'standard',
                'status' => 'active',
            ]);

            UvTransaction::create([
                'agent_id' => $agent->id,
                'amount' => $i * 10000,
                'type' => 'distribution',
                'transaction_date' => now(),
            ]);
        }

        $response = $this->getJson('/api/dashboard/top-agents');
        $response->assertStatus(200);
    }

    /** @test */
    public function alerts_endpoint_shows_overdue_debts(): void
    {
        $this->actingAs($this->master);

        // Créer agent avec dette élevée
        $agent = Agent::create([
            'name' => 'Agent Alerte',
            'phone' => '0699999999',
            'type' => 'standard',
            'debt_limit' => 50000,
            'status' => 'active',
        ]);

        // Créer transaction qui dépasse le plafond
        UvTransaction::create([
            'agent_id' => $agent->id,
            'amount' => 100000,
            'type' => 'distribution',
            'transaction_date' => now()->subDays(30),
        ]);

        $response = $this->getJson('/api/dashboard/alerts');
        $response->assertStatus(200);
    }

    /** @test */
    public function realtime_stats_endpoint(): void
    {
        $this->actingAs($this->master);

        $response = $this->getJson('/api/dashboard/realtime');
        $response->assertStatus(200);
    }

    /** @test */
    public function transaction_stats_by_period(): void
    {
        $this->actingAs($this->master);

        // Stats journalières
        $response = $this->getJson('/api/transactions/stats?period=daily');
        $response->assertStatus(200);

        // Stats mensuelles
        $response = $this->getJson('/api/transactions/stats?period=monthly');
        $response->assertStatus(200);
    }
}
