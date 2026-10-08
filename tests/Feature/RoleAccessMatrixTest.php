<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Matrice d'accès des 5 rôles d'entreprise aux écrans sensibles (web /master et mobile /m) : 200 = autorisé,
 * 403 = refusé par le serveur. Elle fige le résultat de l'audit du 2026-10-08 : toute permission ajoutée ou
 * retirée par erreur fait échouer un cas précis. Un compte par test (voir TenantBoundaryRegressionTest).
 * La plateforme (/platform) est refusée à tous.
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['master', 'admin', 'comptable', 'commercial', User::ROLE_READ_ONLY];

    /** URL => [master, admin, comptable, commercial, lecture_seule] */
    private const MATRIX = [
        // Administration de l'entreprise
        '/master/users' => [200, 200, 403, 403, 403],
        '/master/users/create' => [200, 200, 403, 403, 403],
        '/master/payment-methods' => [200, 200, 403, 403, 403],
        '/master/palier-commissions' => [200, 200, 403, 403, 403],
        '/master/import-page' => [200, 200, 403, 403, 403],
        '/master/mon-abonnement' => [200, 200, 403, 403, 403],
        '/master/profil-entreprise' => [200, 200, 403, 403, 403],
        '/master/activity-logs' => [200, 200, 403, 403, 403],
        '/master/vente-uvs' => [403, 403, 403, 403, 403],
        '/master/remboursement-agents' => [403, 403, 403, 403, 403],
        // Création : selon la permission de chaque métier
        '/master/agents/create' => [200, 200, 403, 200, 403],
        '/master/uv-transactions/create' => [200, 200, 403, 200, 403],
        '/master/agent-soldes/create' => [200, 200, 403, 403, 403],
        '/master/agent-partenaires/create' => [200, 200, 403, 200, 403],
        // Consultation
        '/master' => [200, 200, 200, 200, 200],
        '/master/agents' => [200, 200, 200, 200, 200],
        '/master/uv-transactions' => [200, 200, 200, 200, 200],
        '/master/remboursements' => [200, 200, 200, 200, 200],
        '/master/ma-caisse' => [200, 200, 200, 403, 200],
        '/master/charges' => [200, 200, 200, 403, 200],
        '/master/reports-page' => [200, 200, 200, 200, 403],
        // Mobile
        '/m' => [200, 200, 200, 200, 200],
        '/m/agents' => [200, 200, 200, 200, 200],
        '/m/ventes' => [200, 200, 200, 200, 200],
        '/m/ventes/nouvelle' => [200, 200, 403, 200, 403],
        '/m/remboursements/nouveau' => [200, 200, 200, 403, 403],
        '/m/approvisionnements/nouveau' => [200, 200, 403, 200, 403],
        '/m/caisse/mouvement' => [200, 200, 200, 403, 403],
        '/m/charges/nouvelle' => [200, 200, 200, 403, 403],
        '/m/rapports' => [200, 200, 200, 200, 403],
        '/m/activite' => [200, 200, 403, 403, 403],
        '/m/import' => [200, 200, 403, 403, 403],
        '/m/abonnement' => [200, 200, 403, 403, 403],
        '/m/profil-entreprise' => [200, 200, 403, 403, 403],
        '/m/diagnostic-notifications' => [200, 200, 403, 403, 403],
        '/m/g/utilisateurs' => [200, 200, 403, 403, 403],
        '/m/sauvegardes' => [403, 403, 403, 403, 403],
        '/m/espace-agent' => [403, 403, 403, 403, 403], // aucune fiche agent liée dans ce test
        // Plateforme : jamais pour un compte d'entreprise
        '/platform' => [403, 403, 403, 403, 403],
        '/platform/tenants' => [403, 403, 403, 403, 403],
        '/platform/backup-page' => [403, 403, 403, 403, 403],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
    }

    /** @return array<string, array{string, string, int}> */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::MATRIX as $url => $expected) {
            foreach (self::ROLES as $i => $role) {
                $cases["$role $url"] = [$role, $url, $expected[$i]];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_the_role_gets_the_expected_answer(string $role, string $url, int $expected): void
    {
        $tenant = Tenant::create(['name' => 'Société A']);
        $user = TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });

        $this->assertSame($expected, $this->actingAs($user)->get($url)->getStatusCode(), "$role $url");
    }

    public function test_guests_are_sent_to_the_login_page_never_served(): void
    {
        foreach (['/master', '/master/agents', '/m', '/m/agents', '/m/exports/commissions/pdf', '/security/sessions'] as $url) {
            $status = $this->get($url)->getStatusCode();
            $this->assertContains($status, [302, 401, 403], "$url -> $status");
        }
        $this->getJson('/api/agents')->assertUnauthorized();
    }

    public function test_the_platform_super_admin_is_kept_out_of_the_company_panels(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        TenantContext::runAs(null, fn () => $super->assignRole(User::ROLE_SUPER_ADMIN));

        foreach (['/master', '/master/agents', '/m', '/m/agents'] as $url) {
            $this->assertSame(403, $this->actingAs($super)->get($url)->getStatusCode(), $url);
        }
        $this->assertSame(200, $this->actingAs($super)->get('/platform/tenants')->getStatusCode());
    }
}
