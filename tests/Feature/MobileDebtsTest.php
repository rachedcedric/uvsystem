<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Dettes;
use App\Livewire\Mobile\RemboursementsFournisseurs;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\RemboursementFournisseur;
use App\Models\User;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileDebtsTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'view_remboursements', 'create_remboursements', 'view_agents',
        'view_remboursements_fournisseurs', 'create_remboursements_fournisseurs',
        'view_approvisionnements', 'view_uv_transactions',
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

        PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'icon' => '💵', 'is_active' => true, 'sort_order' => 1]);
    }

    private function indebtedAgent(float $amount = 200000): Agent
    {
        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => Fournisseur::factory()->create(['type' => 'BANQUE'])->id,
            'amount' => 5000000,
            'date_approvisionnement' => now(),
        ]);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 1000000, 'name' => 'Awa Koné']);
        (new UvDistributionService)->distribute($agent, $amount);

        return $agent->fresh();
    }

    private function premiumSupplier(float $credit = 400000): Fournisseur
    {
        $f = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true, 'name' => 'Premium Dealer']);

        // SQLite matérialise l'enum en CHECK sensible à la casse ; MySQL/MariaDB l'acceptent tel quel.
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $sqlite && DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('fournisseurs')->where('id', $f->id)->update(['type' => 'agent_premium']);
        $sqlite && DB::statement('PRAGMA ignore_check_constraints = OFF');
        $f->refresh();

        Approvisionnement::create([
            'user_id' => $this->user->id, 'fournisseur_id' => $f->id, 'amount' => $credit, 'date_approvisionnement' => now(),
        ]);

        return $f;
    }

    public function test_agent_debts_list_shows_summary_and_agents(): void
    {
        $agent = $this->indebtedAgent(200000);

        Livewire::test(Dettes\Index::class)
            ->assertSee('Awa Koné')->assertSee('200')->assertSee('Agents endettés');

        Livewire::test(Dettes\Index::class)->set('search', 'zzz')->assertDontSee('Awa Koné');
        $this->assertTrue($agent->current_debt > 0);
    }

    public function test_supplier_debts_are_listed_and_can_be_paid_partially_then_settled(): void
    {
        $f = $this->premiumSupplier(400000);
        $operationAt = now()->subMinute()->startOfMinute();

        Livewire::test(Dettes\Index::class, ['scope' => 'fournisseurs'])
            ->set('scope', 'fournisseurs')
            ->assertSee('Premium Dealer')->assertSee('À payer');

        Livewire::test(Dettes\PayFournisseur::class, ['fournisseur' => $f])
            ->set('amount', '150000')
            ->set('date', $operationAt->toDateString())
            ->set('time', $operationAt->format('H:i'))
            ->call('save')->assertHasNoErrors()->assertRedirect();
        $paiement = RemboursementFournisseur::firstOrFail();
        $this->assertEquals(150000, $paiement->amount);
        $this->assertTrue($paiement->payment_date->equalTo($operationAt));

        Livewire::test(Dettes\PayFournisseur::class, ['fournisseur' => $f])
            ->set('amount', '999999')->call('save')->assertHasErrors('amount');

        Livewire::test(Dettes\PayFournisseur::class, ['fournisseur' => $f])
            ->call('payAll')->assertSet('amount', '250000')->call('save')->assertHasNoErrors();
        $this->assertEquals(400000, RemboursementFournisseur::sum('amount'));

        Livewire::test(RemboursementsFournisseurs\Index::class)->assertSee('Premium Dealer');
    }

    public function test_paying_a_bank_supplier_is_not_possible(): void
    {
        $bank = Fournisseur::factory()->create(['type' => 'BANQUE', 'is_active' => true]);

        Livewire::test(Dettes\PayFournisseur::class, ['fournisseur' => $bank])->assertNotFound();
    }

    public function test_agent_debt_detail_shows_history_and_export_links(): void
    {
        $agent = $this->indebtedAgent(150000);

        Livewire::test(Dettes\AgentDetail::class, ['agent' => $agent])
            ->assertSee('Reste à payer')->assertSee('Historique')->assertSee('Vente')->assertSee('Encaisser');
    }

    public function test_agent_debt_exports_download_pdf_and_excel(): void
    {
        $agent = $this->indebtedAgent(150000);

        $pdf = $this->get(route('m.exports.dette-agent', [$agent, 'pdf']));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('dette_awa-kone', $pdf->headers->get('Content-Disposition'));

        $this->get(route('m.exports.dette-agent', [$agent, 'xlsx']))->assertOk();
        $this->get(route('m.exports.dette-agent', [$agent, 'doc']))->assertNotFound();
    }

    public function test_debt_screens_require_permissions(): void
    {
        $other = User::factory()->create();
        $other->assignRole(Role::firstOrCreate(['name' => 'commercial', 'guard_name' => 'web']));
        $this->actingAs($other);

        $agent = Agent::factory()->create();
        Livewire::test(Dettes\Index::class)->assertForbidden();
        Livewire::test(Dettes\AgentDetail::class, ['agent' => $agent])->assertForbidden();
    }
}
