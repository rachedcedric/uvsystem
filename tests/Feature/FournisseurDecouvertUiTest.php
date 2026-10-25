<?php

namespace Tests\Feature;

use App\Filament\Pages\DettesFournisseursPage;
use App\Filament\Resources\FournisseurResource\Pages\ViewFournisseur;
use App\Filament\Resources\RemboursementFournisseurResource\Pages\CreateRemboursementFournisseur;
use App\Filament\Resources\RemboursementFournisseurResource\Pages\ListRemboursementFournisseurs;
use App\Filament\Widgets\FournisseurDebtStatsWidget;
use App\Livewire\Mobile\Dettes\FournisseurDetail;
use App\Livewire\Mobile\Dettes\PayFournisseur;
use App\Livewire\Mobile\RemboursementsFournisseurs\Index as PaiementsFournisseurs;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\RemboursementFournisseur;
use App\Models\User;
use App\Services\FournisseurDebtService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phases 2 / 4 — affichage du solde signé (Web et mobile), trop-payé accepté, annulation depuis le menu « ⋯ ».
 */
class FournisseurDecouvertUiTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        $this->actingAs($this->master);
        PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'is_active' => true, 'sort_order' => 1]);

        $this->fournisseur = Fournisseur::factory()->create(['name' => 'Grossiste Kouassi', 'type' => 'AGENT_PREMIUM', 'is_active' => true]);
        Approvisionnement::create(['user_id' => $this->master->id, 'fournisseur_id' => $this->fournisseur->id, 'amount' => 100_000, 'date_approvisionnement' => now()->subHour()]);
    }

    private function overpay(): RemboursementFournisseur
    {
        return app(FournisseurDebtService::class)->process($this->fournisseur, 150_000);
    }

    public function test_the_web_debt_page_and_widget_show_the_signed_overdraft_in_green(): void
    {
        $this->overpay();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(FournisseurDebtStatsWidget::class)
            ->assertSee('Découverts fournisseurs')
            ->assertSee('-50 000 FCFA');

        Livewire::test(DettesFournisseursPage::class)
            ->assertSee('Grossiste Kouassi')
            ->assertSee('-50 000 FCFA')
            ->assertSee('Découvert')
            ->assertSee('Avance disponible');
    }

    public function test_the_web_supplier_sheet_shows_totals_balance_and_traceable_history(): void
    {
        $this->overpay();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get('/master/fournisseurs/'.$this->fournisseur->id)->assertOk();

        Livewire::test(ViewFournisseur::class, ['record' => $this->fournisseur->getRouteKey()])
            ->assertSee('Historique des mouvements')
            ->assertSee('Approvisionnement à crédit')
            ->assertSee('50 000 FCFA en avance (découvert)')
            ->assertSee('-50 000 FCFA');
    }

    public function test_the_web_form_accepts_a_repayment_above_the_debt(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateRemboursementFournisseur::class)
            ->fillForm([
                'fournisseur_id' => $this->fournisseur->id,
                'amount' => '150000',
                'payment_method' => 'cash',
                'payment_date' => now()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(-50_000.0, app(FournisseurDebtService::class)->detteRestante($this->fournisseur));
    }

    public function test_the_web_cancel_action_requires_a_reason_and_disappears_once_cancelled(): void
    {
        $paiement = $this->overpay();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListRemboursementFournisseurs::class)
            ->assertTableActionVisible('cancel', $paiement)
            ->callTableAction('cancel', $paiement, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertFalse($paiement->fresh()->isCancelled());

        Livewire::test(ListRemboursementFournisseurs::class)
            ->callTableAction('cancel', $paiement, data: ['reason' => 'Montant erroné'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($paiement->fresh()->isCancelled());
        $this->assertSame(100_000.0, app(FournisseurDebtService::class)->detteRestante($this->fournisseur));

        Livewire::test(ListRemboursementFournisseurs::class)
            ->assertSee('Annulé')
            ->assertTableActionHidden('cancel', $paiement->fresh())
            ->assertTableActionHidden('edit', $paiement->fresh());
    }

    public function test_the_mobile_debt_list_and_supplier_sheet_show_the_signed_balance(): void
    {
        $this->overpay();

        $this->get('/m/dettes?v=fournisseurs')->assertOk()
            ->assertSee('Découverts')
            ->assertSee('Solde net fournisseurs')
            ->assertSee("-50\u{00A0}000", false);

        $this->get('/m/dettes/fournisseur/'.$this->fournisseur->id)->assertOk()
            ->assertSee('Découvert actuel')
            ->assertSee('Historique des mouvements')
            ->assertSee('en avance (découvert)');
    }

    public function test_the_mobile_payment_screen_previews_and_accepts_an_overdraft(): void
    {
        Livewire::test(PayFournisseur::class, ['fournisseur' => $this->fournisseur])
            ->set('amount', '150000')
            ->assertSee('sera enregistré comme découvert')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('m.dettes.fournisseur.show', $this->fournisseur));

        $this->assertSame(-50_000.0, app(FournisseurDebtService::class)->detteRestante($this->fournisseur));
    }

    public function test_mobile_cancellation_from_the_payment_history_and_the_supplier_sheet(): void
    {
        $paiement = $this->overpay();

        Livewire::test(PaiementsFournisseurs::class)
            ->assertSee('Annuler le remboursement')
            ->call('cancelPayment', $paiement->id)
            ->assertDispatched('m-toast');
        $this->assertFalse($paiement->fresh()->isCancelled(), 'motif obligatoire');

        Livewire::test(FournisseurDetail::class, ['fournisseur' => $this->fournisseur])
            ->set('cancelReason', 'Saisie en double')
            ->call('cancelPayment', $paiement->id)
            ->assertDispatched('m-toast');

        $this->assertTrue($paiement->fresh()->isCancelled());
        $this->assertSame(100_000.0, app(FournisseurDebtService::class)->detteRestante($this->fournisseur));

        Livewire::test(PaiementsFournisseurs::class)->assertSee('Annulé')->assertSee('1 annulé');
    }

    public function test_mobile_cancellation_is_refused_to_a_role_without_the_right(): void
    {
        $paiement = $this->overpay();
        $comptable = User::factory()->create();
        $comptable->assignRole('comptable');
        $this->actingAs($comptable);

        Livewire::test(PaiementsFournisseurs::class)
            ->assertDontSee('Annuler le remboursement')
            ->set('cancelReason', 'Tentative')
            ->call('cancelPayment', $paiement->id)
            ->assertForbidden();

        $this->assertFalse($paiement->fresh()->isCancelled());
    }
}
