<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Remboursements\Create as RemboursementCreate;
use App\Livewire\Mobile\Ventes\Create as VenteCreate;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use App\Tenancy\CrossTenantReference;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Non-régression du cloisonnement entre entreprises (audit du 2026-10-08). Chaque sonde vise une ressource de
 * l'entreprise B avec un compte de l'entreprise A, et un TÉMOIN prouve que la même URL répond bien pour son
 * propriétaire (sans témoin, un 404 pourrait n'être qu'une route inexistante).
 *
 * Un compte par test : le panneau Filament conserve l'utilisateur de la première requête d'un même test.
 */
class TenantBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private Agent $agentB;

    private Fournisseur $fournisseurB;

    private User $userB;

    private User $masterB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
        $this->masterB = $this->userIn($this->b, 'master');
        $this->userB = $this->userIn($this->b, 'comptable');
        [$this->agentB, $this->fournisseurB] = TenantContext::runAs($this->b->id, fn () => [
            Agent::factory()->create(['name' => 'Agent Bravo']),
            Fournisseur::factory()->create(['name' => 'Fournisseur Bravo', 'type' => 'AGENT_PREMIUM']),
        ]);
    }

    private function userIn(Tenant $tenant, string $role): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    /** @return array<string, array{string}> URLs d'une ressource de B (le jeton {agent} etc. est remplacé à l'exécution) */
    public static function foreignUrls(): array
    {
        $urls = [
            '/m/agents/{agent}', '/m/scoring/{agent}', '/m/dettes/agent/{agent}', '/m/dettes/fournisseur/{fournisseur}/payer',
            '/m/exports/fiche-agent/{agent}', '/m/exports/dette-agent/{agent}/pdf',
            '/m/g/agents/{agent}', '/m/g/agents/{agent}/modifier', '/m/g/fournisseurs/{fournisseur}', '/m/g/fournisseurs/{fournisseur}/modifier',
            '/m/g/utilisateurs/{user}', '/m/g/utilisateurs/{user}/modifier',
            '/master/agents/{agent}/edit', '/master/fournisseurs/{fournisseur}/edit', '/master/users/{user}/edit',
            '/api/agents/{agent}', '/api/agents/{agent}/situation', '/api/agents/{agent}/transactions', '/api/agents/{agent}/remboursements',
            '/api/fournisseurs/{fournisseur}',
        ];

        return array_combine($urls, array_map(fn ($u) => [$u], $urls));
    }

    private function resolve(string $url): string
    {
        return strtr($url, ['{agent}' => $this->agentB->id, '{fournisseur}' => $this->fournisseurB->id, '{user}' => $this->userB->id]);
    }

    private function request(User $user, string $url)
    {
        if (str_starts_with($url, '/api')) {
            Sanctum::actingAs($user, ['*']);

            return $this->getJson($url);
        }

        return $this->actingAs($user)->get($url);
    }

    #[DataProvider('foreignUrls')]
    public function test_the_master_of_another_company_never_reaches_it(string $url): void
    {
        $response = $this->request($this->userIn($this->a, 'master'), $this->resolve($url));

        $this->assertContains($response->getStatusCode(), [403, 404], "$url -> ".$response->getStatusCode());
    }

    #[DataProvider('foreignUrls')]
    public function test_witness_the_owner_company_does_reach_it(string $url): void
    {
        // Les URL de modification d'un utilisateur ne sont ouvertes qu'à master/admin : le témoin est le master de B.
        $response = $this->request($this->masterB, $this->resolve($url));

        $this->assertSame(200, $response->getStatusCode(), "$url -> ".$response->getStatusCode());
    }

    #[DataProvider('limitedRoles')]
    public function test_no_tenant_role_reaches_the_other_company_even_for_reads(string $role): void
    {
        $user = $this->userIn($this->a, $role);

        foreach (['/m/agents/'.$this->agentB->id, '/m/dettes/agent/'.$this->agentB->id, '/m/scoring/'.$this->agentB->id] as $url) {
            $this->assertContains($this->actingAs($user)->get($url)->getStatusCode(), [403, 404], "$role $url");
        }
    }

    /** @return array<string, array{string}> */
    public static function limitedRoles(): array
    {
        return array_combine(['admin', 'comptable', 'commercial', User::ROLE_READ_ONLY], array_map(fn ($r) => [$r], ['admin', 'comptable', 'commercial', User::ROLE_READ_ONLY]));
    }

    public function test_lists_and_searches_only_contain_the_own_company(): void
    {
        TenantContext::runAs($this->a->id, fn () => Agent::factory()->create(['name' => 'Agent Alpha']));
        $master = $this->userIn($this->a, 'master');

        $this->actingAs($master)->get('/m/agents')->assertOk()->assertSee('Agent Alpha')->assertDontSee('Agent Bravo');
        $this->actingAs($master)->get('/master/agents')->assertOk()->assertDontSee('Agent Bravo');
        $this->actingAs($master)->get('/m/agents?q=Bravo')->assertOk()->assertDontSee('Agent Bravo');
        $this->actingAs($master)->get('/m/g/fournisseurs')->assertOk()->assertDontSee('Fournisseur Bravo');
        $this->actingAs($master)->get('/m/g/utilisateurs')->assertOk()->assertDontSee($this->userB->email);
    }

    public function test_an_api_list_and_write_never_touch_the_other_company(): void
    {
        $master = $this->userIn($this->a, 'master');
        Sanctum::actingAs($master, ['*']);

        $names = collect($this->getJson('/api/agents')->assertOk()->json('data'))->pluck('name');
        $this->assertNotContains('Agent Bravo', $names->all());

        $this->putJson('/api/agents/'.$this->agentB->id, ['name' => 'Piraté'])->assertNotFound();
        $this->deleteJson('/api/agents/'.$this->agentB->id)->assertNotFound();
        $this->assertSame('Agent Bravo', TenantContext::runAs($this->b->id, fn () => Agent::query()->find($this->agentB->id))->name);
    }

    public function test_forged_livewire_payloads_with_a_foreign_id_create_nothing(): void
    {
        $this->actingAs($this->userIn($this->a, 'master'));
        $count = fn () => TenantContext::withoutTenancy(fn () => UvTransaction::withoutGlobalScopes()->count() + Remboursement::withoutGlobalScopes()->count());
        $before = $count();

        Livewire::test(VenteCreate::class)->set('agentId', $this->agentB->id)->set('amount', '10000')->call('save');
        Livewire::test(RemboursementCreate::class)->set('agentId', $this->agentB->id)->set('amount', '10000')->call('save');

        $this->assertSame($before, $count());
    }

    public function test_a_write_cannot_attach_a_foreign_parent(): void
    {
        $agentA = TenantContext::runAs($this->a->id, fn () => Agent::factory()->create());
        $this->actingAs($this->userIn($this->a, 'master'));

        $this->expectException(CrossTenantReference::class);
        UvTransaction::create([
            'agent_id' => $this->agentB->id, 'fournisseur_id' => $this->fournisseurB->id, 'user_id' => $agentA->user_id,
            'transaction_type' => 'distribution', 'amount' => 1000, 'transaction_date' => now(), 'status' => 'completed', 'reference' => 'V-CROSS',
        ]);
    }
}
