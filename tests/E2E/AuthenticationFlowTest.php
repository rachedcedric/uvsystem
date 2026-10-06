<?php

namespace Tests\E2E;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function master(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('master');

        return $user;
    }

    /** @test */
    public function complete_auth_flow_with_sanctum(): void
    {
        // 1. Un compte métier (l'inscription publique n'existe plus)
        $this->master(['email' => 'test@example.com', 'password' => bcrypt('password123')]);

        // 2. Login via API
        $response = $this->postJson('/api/auth/login', ['email' => 'test@example.com', 'password' => 'password123']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'roles']]]);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // 3. Accéder à une route protégée avec le token
        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/auth/user')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'test@example.com');

        // 4. Logout
        $this->withHeader('Authorization', "Bearer $token")->postJson('/api/auth/logout')->assertStatus(200);

        // 5. Le token est révoqué : on repart d'un nouveau cycle d'authentification
        $this->assertSame(0, User::where('email', 'test@example.com')->first()->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $token")->getJson('/api/auth/user')->assertStatus(401);
    }

    /** @test */
    public function invalid_credentials_rejected(): void
    {
        $this->master(['email' => 'user@example.com', 'password' => bcrypt('correctpassword')]);

        // Laravel répond 422 (erreur de validation) pour des identifiants incorrects
        $this->postJson('/api/auth/login', ['email' => 'user@example.com', 'password' => 'wrongpassword'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** @test */
    public function an_account_without_a_business_role_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'orphelin@example.com', 'password' => bcrypt('password123')]);

        $this->postJson('/api/auth/login', ['email' => 'orphelin@example.com', 'password' => 'password123'])->assertStatus(422);
    }

    /** @test */
    public function protected_routes_require_authentication(): void
    {
        $this->getJson('/api/agents')->assertStatus(401);
        $this->getJson('/api/transactions')->assertStatus(401);
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    /** @test */
    public function token_refresh_flow(): void
    {
        $user = $this->master();
        $old = $user->createToken('ancien');
        $this->withHeader('Authorization', 'Bearer '.$old->plainTextToken);

        $response = $this->postJson('/api/auth/refresh', ['device_name' => 'telephone']);

        $response->assertStatus(200)->assertJsonStructure(['data' => ['token']]);
        $this->assertNotSame($old->plainTextToken, $response->json('data.token'));
        $this->assertNull($old->accessToken->fresh(), 'L\'ancien token est révoqué par le renouvellement.');
    }

    /** @test */
    public function role_based_access_control(): void
    {
        Sanctum::actingAs($this->master());

        $this->getJson('/api/dashboard')->assertStatus(200);
        $this->getJson('/api/agents')->assertStatus(200);
    }
}
