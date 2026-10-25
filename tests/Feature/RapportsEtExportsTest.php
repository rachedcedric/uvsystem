<?php

namespace Tests\Feature;

use App\Exports\ReportCategoryExport;
use App\Filament\Pages\DettesFournisseursPage;
use App\Filament\Pages\HistoriqueDettesPage;
use App\Filament\Pages\ReportsPage;
use App\Filament\Resources\UvTransactionResource\Pages\ListUvTransactions;
use App\Livewire\Mobile\Analytics\Reports;
use App\Livewire\Mobile\Dettes\Historique;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovisionnementService;
use App\Services\CaisseService;
use App\Services\FournisseurDebtService;
use App\Services\Reports\DateRange;
use App\Services\Reports\DebtHistoryService;
use App\Services\Reports\ReportCatalog;
use App\Services\RetourAgentService;
use App\Services\UvDistributionService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phases 3 / 6 / 7 / 13 — Rapports et exports (Web et mobile), exports à dates libres, historique des dettes et
 * crédits : mêmes lignes et mêmes totaux à l'écran, en PDF et en Excel, uniquement pour l'entreprise connectée.
 */
class RapportsEtExportsTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Agent $agent;

    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-20 12:00:00');

        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        $this->actingAs($this->master);

        $this->fournisseur = Fournisseur::factory()->create(['name' => 'Grossiste Bamba', 'type' => 'AGENT_PREMIUM', 'is_active' => true]);
        $this->agent = Agent::factory()->create(['name' => 'Agent Koné', 'status' => 'active', 'debt_limit' => 0]);

        // 2026-09-15 : approvisionnement à crédit (mois précédent) ; 2026-10-19 23:59:59 : vente (veille, fin de journée)
        $this->appro(500_000, '2026-09-15 08:00:00');
        app(UvDistributionService::class)->distribute($this->agent, 200_000, null, '2026-10-19 23:59:59');
        app(UvDistributionService::class)->distribute($this->agent, 50_000, null, '2026-10-20 00:00:00');
        Remboursement::create(['agent_id' => $this->agent->id, 'amount' => 80_000, 'payment_method' => 'cash', 'payment_date' => '2026-10-20 09:00:00']);
        app(FournisseurDebtService::class)->process($this->fournisseur, 600_000, 'cash', '2026-10-05 10:00:00');
        app(RetourAgentService::class)->enregistrer($this->agent, 20_000, '2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appro(int $amount, string $at): void
    {
        app(ApprovisionnementService::class)->create(['user_id' => $this->master->id, 'fournisseur_id' => $this->fournisseur->id, 'amount' => $amount, 'date_approvisionnement' => $at]);
    }

    private function range(string $du, string $au): DateRange
    {
        return DateRange::fromInput(['periode' => 'custom', 'du' => $du, 'au' => $au]);
    }

    // ------------------------------------------------------------------ périodes

    public function test_presets_and_custom_ranges_include_the_whole_end_day_and_reject_inconsistent_dates(): void
    {
        $this->assertSame(['2026-10-20', '2026-10-20'], [DateRange::fromInput(['periode' => 'today'])->from->toDateString(), DateRange::fromInput(['periode' => 'today'])->to->toDateString()]);
        $this->assertSame('2026-10-19', DateRange::fromInput(['periode' => 'yesterday'])->from->toDateString());
        $this->assertSame('2026-10-14', DateRange::fromInput(['periode' => '7d'])->from->toDateString());
        $this->assertSame('2026-10-01', DateRange::fromInput(['periode' => 'month'])->from->toDateString());
        $lastMonth = DateRange::fromInput(['periode' => 'last_month']);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$lastMonth->from->toDateString(), $lastMonth->to->toDateString()]);

        $ventes = app(ReportCatalog::class)->get('ventes');
        // une seule journée : la vente de 23:59:59 est incluse, celle de 00:00:00 le lendemain ne l'est pas
        $this->assertSame(1, $ventes->summary($this->range('2026-10-19', '2026-10-19'))['count']);
        $this->assertSame(200_000.0, $ventes->summary($this->range('2026-10-19', '2026-10-19'))['total']);

        $this->assertThrows(fn () => $this->range('2026-10-20', '2026-10-01'), ValidationException::class);
        $this->assertThrows(fn () => $this->range('2026-13-01', '2026-10-01'), ValidationException::class);
        $this->assertThrows(fn () => $this->range('2026-11-01', '2026-11-02'), ValidationException::class);
    }

    public function test_multi_month_ranges_cover_every_operation(): void
    {
        $range = $this->range('2026-09-01', '2026-10-20');
        $catalog = app(ReportCatalog::class);

        $this->assertSame(500_000.0, $catalog->get('approvisionnements')->summary($range)['total'], 'les retours agents ont leur propre catégorie');
        $this->assertSame(250_000.0, $catalog->get('ventes')->summary($range)['total']);
        $this->assertSame(20_000.0, $catalog->get('retours_agents')->summary($range)['total']);
        $this->assertSame(0.0, $catalog->get('approvisionnements')->summary($this->range('2026-10-01', '2026-10-20'))['total']);
    }

    // ------------------------------------------------------------------ exports

    public function test_every_category_exports_a_valid_pdf_and_excel_for_a_custom_range(): void
    {
        $query = ['periode' => 'custom', 'du' => '2026-09-01', 'au' => '2026-10-20'];

        foreach (array_keys(app(ReportCatalog::class)->all()) as $key) {
            $pdf = $this->get(route('m.exports.categorie', [$key, 'pdf'] + $query));
            $pdf->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'), $key);
            $this->assertStringStartsWith('%PDF', $pdf->streamedContent(), $key);

            $xlsx = $this->get(route('exports.categorie', [$key, 'xlsx'] + $query));
            $xlsx->assertOk();
            $this->assertStringContainsString(str_replace('_', '-', $key).'_2026-09-01_2026-10-20.xlsx', (string) $xlsx->headers->get('Content-Disposition'), $key);
        }
    }

    public function test_an_inconsistent_range_is_refused_with_a_clear_message_and_an_empty_range_still_exports(): void
    {
        $this->get(route('m.exports.categorie', ['ventes', 'pdf', 'periode' => 'custom', 'du' => '2026-10-20', 'au' => '2026-10-01']))
            ->assertStatus(422)->assertJsonFragment(['message' => 'La date de fin doit être égale ou postérieure à la date de début.']);

        $vide = $this->get(route('m.exports.categorie', ['charges', 'pdf', 'periode' => 'custom', 'du' => '2026-01-01', 'au' => '2026-01-02']));
        $vide->assertOk();
        $this->assertStringStartsWith('%PDF', $vide->streamedContent());

        $this->get(route('m.exports.categorie', ['inconnue', 'pdf']))->assertNotFound();
    }

    public function test_exported_rows_and_totals_match_the_screen(): void
    {
        $range = $this->range('2026-10-01', '2026-10-20');
        $ventes = app(ReportCatalog::class)->get('ventes');
        $rows = (new ReportCategoryExport($ventes, $range))->array();

        $this->assertCount($ventes->summary($range)['count'] + 1, $rows, 'lignes + ligne de total');
        $this->assertSame($ventes->summary($range)['total'], end($rows)[4]);
        $this->assertSame('19/10/2026 23:59', $rows[1][0]);
        $this->assertSame(200_000.0, $rows[1][4], 'montant numérique exploitable');

        // recherche active reprise dans l'export
        $filtered = (new ReportCategoryExport($ventes, $range, ['q' => 'introuvable']))->array();
        $this->assertCount(1, $filtered);
    }

    public function test_a_master_never_exports_another_masters_data(): void
    {
        TenantContext::setFallback(null);
        $autre = Tenant::create(['name' => 'Société B']);
        TenantContext::runAs($autre->id, function () {
            $masterB = User::factory()->create();
            $masterB->assignRole('master');
            $this->actingAs($masterB);
            $b = Fournisseur::factory()->create(['name' => 'Fournisseur Secret', 'type' => 'BANQUE', 'is_active' => true]);
            app(ApprovisionnementService::class)->create(['user_id' => $masterB->id, 'fournisseur_id' => $b->id, 'amount' => 999_000, 'date_approvisionnement' => '2026-10-10 10:00:00']);
        });

        $this->actingAs($this->master);
        $rows = (new ReportCategoryExport(app(ReportCatalog::class)->get('approvisionnements'), $this->range('2026-09-01', '2026-10-20')))->array();

        $this->assertStringNotContainsString('Fournisseur Secret', json_encode($rows));
        $this->assertSame(500_000.0, end($rows)[5]);
    }

    public function test_exports_follow_permissions_read_only_and_admin_routes_stay_protected(): void
    {
        $commercial = User::factory()->create();
        $commercial->assignRole('commercial');
        $this->actingAs($commercial);
        $this->get(route('m.exports.categorie', ['ventes', 'pdf']))->assertOk();
        $this->get(route('m.exports.categorie', ['mouvements', 'pdf']))->assertForbidden(); // pas view_caisse

        $lecture = User::factory()->create();
        $lecture->assignRole(User::ROLE_READ_ONLY);
        $this->actingAs($lecture);
        $this->get(route('m.exports.categorie', ['ventes', 'xlsx']))->assertForbidden();

        $this->actingAs($this->master);
        $this->get('/platform')->assertForbidden();
    }

    // ------------------------------------------------------------------ historique des dettes et crédits

    public function test_debt_history_reports_opening_payments_and_signed_balances_at_any_past_date(): void
    {
        $service = app(DebtHistoryService::class);

        // Octobre : agent = 0 + 250 000 vendus − 80 000 payés = 170 000 (le retour d'UV ne touche pas sa dette d'achats) ;
        // fournisseur = 500 000 − 600 000 = −100 000 ; compte « Retour Agent » de l'agent = 20 000 dus par le Master.
        $octobre = $service->rows($this->range('2026-10-01', '2026-10-20'));
        $agent = $octobre->firstWhere('type', 'agent');
        $fournisseur = $octobre->firstWhere('nom', 'Grossiste Bamba');
        $retour = $octobre->firstWhere('nom', 'Retour Agent — Agent Koné');

        $this->assertSame(250_000.0, $agent['dette_initiale']);
        $this->assertSame(80_000.0, $agent['paye']);
        $this->assertSame(170_000.0, $agent['solde']);
        $this->assertSame(20_000.0, $retour['solde']);
        $this->assertSame('Fournisseur (retour agent)', $retour['type_label']);
        $this->assertSame('Partiel', $agent['statut']);
        $this->assertSame(500_000.0, $fournisseur['dette_initiale'], 'ouverture au 30/09');
        $this->assertSame(600_000.0, $fournisseur['paye']);
        $this->assertSame(-100_000.0, $fournisseur['solde']);
        $this->assertSame('Découvert', $fournisseur['statut']);

        // Solde à une date passée (fin septembre) : le fournisseur était créancier, l'agent ne devait rien
        $septembre = $service->rows($this->range('2026-09-01', '2026-09-30'));
        $this->assertSame(500_000.0, $septembre->firstWhere('nom', 'Grossiste Bamba')['solde']);
        $this->assertNull($septembre->firstWhere('type', 'agent'));

        $summary = $service->summary($this->range('2026-10-01', '2026-10-20'));
        $this->assertSame(170_000.0, $summary['dettes_agents']);
        $this->assertSame(20_000.0, $summary['dettes_fournisseurs'], 'UV rendus à payer à l\'agent');
        $this->assertSame(-100_000.0, $summary['decouverts_fournisseurs']);
        $this->assertSame(250_000.0, $summary['solde_net'], '170 000 à encaisser + 100 000 d\'avance − 20 000 dus');

        // filtres
        $range = $this->range('2026-10-01', '2026-10-20');
        $this->assertCount(1, $service->rows($range, ['type' => 'agent']));
        $this->assertCount(1, $service->rows($range, ['statut' => 'credit']));
        $this->assertCount(1, $service->rows($range, ['q' => 'bamba']));
        $this->assertCount(2, $service->rows($range, ['type' => 'fournisseur']));
    }

    public function test_mobile_and_web_debt_history_screens_show_widgets_filters_and_exports(): void
    {
        Livewire::test(Historique::class)
            ->assertSee('Dettes agents')->assertSee('Découverts fournisseurs')->assertSee('Solde net global')
            ->assertSee('Agent Koné')->assertSee('Grossiste Bamba')
            ->assertSeeHtml('exports/rapports/historique_dettes/pdf')
            // Filtre « Fournisseurs » : plus la ligne de l'agent, mais son compte de retours (le Master lui doit 20 000).
            ->set('type', 'fournisseur')
            ->assertDontSeeHtml(route('m.dettes.agent', $this->agent))
            ->assertSee('Retour Agent — Agent Koné')
            ->set('periode', 'custom')->set('du', '2026-10-20')->set('au', '2026-10-01')
            ->assertSee('La date de fin doit être égale ou postérieure')
            ->assertDontSeeHtml('exports/rapports/historique_dettes/pdf');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get(HistoriqueDettesPage::getUrl())->assertOk();
        Livewire::test(HistoriqueDettesPage::class)
            ->assertSee('Total des découverts fournisseurs')
            ->assertSee('Grossiste Bamba')
            ->assertSee('-100 000 FCFA')
            ->assertSeeHtml('exports/rapports/historique_dettes/xlsx');
    }

    // ------------------------------------------------------------------ écrans Rapports et exports

    public function test_mobile_reports_dashboard_categories_and_category_view(): void
    {
        Livewire::test(Reports::class)
            ->assertSee('Total des ventes')->assertSee('Total des approvisionnements')->assertSee('Total des remboursements')
            ->assertSee('Total des commissions')->assertSee('Dettes agents')->assertSee('Crédits et découverts')
            ->assertSee('Retours agents')->assertSee('Mouvements financiers')
            ->set('categorie', 'ventes')
            ->assertSee('Agent Koné')
            ->assertSeeHtml('exports/rapports/ventes/pdf')
            ->assertSeeHtml('exports/rapports/ventes/xlsx')
            ->set('q', 'introuvable')->assertSee('Aucun résultat pour cette recherche')
            ->call('resetFilters')
            ->set('periode', 'custom')->set('du', '2026-10-19')->set('au', '2026-10-19')
            ->assertSeeHtml('du=2026-10-19');

        $this->get('/m/rapports')->assertOk();
    }

    public function test_web_reports_page_shares_the_same_catalog_and_exports(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get(ReportsPage::getUrl())->assertOk();

        Livewire::test(ReportsPage::class)
            ->assertSee('Catégories de rapports')
            ->assertSee('Total des ventes')
            ->call('selectCategorie', 'retours_agents')
            ->assertSee('Stock UV +20 000 · dû à l\'agent +20 000')
            ->assertSeeHtml('exports/rapports/retours_agents/pdf')
            ->assertSee('Export Transactions UV', false); // exports détaillés existants conservés
    }

    public function test_web_lists_offer_a_custom_period_export_and_refuse_inconsistent_dates(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListUvTransactions::class)
            ->assertActionVisible('exportPeriode')
            ->callAction('exportPeriode', data: ['periode' => 'custom', 'du' => '2026-10-01', 'au' => '2026-10-19', 'format' => 'xlsx'])
            ->assertRedirect(route('exports.categorie', ['ventes', 'xlsx', 'periode' => 'custom', 'du' => '2026-10-01', 'au' => '2026-10-19']));

        Livewire::test(ListUvTransactions::class)
            ->callAction('exportPeriode', data: ['periode' => 'custom', 'du' => '2026-10-19', 'au' => '2026-10-01', 'format' => 'pdf'])
            ->assertHasActionErrors(['au']);

        Livewire::test(DettesFournisseursPage::class)->assertActionVisible('exportPeriode')->assertActionVisible('historique');
    }

    public function test_mobile_export_sheets_offer_a_custom_period(): void
    {
        $this->get('/m/ventes')->assertOk()->assertSee('Période personnalisée')->assertSee('exports/rapports/ventes/xlsx', false);
        $this->get('/m/paiements-fournisseurs')->assertOk()->assertSee('exports/rapports/remboursements_fournisseurs/pdf', false);
    }

    public function test_cancelled_movements_are_excluded_from_financial_movement_totals(): void
    {
        $cat = CaisseCategorie::firstOrCreate(['name' => 'Non catégorisé'], ['is_system' => true, 'is_active' => true, 'sort_order' => 999]);
        CaisseMouvement::create(['type' => 'entree', 'amount' => 40_000, 'libelle' => 'Dépôt', 'caisse_categorie_id' => $cat->id, 'date_mouvement' => '2026-10-18 09:00:00']);
        CaisseMouvement::create(['type' => 'sortie', 'amount' => 10_000, 'libelle' => 'Retrait', 'caisse_categorie_id' => $cat->id, 'date_mouvement' => '2026-10-19 09:00:00']);
        app(CaisseService::class)->annulerJournee(Carbon::parse('2026-10-19'), 'Erreur', '19/10/2026');

        $summary = app(ReportCatalog::class)->get('mouvements')->summary($this->range('2026-10-01', '2026-10-20'));
        $this->assertSame(1, $summary['count']);
        $this->assertSame(40_000.0, $summary['total']);
    }
}
