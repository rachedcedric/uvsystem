<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Charges\Show;
use App\Livewire\Mobile\Crud;
use App\Livewire\Mobile\Remboursements as RembLivewire;
use App\Livewire\Mobile\Ventes as VentesLivewire;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\CaisseCategorie;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Services\MasterSoldeService;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use App\Support\Mobile\AdminUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'view_uv_transactions', 'edit_uv_transactions', 'delete_uv_transactions', 'create_uv_transactions',
        'view_remboursements', 'edit_remboursements', 'delete_remboursements', 'create_remboursements',
        'view_approvisionnements', 'edit_approvisionnements', 'delete_approvisionnements',
        'view_charges', 'edit_charges', 'delete_charges', 'view_agents',
        'view_caisse', 'create_caisse', 'edit_caisse', 'delete_caisse',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web'])->syncPermissions(self::PERMISSIONS);
        $this->user = User::factory()->create();
        $this->user->assignRole('master');
        $this->actingAs($this->user);
        PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'is_active' => true, 'sort_order' => 1]);
    }

    private function fund(float $amount = 1000000): Approvisionnement
    {
        return Approvisionnement::create(['user_id' => $this->user->id, 'fournisseur_id' => Fournisseur::factory()->create()->id, 'amount' => $amount, 'date_approvisionnement' => now()]);
    }

    public function test_a_sale_can_be_edited_within_balance_and_debt_ceiling(): void
    {
        $this->fund(1000000);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 300000]);
        $tx = (new UvDistributionService)->distribute($agent, 100000);
        $form = fn () => Livewire::test(Crud\Form::class, ['resource' => 'ventes', 'id' => (string) $tx->id]);

        $form()->assertSet('data.amount', '100000')->set('data.amount', '250000')->call('save')->assertHasNoErrors();
        $this->assertEquals(250000, $tx->fresh()->amount);
        $this->assertEquals(250000, $agent->fresh()->current_debt);
        $this->assertEquals(750000, app(MasterSoldeService::class)->getAvailableBalance());

        $form()->set('data.amount', '350000')->call('save')->assertHasErrors(['data.amount']);
        $this->assertEquals(250000, $tx->fresh()->amount);
    }

    public function test_a_sale_cannot_exceed_the_master_balance_when_edited(): void
    {
        $this->fund(200000);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 5000000]);
        $tx = (new UvDistributionService)->distribute($agent, 100000);

        Livewire::test(Crud\Form::class, ['resource' => 'ventes', 'id' => (string) $tx->id])
            ->set('data.amount', '300000')->call('save')->assertHasErrors(['data.amount']);
    }

    public function test_a_cancelled_sale_cannot_be_edited_and_a_sale_can_be_deleted(): void
    {
        $this->fund();
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 900000]);
        $service = new UvDistributionService;
        $cancelled = $service->distribute($agent, 50000);
        $service->cancel($cancelled);
        $kept = $service->distribute($agent, 70000);

        Livewire::test(Crud\Form::class, ['resource' => 'ventes', 'id' => (string) $cancelled->id])
            ->set('data.amount', '60000')->call('save')->assertHasErrors(['data.amount']);

        Livewire::test(VentesLivewire\Show::class, ['transaction' => $kept])->call('delete');
        $this->assertSoftDeleted($kept);
        $this->assertEquals(0, $agent->fresh()->current_debt);
    }

    public function test_a_repayment_can_be_edited_up_to_the_debt_and_deleted(): void
    {
        $this->fund();
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 900000]);
        (new UvDistributionService)->distribute($agent, 200000);
        $pay = app(RemboursementService::class)->process($agent->fresh(), 50000);

        $form = fn () => Livewire::test(Crud\Form::class, ['resource' => 'remboursements', 'id' => (string) $pay->id]);
        $form()->set('data.amount', '200000')->call('save')->assertHasNoErrors();
        $this->assertEquals(0, $agent->fresh()->current_debt);

        $form()->set('data.amount', '200001')->call('save')->assertHasErrors(['data.amount']);

        Livewire::test(RembLivewire\Show::class, ['remboursement' => $pay])->call('delete');
        $this->assertSoftDeleted($pay);
        $this->assertEquals(200000, $agent->fresh()->current_debt);
    }

    public function test_a_supply_cannot_be_reduced_or_deleted_below_what_was_already_sold(): void
    {
        $appro = $this->fund(300000);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 900000]);
        (new UvDistributionService)->distribute($agent, 250000);

        Livewire::test(Crud\Form::class, ['resource' => 'approvisionnements', 'id' => (string) $appro->id])
            ->set('data.amount', '100000')->call('save')->assertHasErrors(['data.amount']);

        Livewire::test(Crud\Form::class, ['resource' => 'approvisionnements', 'id' => (string) $appro->id])
            ->set('data.amount', '400000')->set('data.date_approvisionnement', now()->subMinute()->format('Y-m-d\TH:i'))
            ->call('save')->assertHasNoErrors();
        $this->assertEquals(150000, app(MasterSoldeService::class)->getAvailableBalance());

        Livewire::test(Crud\Show::class, ['resource' => 'approvisionnements', 'id' => (string) $appro->id])->call('delete')->assertForbidden();
        $this->assertNull($appro->fresh()->deleted_at);
    }

    public function test_a_charge_can_be_edited_and_deleted(): void
    {
        $cat = ChargeCategorie::create(['code' => 'loyer', 'name' => 'Loyer', 'is_active' => true]);
        $charge = Charge::create(['category_id' => $cat->id, 'libelle' => 'Loyer', 'montant' => 100000, 'date_charge' => today(), 'created_by' => $this->user->id]);

        Livewire::test(Crud\Form::class, ['resource' => 'charges', 'id' => (string) $charge->id])
            ->assertSet('data.montant', '100000')->set('data.montant', '120000')->set('data.libelle', 'Loyer octobre')
            ->set('data.heure_charge', now()->subMinute()->format('H:i'))
            ->call('save')->assertHasNoErrors();
        $this->assertEquals(120000, $charge->fresh()->montant);
        $this->assertSame($this->user->id, $charge->fresh()->updated_by);

        Livewire::test(Show::class, ['charge' => $charge])->call('delete');
        $this->assertSoftDeleted($charge);
    }

    public function test_a_cash_movement_can_be_edited_and_deleted(): void
    {
        $service = app(CaisseService::class);
        $service->enregistrer('entree', 100000, 'Apport');
        $sortie = $service->enregistrer('sortie', 30000, 'Carburant');
        $cat = CaisseCategorie::create(['name' => 'Divers', 'is_active' => true, 'sort_order' => 1]);

        Livewire::test(Crud\Form::class, ['resource' => 'mouvements-caisse', 'id' => (string) $sortie->id])
            ->set('data.amount', '20000')->set('data.caisse_categorie_id', (string) $cat->id)
            ->set('data.date_mouvement', now()->subMinute()->format('Y-m-d\TH:i'))
            ->call('save')->assertHasNoErrors();
        $this->assertEquals(20000, $sortie->fresh()->amount);

        Livewire::test(Crud\Form::class, ['resource' => 'mouvements-caisse', 'id' => (string) $sortie->id])
            ->set('data.amount', '999999')->call('save')->assertHasErrors(['data.amount']);

        Livewire::test(Crud\Show::class, ['resource' => 'mouvements-caisse', 'id' => (string) $sortie->id])->call('delete');
        $this->assertSoftDeleted($sortie);
        $this->assertEquals(100000, $service->soldeActuel());
    }

    public function test_detail_screens_show_edit_and_delete_actions(): void
    {
        $this->fund();
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 900000]);
        $tx = (new UvDistributionService)->distribute($agent, 40000);

        $this->get(route('m.ventes.show', $tx))->assertOk()->assertSee('Supprimer la vente')->assertSee(route('m.crud.edit', ['ventes', $tx->id]), false);
    }

    public function test_caisse_pdfs_are_downloadable(): void
    {
        app(CaisseService::class)->enregistrer('entree', 50000, 'Apport');
        $day = app(CaissePeriodResolver::class)->currentDate()->toDateString();

        foreach ([route('m.exports.caisse-jour', $day), route('m.exports.caisse-historique'), route('m.exports.caisse-historique', ['p' => 'all'])] as $url) {
            $res = $this->get($url);
            $res->assertOk();
            $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
        }
    }

    public function test_edit_requires_permission(): void
    {
        $this->fund();
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 900000]);
        $tx = (new UvDistributionService)->distribute($agent, 40000);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view_uv_transactions');
        $this->actingAs($viewer);

        Livewire::test(Crud\Form::class, ['resource' => 'ventes', 'id' => (string) $tx->id])->assertForbidden();
        Livewire::test(VentesLivewire\Show::class, ['transaction' => $tx])->call('delete')->assertForbidden();
    }

    public function test_a_sale_can_be_duplicated_within_the_same_rules(): void
    {
        $this->fund(300000);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 150000]);
        $tx = (new UvDistributionService)->distribute($agent, 100000);

        Livewire::test(VentesLivewire\Show::class, ['transaction' => $tx])->call('duplicate')->assertDispatched('m-toast');
        $this->assertSame(1, UvTransaction::count());          // plafond de dette dépassé : refusé
        $this->assertEquals(100000, $agent->fresh()->current_debt);

        $agent->update(['debt_limit' => 500000]);
        Livewire::test(VentesLivewire\Show::class, ['transaction' => $tx])->call('duplicate')->assertRedirect();
        $this->assertSame(2, UvTransaction::count());
    }

    public function test_notification_links_are_translated_to_mobile_screens(): void
    {
        $this->assertSame(route('m.remboursements.create', ['agent' => 12]), AdminUrl::toMobile('https://uvmaster.ci/master/remboursements/create?agent_id=12'));
        // Notifications déjà enregistrées avec l'ancien domaine : le lien reste converti.
        $this->assertSame(route('m.remboursements.create', ['agent' => 12]), AdminUrl::toMobile('https://uvmaster.ci/master/remboursements/create?agent_id=12'));
        $this->assertSame(route('m.dettes.agent', 7), AdminUrl::toMobile('/master/dettes/7'));
        $this->assertSame(route('m.home'), AdminUrl::toMobile('/master'));
        $this->assertSame(route('m.dettes.agent', 7), AdminUrl::toMobile('/admin/dettes/7'));
        $this->assertSame(route('m.home'), AdminUrl::toMobile('/admin'));
        $this->assertSame('https://exemple.com/x', AdminUrl::toMobile('https://exemple.com/x'));
    }

    public function test_supplier_sheet_shows_the_credit_debt(): void
    {
        $f = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true]);
        Approvisionnement::create(['user_id' => $this->user->id, 'fournisseur_id' => $f->id, 'amount' => 400000, 'date_approvisionnement' => now()]);
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_fournisseurs', 'guard_name' => 'web']));

        $this->get(route('m.crud.show', ['fournisseurs', $f->id]))->assertOk()->assertSee('Reste à payer')->assertSee('400');
    }
}
