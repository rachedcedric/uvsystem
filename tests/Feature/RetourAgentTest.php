<?php

namespace Tests\Feature;

use App\Filament\Pages\DettesFournisseursPage;
use App\Filament\Resources\ApprovisionnementResource\Pages\CreateApprovisionnement;
use App\Livewire\Mobile\Approvisionnements\Create as MobileCreate;
use App\Livewire\Mobile\Dettes\PayFournisseur;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovisionnementService;
use App\Services\FournisseurDebtService;
use App\Services\MasterSoldeService;
use App\Services\RetourAgentService;
use App\Services\UvDistributionService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 9 / 13 — Retour Agent (rebalancing) : choisir « Retour Agent » (type Agent) puis l'agent réel. Le retour est un
 * approvisionnement à crédit sur le compte fournisseur propre à l'agent, traité comme un Agent Premium : stock UV du
 * Master augmenté, montant dû à l'agent dans « Dettes fournisseurs », dette d'achats de l'agent inchangée.
 */
class RetourAgentTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        $this->actingAs($this->master);

        $banque = Fournisseur::factory()->create(['type' => 'BANQUE', 'is_active' => true]);
        app(ApprovisionnementService::class)->create(['user_id' => $this->master->id, 'fournisseur_id' => $banque->id, 'amount' => 1_000_000, 'date_approvisionnement' => now()->subDay()]);
        $this->agent = Agent::factory()->create(['name' => 'Agent Yao', 'phone' => '0700000001', 'status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($this->agent, 300_000);
    }

    public function test_every_company_has_a_single_retour_agent_selector_of_type_agent(): void
    {
        $retour = Fournisseur::query()->where('type', Fournisseur::TYPE_AGENT)->get();

        $this->assertCount(1, $retour, 'créée par la migration pour l\'entreprise existante');
        $this->assertSame('Retour Agent', $retour->first()->name);
        $this->assertSame('Agent', $retour->first()->typeLabel());
        $this->assertTrue($retour->first()->isRetourAgent());
        $this->assertSame($retour->first()->id, Fournisseur::retourAgent()->id, 'jamais dupliquée');
    }

    public function test_a_return_is_a_credit_supply_owed_to_the_agent_like_an_agent_premium_supplier(): void
    {
        $stockAvant = app(MasterSoldeService::class)->getAvailableBalance();

        $appro = app(RetourAgentService::class)->enregistrer($this->agent, 120_000, now()->toDateTimeString());
        $compte = $appro->fournisseur;

        $this->assertTrue($compte->isAgentFournisseur());
        $this->assertTrue($compte->isCreditFournisseur(), 'traité comme un Agent Premium');
        $this->assertSame($this->agent->id, $compte->agent_id);
        $this->assertSame('Retour Agent — Agent Yao', $compte->name);
        $this->assertSame($stockAvant + 120_000, app(MasterSoldeService::class)->getAvailableBalance());
        $this->assertSame(120_000.0, app(FournisseurDebtService::class)->detteRestante($compte), 'le Master doit 120 000 à l\'agent');
        $this->assertSame(120_000.0, app(FournisseurDebtService::class)->globalSummary()['resteAPayer']);
        $this->assertSame(300_000.0, $this->agent->fresh()->debtAsOf(), 'la dette d\'achats de l\'agent ne change pas : pas de double comptabilisation');

        // un second retour du même agent alimente le même compte ; un autre agent a le sien
        app(RetourAgentService::class)->enregistrer($this->agent, 30_000);
        $autre = Agent::factory()->create(['name' => 'Agent Awa', 'status' => 'active']);
        app(RetourAgentService::class)->enregistrer($autre, 10_000);

        $this->assertSame(150_000.0, app(FournisseurDebtService::class)->detteRestante($compte->fresh()));
        $this->assertSame(2, Fournisseur::query()->whereNotNull('agent_id')->count());
    }

    public function test_returns_appear_in_supplier_debts_and_the_agent_can_be_paid_there(): void
    {
        $compte = app(RetourAgentService::class)->enregistrer($this->agent, 80_000)->fournisseur;
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(DettesFournisseursPage::class)
            ->assertSee('Retour Agent — Agent Yao')
            ->assertSee('80 000 FCFA');
        $this->assertSame([$compte->id], DettesFournisseursPage::fournisseursEndettes()->pluck('id')->all(), 'l\'entrée de sélection n\'est pas un créancier');

        $this->get('/m/dettes?v=fournisseurs')->assertOk()->assertSee('Retour Agent — Agent Yao');

        Livewire::test(PayFournisseur::class, ['fournisseur' => $compte])
            ->set('amount', '80000')->call('save')->assertHasNoErrors();
        $this->assertSame(0.0, app(FournisseurDebtService::class)->detteRestante($compte));
    }

    public function test_the_retour_agent_selector_cannot_receive_a_plain_approvisionnement(): void
    {
        $this->assertThrows(fn () => app(ApprovisionnementService::class)->create([
            'user_id' => $this->master->id, 'fournisseur_id' => Fournisseur::retourAgent()->id, 'amount' => 10_000,
        ]), ValidationException::class);
    }

    public function test_agent_entries_are_protected_from_edition_and_deletion(): void
    {
        $compte = app(RetourAgentService::class)->enregistrer($this->agent, 10_000)->fournisseur;

        foreach ([Fournisseur::retourAgent(), $compte] as $entry) {
            $this->assertFalse($this->master->can('update', $entry));
            $this->assertFalse($this->master->can('delete', $entry));
        }
        $this->assertArrayNotHasKey(Fournisseur::TYPE_AGENT, Fournisseur::SELECTABLE_TYPES);
    }

    public function test_mobile_form_shows_the_agent_field_only_for_retour_agent_and_records_the_return(): void
    {
        $retourId = Fournisseur::retourAgent()->id;

        Livewire::test(MobileCreate::class)
            ->assertSee('Retour Agent')
            ->assertDontSee('Sélectionner un agent')
            ->set('fournisseurId', $retourId)
            ->assertSee('Sélectionner un agent')
            ->assertSee('Agent Yao')
            ->set('amount', '100000')
            ->call('save')
            ->assertHasErrors('agentId')
            ->set('agentId', $this->agent->id)
            ->assertSee("Dû à l'agent (dettes fournisseurs)", false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('m.approvisionnements'));

        $compte = Fournisseur::query()->where('agent_id', $this->agent->id)->firstOrFail();
        $this->assertSame(100_000.0, app(FournisseurDebtService::class)->detteRestante($compte));
        $this->assertSame(300_000.0, $this->agent->fresh()->debtAsOf());

        // le compte de l'agent n'encombre pas la liste de choix (on passe toujours par « Retour Agent »)
        Livewire::test(MobileCreate::class)->assertDontSee('Retour Agent — Agent Yao');
    }

    public function test_web_form_shows_the_agent_select_for_retour_agent_and_records_the_return(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $retourId = Fournisseur::retourAgent()->id;

        Livewire::test(CreateApprovisionnement::class)
            ->assertFormFieldIsHidden('agent_id')
            ->fillForm(['fournisseur_id' => $retourId])
            ->assertFormFieldIsVisible('agent_id')
            ->fillForm(['amount' => '50000', 'date_approvisionnement' => now()->subMinute()->format('Y-m-d H:i:s')])
            ->call('create')
            ->assertHasFormErrors(['agent_id' => 'required'])
            ->fillForm(['agent_id' => $this->agent->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $compte = Fournisseur::query()->where('agent_id', $this->agent->id)->firstOrFail();
        $this->assertSame(50_000.0, app(FournisseurDebtService::class)->detteRestante($compte));
        $this->assertSame(2, Approvisionnement::count());
        $this->assertSame(300_000.0, $this->agent->fresh()->debtAsOf());
    }

    public function test_an_agent_of_another_master_is_rejected(): void
    {
        TenantContext::setFallback(null);
        $autre = Tenant::create(['name' => 'Société B']);
        $agentB = TenantContext::runAs($autre->id, fn () => Agent::factory()->create(['status' => 'active']));

        $this->actingAs($this->master);
        $this->assertThrows(fn () => app(RetourAgentService::class)->enregistrer($agentB->id, 10_000), ValidationException::class);
        $this->assertThrows(fn () => app(RetourAgentService::class)->enregistrer($agentB, 10_000), ValidationException::class);

        Livewire::test(MobileCreate::class)
            ->set('fournisseurId', Fournisseur::retourAgent()->id)
            ->assertDontSee($agentB->name)
            ->set('agentId', $agentB->id)
            ->set('amount', '10000')
            ->call('save')
            ->assertHasErrors('agentId');

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Fournisseur::query()->whereNotNull('agent_id')->count()));
    }

    public function test_new_companies_are_provisioned_with_their_own_retour_agent_entry(): void
    {
        TenantContext::setFallback(null);
        $tenant = app(TenantProvisioner::class)->provision(['name' => 'Société C'], ['name' => 'Master C', 'email' => 'c@example.test', 'password' => 'Mot-de-passe-1!']);

        $count = TenantContext::runAs($tenant->id, fn () => Fournisseur::query()->where('type', Fournisseur::TYPE_AGENT)->count());
        $this->assertSame(1, $count);
    }
}
