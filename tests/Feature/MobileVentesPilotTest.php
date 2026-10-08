<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Ventes\Index;
use App\Livewire\Mobile\Ventes\Show;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Écran pilote « Ventes UV » (liste, fiche, création, modification) : droits par rôle appliqués côté serveur ET
 * reflétés dans l'interface, états (vide, filtré, annulé, consultation seule), isolation entre entreprises.
 * Un compte par test : le panneau Filament conserve l'utilisateur de la première requête d'un même test.
 */
class MobileVentesPilotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private UvTransaction $sale;

    private UvTransaction $cancelled;

    private UvTransaction $saleB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
    }

    private function userIn(Tenant $tenant, string $role): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    private function sale(Tenant $tenant, string $agentName, int $amount, string $status = 'completed', ?string $ref = null): UvTransaction
    {
        return TenantContext::runAs($tenant->id, function () use ($agentName, $amount, $status, $ref) {
            $owner = User::query()->first() ?? User::factory()->create();
            $agent = Agent::factory()->create(['name' => $agentName, 'status' => 'active']);
            $fournisseur = Fournisseur::factory()->create();

            return UvTransaction::withoutEvents(fn () => UvTransaction::create([
                'agent_id' => $agent->id, 'fournisseur_id' => $fournisseur->id, 'user_id' => $owner->id,
                'transaction_type' => 'distribution', 'amount' => $amount, 'transaction_date' => now(), 'status' => $status,
                'reference' => $ref ?? 'V-'.strtoupper(substr(md5($agentName.$amount), 0, 6)),
            ]));
        });
    }

    private function seedSales(): void
    {
        $this->userIn($this->a, 'master'); // propriétaire des lignes
        $this->sale = $this->sale($this->a, 'Agent Alpha', 125000, 'completed', 'V-ALPHA1');
        $this->cancelled = $this->sale($this->a, 'Agent Annulé', 40000, 'cancelled', 'V-CANC01');
        $this->saleB = $this->sale($this->b, 'Agent Bravo', 99000, 'completed', 'V-BRAVO1');
    }

    /** @return array<string, array{string, bool, bool}> rôle, peut créer, peut supprimer */
    public static function roles(): array
    {
        return [
            'master' => ['master', true, true],
            'admin' => ['admin', true, true],
            'comptable' => ['comptable', false, false],
            'commercial' => ['commercial', true, false],
            'lecture seule' => [User::ROLE_READ_ONLY, false, false],
        ];
    }

    // ------------------------------------------------------------------------------------- liste

    #[DataProvider('roles')]
    public function test_the_list_follows_the_role_in_content_and_in_actions(string $role, bool $canCreate): void
    {
        $this->seedSales();
        $user = $this->userIn($this->a, $role);

        $html = $this->actingAs($user)->get(route('m.ventes'))->assertOk()
            ->assertSee('Agent Alpha')
            ->assertDontSee('Agent Bravo')->assertDontSee('V-BRAVO1')
            ->getContent();

        $this->assertMatchesRegularExpression('/125[\s\x{00A0}\x{202F}]000/u', $html, 'montant complet, jamais abrégé');
        $this->assertSame($canCreate, str_contains($html, route('m.ventes.create')), "$role : bouton de création");
        $this->assertSame($role === User::ROLE_READ_ONLY, str_contains($html, 'Consultation seule'), "$role : mention de consultation seule");
        $this->assertStringContainsString('Annulée', $html);
        $this->assertStringContainsString('is-cancelled', $html);
    }

    public function test_an_empty_company_gets_a_helpful_empty_state_with_the_right_call_to_action(): void
    {
        $master = $this->userIn($this->a, 'master');
        $this->actingAs($master)->get(route('m.ventes'))->assertOk()
            ->assertSee('Aucune vente')->assertSee('Nouvelle vente');
    }

    public function test_a_read_only_user_with_no_sale_sees_no_creation_prompt(): void
    {
        $user = $this->userIn($this->a, User::ROLE_READ_ONLY);

        $html = $this->actingAs($user)->get(route('m.ventes'))->assertOk()->assertSee('Aucune vente')->getContent();
        $this->assertStringNotContainsString(route('m.ventes.create'), $html);
    }

    public function test_a_search_without_result_offers_to_reset_the_filters(): void
    {
        $this->seedSales();
        $user = $this->userIn($this->a, 'master');

        $this->actingAs($user)->get(route('m.ventes', ['q' => 'introuvable-zzz']))->assertOk()
            ->assertSee('Aucune vente pour ces filtres')->assertSee('Réinitialiser les filtres');
    }

    public function test_resetting_the_filters_restores_the_defaults(): void
    {
        $this->seedSales();

        Livewire::actingAs($this->userIn($this->a, 'master'))->test(Index::class)
            ->set('search', 'zzz')->set('period', 'all')
            ->call('resetFilters')
            ->assertSet('search', '')->assertSet('period', 'week');
    }

    public function test_the_list_does_not_run_more_queries_when_there_are_more_rows(): void
    {
        $this->userIn($this->a, 'master');
        $user = $this->userIn($this->a, 'master');
        $this->sale($this->a, 'Agent 1', 1000, 'completed', 'V-Q0001');

        $count = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($user)->test(Index::class)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count(); // préchauffage : le premier rendu charge les permissions et caches (hors sujet ici)
        $few = $count();
        foreach (range(2, 9) as $i) {
            $this->sale($this->a, "Agent $i", 1000 * $i, 'completed', "V-Q000$i");
        }

        $this->assertSame($few, $count(), 'pas de requête par ligne');
    }

    // ------------------------------------------------------------------------------------- fiche

    #[DataProvider('roles')]
    public function test_the_detail_shows_only_the_actions_the_role_may_perform(string $role, bool $canCreate, bool $canDelete): void
    {
        $this->seedSales();
        $user = $this->userIn($this->a, $role);

        $html = $this->actingAs($user)->get(route('m.ventes.show', $this->sale))->assertOk()->assertSee('V-ALPHA1')->getContent();

        $canUpdate = $user->can('update', $this->sale);
        $this->assertSame($canUpdate, str_contains($html, 'data-sheet-open="cancel-sheet"'), "$role : annuler");
        $this->assertSame($canUpdate, str_contains($html, route('m.crud.edit', ['ventes', $this->sale->id])), "$role : modifier");
        $this->assertSame($canDelete, str_contains($html, 'data-sheet-open="delete-sheet"'), "$role : supprimer");
        $this->assertSame($canCreate, str_contains($html, 'Dupliquer cette vente'), "$role : dupliquer");
        $this->assertSame($role === User::ROLE_READ_ONLY, str_contains($html, 'Consultation seule'));
    }

    public function test_a_cancelled_sale_is_labelled_and_offers_no_edit_or_cancel(): void
    {
        $this->seedSales();

        $html = $this->actingAs($this->userIn($this->a, 'master'))->get(route('m.ventes.show', $this->cancelled))->assertOk()->getContent();

        $this->assertStringContainsString('Annulée', $html);
        $this->assertStringContainsString('is-cancelled', $html);
        $this->assertStringNotContainsString('data-sheet-open="cancel-sheet"', $html);
        $this->assertStringNotContainsString('Dupliquer cette vente', $html);
        $this->assertStringNotContainsString(route('m.crud.edit', ['ventes', $this->cancelled->id]), $html);
    }

    public function test_the_confirmation_sheets_are_dialogs_wired_to_the_server_actions(): void
    {
        $this->seedSales();

        $html = $this->actingAs($this->userIn($this->a, 'master'))->get(route('m.ventes.show', $this->sale))->getContent();

        foreach (['cancel-sheet' => 'wire:click="cancel"', 'delete-sheet' => 'wire:click="delete"'] as $id => $action) {
            $this->assertStringContainsString('data-sheet-open="'.$id.'"', $html);
            $this->assertStringContainsString('id="'.$id.'"', $html);
            $this->assertStringContainsString($action, $html);
        }
        $this->assertMatchesRegularExpression('/id="cancel-sheet" class="m-sheet" role="dialog"/', $html);
        $this->assertMatchesRegularExpression('/id="delete-sheet" class="m-sheet" role="dialog"/', $html);
    }

    // ------------------------------------------------------------------------------------- création / modification

    #[DataProvider('roles')]
    public function test_creation_and_edition_screens_are_closed_to_roles_without_the_permission(string $role, bool $canCreate): void
    {
        $this->seedSales();
        $user = $this->userIn($this->a, $role);

        $create = $this->actingAs($user)->get(route('m.ventes.create'));
        $canCreate ? $create->assertOk() : $create->assertForbidden();

        $edit = $this->actingAs($user)->get(route('m.crud.edit', ['ventes', $this->sale->id]));
        $user->can('update', $this->sale) ? $edit->assertOk() : $edit->assertForbidden();
    }

    // ------------------------------------------------------------------------------------- droits des actions Livewire

    #[DataProvider('roles')]
    public function test_forged_livewire_actions_are_refused_without_the_permission(string $role, bool $canCreate, bool $canDelete): void
    {
        $this->seedSales();
        $user = $this->userIn($this->a, $role);
        $this->assertSame($canDelete, $user->can('delete', $this->sale), "$role : table des droits attendue");
        $this->assertSame($canCreate, $user->can('create', UvTransaction::class), "$role : table des droits attendue");
        $component = Livewire::actingAs($user)->test(Show::class, ['transaction' => $this->sale]);

        if (! $user->can('update', $this->sale)) {
            $component->call('cancel')->assertForbidden();
        }
        if (! $canDelete) {
            Livewire::actingAs($user)->test(Show::class, ['transaction' => $this->sale])->call('delete')->assertForbidden();
            $this->assertNotNull(TenantContext::runAs($this->a->id, fn () => UvTransaction::query()->find($this->sale->id)), "$role : la vente existe toujours");
        }
        if (! $canCreate) {
            Livewire::actingAs($user)->test(Show::class, ['transaction' => $this->sale])->call('duplicate')->assertForbidden();
            $this->assertSame(2, TenantContext::runAs($this->a->id, fn () => UvTransaction::query()->count()), "$role : aucune vente dupliquée");
        }
    }

    // ------------------------------------------------------------------------------------- isolation

    public function test_another_companys_sale_is_never_reachable(): void
    {
        $this->seedSales();
        $master = $this->userIn($this->a, 'master');

        $this->actingAs($master)->get(route('m.ventes.show', $this->saleB))->assertNotFound();
        $this->actingAs($master)->get(route('m.crud.edit', ['ventes', $this->saleB->id]))->assertNotFound();
        $this->actingAs($master)->get(route('m.ventes', ['q' => 'BRAVO']))->assertOk()->assertDontSee('V-BRAVO1')->assertDontSee('Agent Bravo');
    }
}
