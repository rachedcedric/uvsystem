<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Agents;
use App\Livewire\Mobile\Approvisionnements;
use App\Livewire\Mobile\Caisse;
use App\Livewire\Mobile\Charges;
use App\Livewire\Mobile\More;
use App\Livewire\Mobile\Notifications;
use App\Livewire\Mobile\Remboursements;
use App\Livewire\Mobile\Ventes;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\CaisseMouvement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileModulesTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'view_uv_transactions', 'create_uv_transactions', 'edit_uv_transactions', 'delete_uv_transactions',
        'view_remboursements', 'create_remboursements', 'edit_remboursements',
        'view_agents', 'view_caisse', 'create_caisse', 'edit_caisse', 'delete_caisse',
        'view_approvisionnements', 'create_approvisionnements', 'view_charges', 'create_charges',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $role->syncPermissions(self::PERMISSIONS);

        $this->user = User::factory()->create();
        $this->user->assignRole('master');
        $this->actingAs($this->user);

        PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'icon' => '💵', 'is_active' => true, 'sort_order' => 1]);
    }

    private function fund(float $amount): void
    {
        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => Fournisseur::factory()->create()->id,
            'amount' => $amount,
            'date_approvisionnement' => now(),
        ]);
    }

    private function agent(array $attrs = []): Agent
    {
        return Agent::factory()->create($attrs + ['status' => 'active', 'debt_limit' => 500000, 'name' => 'Awa Koné']);
    }

    // ---------------------------------------------------------------- Ventes

    public function test_sale_is_created_through_the_distribution_service(): void
    {
        $this->fund(1000000);
        $agent = $this->agent();
        $operationAt = now()->subMinute()->startOfMinute();

        Livewire::test(Ventes\Create::class)
            ->set('agentId', $agent->id)
            ->set('amount', '150000')
            ->set('date', $operationAt->toDateString())
            ->set('time', $operationAt->format('H:i'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $tx = UvTransaction::firstOrFail();
        $this->assertSame('distribution', $tx->transaction_type);
        $this->assertEquals(150000, $tx->amount);
        $this->assertTrue($tx->transaction_date->equalTo($operationAt));
        $this->assertEquals(850000, app(MasterSoldeService::class)->getAvailableBalance());
        $this->assertEquals(150000, $agent->fresh()->current_debt);
    }

    public function test_sale_is_blocked_when_master_balance_is_insufficient(): void
    {
        $this->fund(100000);
        $agent = $this->agent();

        Livewire::test(Ventes\Create::class)
            ->set('agentId', $agent->id)
            ->set('amount', '250000')
            ->call('save')
            ->assertHasErrors('amount');

        $this->assertSame(0, UvTransaction::count());
    }

    public function test_sale_is_blocked_above_the_agent_debt_ceiling(): void
    {
        $this->fund(5000000);
        $agent = $this->agent(['debt_limit' => 100000]);

        Livewire::test(Ventes\Create::class)
            ->set('agentId', $agent->id)
            ->set('amount', '120000')
            ->call('save')
            ->assertHasErrors('amount');

        $this->assertSame(0, UvTransaction::count());
    }

    public function test_sale_requires_the_create_permission(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['view_uv_transactions']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Livewire::test(Ventes\Create::class)->assertForbidden();
    }

    public function test_sales_list_groups_by_day_and_searches_by_agent(): void
    {
        $this->fund(2000000);
        $awa = $this->agent();
        $moussa = $this->agent(['name' => 'Moussa Traoré']);
        $service = new UvDistributionService;
        $service->distribute($awa, 100000);
        $service->distribute($moussa, 50000);

        Livewire::test(Ventes\Index::class)
            ->assertSee('Awa Koné')->assertSee('Moussa Traoré')->assertSee("Aujourd'hui")
            ->set('search', 'Moussa')
            ->assertSee('Moussa Traoré')->assertDontSee('Awa Koné');
    }

    public function test_sale_can_be_cancelled_and_credits_the_balance_back(): void
    {
        $this->fund(1000000);
        $agent = $this->agent();
        $tx = (new UvDistributionService)->distribute($agent, 200000);

        Livewire::test(Ventes\Show::class, ['transaction' => $tx])->call('cancel');

        $this->assertSame('cancelled', $tx->fresh()->status);
        $this->assertEquals(1000000, app(MasterSoldeService::class)->getAvailableBalance());
    }

    // ------------------------------------------------------- Remboursements

    public function test_repayment_is_recorded_and_reduces_the_debt(): void
    {
        $this->fund(1000000);
        $agent = $this->agent();
        (new UvDistributionService)->distribute($agent, 300000);
        $operationAt = now()->subMinute()->startOfMinute();

        Livewire::test(Remboursements\Create::class)
            ->set('agentId', $agent->id)
            ->set('amount', '100000')
            ->set('date', $operationAt->toDateString())
            ->set('time', $operationAt->format('H:i'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $remboursement = Remboursement::firstOrFail();
        $this->assertEquals(100000, $remboursement->amount);
        $this->assertTrue($remboursement->payment_date->equalTo($operationAt));
        $this->assertEquals(200000, $agent->fresh()->current_debt);
    }

    public function test_repayment_cannot_exceed_the_remaining_debt_and_pay_all_settles_it(): void
    {
        $this->fund(1000000);
        $agent = $this->agent();
        (new UvDistributionService)->distribute($agent, 120000);

        Livewire::test(Remboursements\Create::class)
            ->set('agentId', $agent->id)
            ->set('amount', '120001')
            ->call('save')
            ->assertHasErrors('amount');

        Livewire::test(Remboursements\Create::class)
            ->set('agentId', $agent->id)
            ->call('payAll')
            ->assertSet('amount', '120000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(0, $agent->fresh()->current_debt);
    }

    public function test_repayment_form_only_offers_agents_with_a_debt(): void
    {
        $this->fund(1000000);
        $debtor = $this->agent();
        $clean = $this->agent(['name' => 'Fatou Bamba']);
        (new UvDistributionService)->distribute($debtor, 80000);

        Livewire::test(Remboursements\Create::class)
            ->assertSee('Awa Koné')->assertDontSee('Fatou Bamba');
    }

    // ---------------------------------------------------------------- Agents

    public function test_agent_list_filters_by_debt_and_agent_page_shows_history(): void
    {
        $this->fund(1000000);
        $debtor = $this->agent();
        $this->agent(['name' => 'Fatou Bamba']);
        (new UvDistributionService)->distribute($debtor, 90000);

        Livewire::test(Agents\Index::class)->assertSee('Awa Koné')->assertSee('Fatou Bamba')
            ->set('filter', 'debt')->assertSee('Awa Koné')->assertDontSee('Fatou Bamba');

        Livewire::test(Agents\Show::class, ['agent' => $debtor])
            ->assertSee('Historique')->assertSee('Vente')->assertSee('Encaisser');
    }

    // ---------------------------------------------------------------- Caisse

    public function test_cash_movements_are_recorded_and_exits_cannot_exceed_the_balance(): void
    {
        $operationAt = now()->subMinute()->startOfMinute();

        Livewire::test(Caisse\Create::class)
            ->set('lines.0.type', 'entree')->set('lines.0.amount', '50000')->set('lines.0.libelle', 'Apport')
            ->set('date', $operationAt->toDateString())->set('time', $operationAt->format('H:i'))
            ->call('save')->assertHasNoErrors();

        Livewire::test(Caisse\Create::class)
            ->set('lines.0.type', 'sortie')->set('lines.0.amount', '80000')->set('lines.0.libelle', 'Carburant')
            ->call('save')->assertHasErrors('lines');

        Livewire::test(Caisse\Create::class)
            ->set('lines.0.type', 'sortie')->set('lines.0.amount', '20000')->set('lines.0.libelle', 'Carburant')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(2, CaisseMouvement::count());
        $this->assertTrue(CaisseMouvement::oldest('id')->firstOrFail()->date_mouvement->equalTo($operationAt));
        $this->assertEquals(30000, app(CaisseService::class)->soldeActuel());

        Livewire::test(Caisse\Index::class)->assertSee('30');
        $today = app(CaissePeriodResolver::class)->currentDate()->toDateString();
        Livewire::test(Caisse\Jour::class, ['date' => $today])->assertSee('Apport')->assertSee('Carburant');
    }

    public function test_caisse_accepts_several_lines_for_the_same_date(): void
    {
        $day = now()->subDay()->startOfDay();

        Livewire::test(Caisse\Create::class)
            ->set('date', $day->toDateString())->set('time', '10:30')
            ->set('lines.0.type', 'entree')->set('lines.0.amount', '100000')->set('lines.0.libelle', 'Apport')
            ->call('addLine')
            ->set('lines.1.amount', '25000')->set('lines.1.libelle', 'Versement Awa')->set('lines.1.notes', 'reçu en espèces')
            ->call('addLine')
            ->set('lines.2.type', 'sortie')->set('lines.2.amount', '15000')->set('lines.2.libelle', 'Carburant')
            ->call('save')->assertHasNoErrors()
            ->assertRedirect(route('m.caisse.jour', ['date' => $day->toDateString()]));

        $this->assertSame(3, CaisseMouvement::count());
        $this->assertSame(
            [$day->toDateString()],
            CaisseMouvement::all()->map(fn ($m) => $m->date_mouvement->toDateString())->unique()->values()->all()
        );
        $this->assertSame('reçu en espèces', CaisseMouvement::where('libelle', 'Versement Awa')->value('notes'));
        $this->assertEquals(110000, app(CaisseService::class)->dayTotals($day)['solde']);
    }

    public function test_caisse_lines_are_all_or_nothing_and_errors_point_to_the_line(): void
    {
        Livewire::test(Caisse\Create::class)
            ->set('lines.0.amount', '40000')->set('lines.0.libelle', 'Apport')
            ->call('addLine')
            ->set('lines.1.amount', '5000')
            ->call('save')->assertHasErrors(['lines.1.libelle']);

        Livewire::test(Caisse\Create::class)
            ->set('lines.0.amount', '40000')->set('lines.0.libelle', 'Apport')
            ->call('addLine')
            ->set('lines.1.type', 'sortie')->set('lines.1.amount', '90000')->set('lines.1.libelle', 'Trop')
            ->call('save')->assertHasErrors('lines');

        $this->assertSame(0, CaisseMouvement::count());
    }

    public function test_caisse_lines_can_be_removed_but_one_always_remains(): void
    {
        Livewire::test(Caisse\Create::class)
            ->call('addLine')->call('addLine')->assertCount('lines', 3)
            ->call('removeLine', 1)->assertCount('lines', 2)
            ->call('removeLine', 0)->call('removeLine', 0)->assertCount('lines', 1);
    }

    public function test_caisse_create_prefills_the_date_from_a_day_page_and_ignores_the_future(): void
    {
        $past = now()->subDays(3)->toDateString();

        Livewire::withQueryParams(['date' => $past])->test(Caisse\Create::class)->assertSet('date', $past);
        Livewire::withQueryParams(['date' => now()->addDays(2)->toDateString()])->test(Caisse\Create::class)
            ->assertSet('date', now()->toDateString());

        $this->get(route('m.caisse.jour', $past))->assertOk()->assertSee('Ajouter à cette journée', false);
    }

    // -------------------------------------------------------- Agents inactifs

    public function test_inactive_agents_are_those_without_a_purchase_for_30_days(): void
    {
        $buy = function (Agent $agent, $date, string $status = 'completed', string $type = 'distribution') {
            UvTransaction::withoutEvents(fn () => UvTransaction::create([
                'agent_id' => $agent->id, 'user_id' => $this->user->id, 'transaction_type' => $type, 'amount' => 10000,
                'transaction_date' => $date, 'status' => $status, 'reference' => 'V-'.Str::random(6),
            ]));
        };

        $recent = Agent::factory()->create(['name' => 'Achat recent', 'created_at' => now()->subYear()]);
        $buy($recent, now()->subDays(5));

        $stale = Agent::factory()->create(['name' => 'Achat ancien', 'created_at' => now()->subYear()]);
        $buy($stale, now()->subDays(45));
        $buy($stale, now()->subDays(40));

        $never = Agent::factory()->create(['name' => 'Jamais achete', 'created_at' => now()->subDays(90)]);

        $newcomer = Agent::factory()->create(['name' => 'Nouveau venu', 'created_at' => now()->subDays(3)]);

        $imported = Agent::factory()->create(['name' => 'Importe recemment', 'created_at' => now()->subDay()]);
        $buy($imported, now()->subDays(60));

        $onlyCancelled = Agent::factory()->create(['name' => 'Achat annule', 'created_at' => now()->subYear()]);
        $buy($onlyCancelled, now()->subDays(2), 'cancelled');

        $onlyReturn = Agent::factory()->create(['name' => 'Retour seulement', 'created_at' => now()->subYear()]);
        $buy($onlyReturn, now()->subDays(2), 'completed', 'retour');

        $suspendedButBuying = Agent::factory()->create(['name' => 'Suspendu qui achete', 'status' => 'suspended', 'created_at' => now()->subYear()]);
        $buy($suspendedButBuying, now()->subDays(1));

        $boundary = Agent::factory()->create(['name' => 'Pile 29 jours', 'created_at' => now()->subYear()]);
        $buy($boundary, now()->subDays(29));

        $names = Agent::inactiveForDays()->pluck('name')->all();

        sort($names);
        $this->assertSame(['Achat ancien', 'Achat annule', 'Importe recemment', 'Jamais achete', 'Retour seulement'], $names);

        $page = Livewire::withQueryParams(['f' => 'inactive'])->test(Agents\Index::class)
            ->assertSee('Achat ancien')->assertSee('Jamais achete')->assertSee('Aucun achat')->assertSee('Dernier achat il y a 40 j')
            ->assertSee('30 jours')
            ->assertDontSee('Achat recent')->assertDontSee('Nouveau venu')->assertDontSee('Suspendu qui achete')->assertDontSee('Pile 29 jours');

        // Les plus anciens (ou jamais acheté) d'abord.
        $html = $page->html();
        $this->assertLessThan(strpos($html, 'Achat ancien'), strpos($html, 'Jamais achete'));

        // Les autres onglets ne changent pas.
        Livewire::test(Agents\Index::class)->set('filter', 'all')->assertSee('Achat recent')->assertSee('Nouveau venu');
    }

    // --------------------------------------------------------- Notifications

    public function test_notification_is_marked_read_when_opened(): void
    {
        $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'Filament\Notifications\DatabaseNotification',
            'data' => ['title' => 'Rappel de dette', 'body' => 'Awa Koné doit 90 000 FCFA', 'color' => 'warning'],
        ]);

        Livewire::test(Notifications::class)->assertSee('Rappel de dette')->assertSee('1 non lue');

        $id = $this->user->notifications()->first()->id;
        Livewire::test(Notifications::class)->call('open', $id);
        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    // ------------------------------------------------- Approvisionnements

    public function test_supply_increases_the_uv_balance(): void
    {
        $fournisseur = Fournisseur::factory()->create(['is_active' => true]);
        $operationAt = now()->subMinute()->startOfMinute();

        Livewire::test(Approvisionnements\Create::class)
            ->set('fournisseurId', $fournisseur->id)
            ->set('amount', '2500000')
            ->set('date', $operationAt->toDateString())
            ->set('time', $operationAt->format('H:i'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertEquals(2500000, app(MasterSoldeService::class)->getAvailableBalance());
        $approvisionnement = Approvisionnement::firstOrFail();
        $this->assertSame($this->user->id, $approvisionnement->user_id);
        $this->assertTrue($approvisionnement->date_approvisionnement->equalTo($operationAt));

        Livewire::test(Approvisionnements\Index::class)->assertSee($fournisseur->name);
    }

    public function test_supply_rejects_future_dates_and_missing_supplier(): void
    {
        Livewire::test(Approvisionnements\Create::class)
            ->set('amount', '1000')
            ->call('save')
            ->assertHasErrors('fournisseurId');

        $fournisseur = Fournisseur::factory()->create(['is_active' => true]);

        Livewire::test(Approvisionnements\Create::class)
            ->set('fournisseurId', $fournisseur->id)->set('amount', '1000')->set('date', now()->addDays(3)->toDateString())
            ->call('save')
            ->assertHasErrors('date');
    }

    // --------------------------------------------------------------- Charges

    public function test_charge_is_recorded_with_a_private_receipt_photo(): void
    {
        Storage::fake('local');
        $category = ChargeCategorie::create(['code' => 'TRANSPORT', 'name' => 'Transport', 'is_active' => true]);
        $operationAt = now()->subMinute()->startOfMinute();

        Livewire::test(Charges\Create::class)
            ->set('libelle', 'Carburant')
            ->set('categoryId', $category->id)
            ->set('amount', '15000')
            ->set('date', $operationAt->toDateString())
            ->set('time', $operationAt->format('H:i'))
            ->set('justificatif', UploadedFile::fake()->image('recu.jpg'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $charge = Charge::firstOrFail();
        $this->assertEquals(15000, $charge->montant);
        $this->assertSame($operationAt->toDateString(), $charge->date_charge->toDateString());
        $this->assertSame($operationAt->format('H:i'), substr($charge->heure_charge, 0, 5));
        $this->assertNotNull($charge->justificatif_path);
        Storage::disk('local')->assertExists($charge->justificatif_path);

        Livewire::test(Charges\Create::class)
            ->set('libelle', 'X')->set('categoryId', $category->id)->set('amount', '1000')
            ->set('justificatif', UploadedFile::fake()->create('virus.exe', 10))
            ->call('save')
            ->assertHasErrors('justificatif');

        Livewire::test(Charges\Index::class)->assertSee('Carburant');
        Livewire::test(Charges\Show::class, ['charge' => $charge])->assertSee('Voir le justificatif');
    }

    public function test_operation_date_and_time_cannot_be_in_the_future(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        $this->fund(1000000);
        $agent = $this->agent();

        Livewire::test(Ventes\Create::class)
            ->set('agentId', $agent->id)
            ->set('amount', '10000')
            ->set('date', '2026-10-05')
            ->set('time', '12:01')
            ->call('save')
            ->assertHasErrors('time');

        $this->assertSame(0, UvTransaction::count());
    }

    public function test_more_page_offers_native_sections_without_duplicating_ported_screens(): void
    {
        Livewire::test(More::class)
            ->assertSee('Argent')->assertSee('Remboursements')->assertSee('Approvisionnements')->assertSee('Charges');
    }

    public function test_mobile_pages_render_through_http_for_an_admin(): void
    {
        $this->fund(1000000);
        $agent = $this->agent();
        $tx = (new UvDistributionService)->distribute($agent, 100000);

        foreach (['/m/ventes', '/m/ventes/nouvelle', "/m/ventes/{$tx->id}", '/m/remboursements', '/m/remboursements/nouveau',
            '/m/agents', "/m/agents/{$agent->id}", '/m/caisse', '/m/caisse/mouvement', '/m/notifications', '/m/plus',
            '/m/approvisionnements', '/m/approvisionnements/nouveau', '/m/charges', '/m/charges/nouvelle'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
