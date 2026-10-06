<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Le webhook e-mail n'a pas d'utilisateur : il cherche l'adresse HORS contexte pour identifier le tenant
 * propriétaire, puis modifie DANS ce tenant — ni refus systématique, ni mise à jour globale.
 */
class TenantWebhookEmailTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-de-test';

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['services.resend.webhook_secret' => self::SECRET]);

        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
    }

    private function user(Tenant $tenant, string $email): User
    {
        return TenantContext::runAs($tenant->id, fn () => User::factory()->create(['email' => $email]));
    }

    private function agent(Tenant $tenant, string $email): Agent
    {
        return TenantContext::runAs($tenant->id, fn () => Agent::factory()->create(['email' => $email]));
    }

    /** Requête réelle, sans utilisateur, signée comme Resend/Svix. */
    private function bounce(array|string $to): void
    {
        $body = json_encode(['type' => 'email.bounced', 'data' => ['email_id' => 'e1', 'to' => $to, 'bounce_type' => 'Permanent']]);
        $id = 'msg_1';
        $timestamp = (string) time();
        $signature = 'v1='.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", self::SECRET, true));

        $this->call('POST', '/api/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $timestamp, 'HTTP_SVIX_SIGNATURE' => $signature,
        ], $body)->assertOk();
    }

    public function test_a_bounce_updates_only_the_owner_tenants_records(): void
    {
        $userA = $this->user($this->a, 'rebond-a@example.com');
        $userB = $this->user($this->b, 'sain-b@example.com');
        $agentA = $this->agent($this->a, 'agent-a@example.com');
        $agentB = $this->agent($this->b, 'agent-b@example.com');

        $this->bounce(['rebond-a@example.com', 'agent-a@example.com']);   // Resend envoie une LISTE

        $this->assertNull(User::withoutGlobalScopes()->find($userA->id)->email_verified_at, 'Le compte du tenant propriétaire est mis à jour (le rebond n\'est plus refusé).');
        $this->assertNotNull(User::withoutGlobalScopes()->find($userB->id)->email_verified_at, 'Un autre tenant n\'est pas touché.');
        $this->assertFalse((bool) Agent::withoutGlobalScopes()->find($agentA->id)->email_valid);
        $this->assertTrue((bool) Agent::withoutGlobalScopes()->find($agentB->id)->email_valid);
    }

    public function test_a_string_recipient_is_handled_too(): void
    {
        $user = $this->user($this->a, 'seul@example.com');

        $this->bounce('seul@example.com');

        $this->assertNull(User::withoutGlobalScopes()->find($user->id)->email_verified_at);
    }

    public function test_an_agent_address_shared_by_two_tenants_is_ambiguous_and_modifies_nobody(): void
    {
        Log::spy();
        $agentA = $this->agent($this->a, 'partage@example.com');
        $agentB = $this->agent($this->b, 'partage@example.com');

        $this->bounce('partage@example.com');

        $this->assertTrue((bool) Agent::withoutGlobalScopes()->find($agentA->id)->email_valid);
        $this->assertTrue((bool) Agent::withoutGlobalScopes()->find($agentB->id)->email_valid);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'partagée entre plusieurs tenants'))->once();
    }

    public function test_a_platform_account_without_tenant_is_handled_and_unknown_addresses_are_harmless(): void
    {
        $super = TenantContext::runAs(null, fn () => User::factory()->create(['email' => 'root@example.com']));
        $other = $this->user($this->a, 'autre@example.com');

        $this->bounce('root@example.com');
        $this->bounce('inconnu@example.com');

        $this->assertNull(User::withoutGlobalScopes()->find($super->id)->email_verified_at);
        $this->assertNotNull(User::withoutGlobalScopes()->find($other->id)->email_verified_at);
    }

    public function test_an_unsigned_request_is_still_refused(): void
    {
        $user = $this->user($this->a, 'a@example.com');

        $this->postJson('/api/webhooks/resend', ['type' => 'email.bounced', 'data' => ['to' => ['a@example.com']]])->assertStatus(401);

        $this->assertNotNull(User::withoutGlobalScopes()->find($user->id)->email_verified_at);
    }
}
