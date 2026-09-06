<?php

namespace Tests\E2E;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @test */
    public function complete_auth_flow_with_sanctum(): void
    {
        // 1. Créer un utilisateur
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        // 2. Login via API
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email'],
        ]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // 3. Accéder à une route protégée avec le token
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/auth/user');

        $response->assertStatus(200);
        $response->assertJsonPath('email', 'test@example.com');

        // 4. Logout
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/auth/logout');

        $response->assertStatus(200);

        // 5. Token invalidé après logout
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/auth/user');

        $response->assertStatus(401);
    }

    /** @test */
    public function invalid_credentials_rejected(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('correctpassword'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function protected_routes_require_authentication(): void
    {
        // Sans token
        $this->getJson('/api/agents')->assertStatus(401);
        $this->getJson('/api/transactions')->assertStatus(401);
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    /** @test */
    public function token_refresh_flow(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/refresh');

        // Selon l'implémentation
        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /** @test */
    public function role_based_access_control(): void
    {
        // Master user
        $master = User::where('email', 'master@uv-system.com')->first()
            ?? User::factory()->create();

        Sanctum::actingAs($master);

        // Master peut accéder au dashboard
        $this->getJson('/api/dashboard')->assertStatus(200);

        // Master peut lister les agents
        $this->getJson('/api/agents')->assertStatus(200);
    }
}
