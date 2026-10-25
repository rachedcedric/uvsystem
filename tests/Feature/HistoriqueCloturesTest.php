<?php

namespace Tests\Feature;

use App\Exports\ReportCategoryExport;
use App\Filament\Pages\HistoriqueDettesPage;
use App\Livewire\Mobile\Dettes\Historique;
use App\Models\Agent;
use App\Models\ClotureJournaliere;
use App\Models\ClotureSolde;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovisionnementService;
use App\Services\ClotureSoldesService;
use App\Services\FournisseurDebtService;
use App\Services\Reports\ClotureHistoryService;
use App\Services\Reports\DateRange;
use App\Services\Reports\ReportCatalog;
use App\Services\UvDistributionService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Historique des dettes et crédits : photographie des soldes à la clôture de chaque journée (23h59, Africa/Abidjan).
 * Dette = le Master doit (fournisseur créancier, agent créditeur) ; Crédit = le compte doit au Master (agent
 * débiteur, découvert fournisseur). Scénario de l'exemple : 25/10 (A 1 000 000 dette, Koffi 350 000 crédit,
 * B 200 000 dette), 26/10 tout soldé, 27/10 A 400 000 dette.
 */
class HistoriqueCloturesTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Fournisseur $a;

    private Fournisseur $b;

    private Agent $koffi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2025-10-25 07:00:00');
        Tenant::query()->whereKey(1)->update(['created_at' => '2025-10-24 08:00:00']);

        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        $this->actingAs($this->master);

        $this->a = Fournisseur::factory()->create(['name' => 'Fournisseur A', 'type' => 'AGENT_PREMIUM', 'is_active' => true]);
        $this->b = Fournisseur::factory()->create(['name' => 'Fournisseur B', 'type' => 'AGENT_PREMIUM', 'is_active' => true]);
        $this->koffi = Agent::factory()->create(['name' => 'Agent Koffi', 'status' => 'active', 'debt_limit' => 0]);

        // 25/10 : dettes envers A et B, Koffi doit 350 000 au Master
        $this->appro($this->a, 1_000_000, '2025-10-25 08:00:00');
        $this->appro($this->b, 200_000, '2025-10-25 09:00:00');
        app(UvDistributionService::class)->distribute($this->koffi, 350_000, null, '2025-10-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appro(Fournisseur $f, int $amount, string $at): void
    {
        app(ApprovisionnementService::class)->create(['user_id' => $this->master->id, 'fournisseur_id' => $f->id, 'amount' => $amount, 'date_approvisionnement' => $at]);
    }

    private function payerAgent(Agent $agent, int $amount, string $at): void
    {
        Remboursement::create(['agent_id' => $agent->id, 'amount' => $amount, 'payment_method' => 'cash', 'payment_date' => $at]);
    }

    /** 26/10 : tout est soldé avant 23h59 ; 27/10 : nouvelle dette de 400 000 envers A. */
    private function journees26et27(): void
    {
        app(FournisseurDebtService::class)->process($this->a, 1_000_000, 'cash', '2025-10-26 09:00:00');
        app(FournisseurDebtService::class)->process($this->b, 200_000, 'cash', '2025-10-26 09:30:00');
        $this->payerAgent($this->koffi, 350_000, '2025-10-26 10:00:00');
        $this->appro($this->a, 400_000, '2025-10-27 08:00:00');
    }

    private function cloturer(string $now): array
    {
        Carbon::setTestNow($now);

        return app(ClotureSoldesService::class)->cloturerJourneesManquantes();
    }

    /** @return array<string, array{nature: string, montant: float}> nom => ligne figée */
    private function lignes(string $date): array
    {
        return ClotureSolde::query()->where('date', $date)->get()
            ->mapWithKeys(fn (ClotureSolde $l) => [$l->nom => ['nature' => $l->nature, 'montant' => (float) $l->montant]])->all();
    }

    private function range(string $du, string $au): DateRange
    {
        return DateRange::fromInput(['periode' => 'custom', 'du' => $du, 'au' => $au]);
    }

    // ------------------------------------------------------------------ photographie de fin de journée

    public function test_a_day_with_several_debts_and_a_credit_is_frozen_with_the_right_natures(): void
    {
        $this->cloturer('2025-10-26 00:01:00');

        $this->assertSame([
            'Agent Koffi' => ['nature' => 'credit', 'montant' => 350_000.0],
            'Fournisseur A' => ['nature' => 'dette', 'montant' => 1_000_000.0],
            'Fournisseur B' => ['nature' => 'dette', 'montant' => 200_000.0],
        ], collect($this->lignes('2025-10-25'))->sortKeys()->all());

        $cloture = ClotureJournaliere::query()->where('date', '2025-10-25')->firstOrFail();
        $this->assertSame([2, 1], [$cloture->nb_dettes, $cloture->nb_credits]);
        $this->assertSame(['1200000.00', '350000.00'], [(string) $cloture->total_dettes, (string) $cloture->total_credits]);
        $this->assertSame(ClotureJournaliere::SOURCE_AUTOMATIQUE, $cloture->source);
    }

    public function test_a_day_with_several_credits_agent_owing_and_supplier_overdraft(): void
    {
        // 25/10 en fin de journée : B est payé 300 000 pour 200 000 dus (découvert = crédit), A est soldé
        app(FournisseurDebtService::class)->process($this->b, 300_000, 'cash', '2025-10-25 18:00:00');
        app(FournisseurDebtService::class)->process($this->a, 1_000_000, 'cash', '2025-10-25 18:30:00');
        $this->cloturer('2025-10-26 00:01:00');

        $this->assertSame([
            'Agent Koffi' => ['nature' => 'credit', 'montant' => 350_000.0],
            'Fournisseur B' => ['nature' => 'credit', 'montant' => 100_000.0],
        ], collect($this->lignes('2025-10-25'))->sortKeys()->all());
    }

    public function test_an_agent_overpaid_is_a_debt_of_the_master(): void
    {
        $this->payerAgent($this->koffi, 400_000, '2025-10-25 19:00:00');
        $this->cloturer('2025-10-26 00:01:00');

        $this->assertSame(['nature' => 'dette', 'montant' => 50_000.0], $this->lignes('2025-10-25')['Agent Koffi']);
    }

    public function test_days_without_any_balance_are_closed_without_lines(): void
    {
        $this->journees26et27();
        $this->cloturer('2025-10-28 00:01:00');

        // 24/10 (création de l'entreprise, aucun mouvement) et 26/10 (tout soldé) : clôture sans ligne
        foreach (['2025-10-24', '2025-10-26'] as $date) {
            $cloture = ClotureJournaliere::query()->where('date', $date)->firstOrFail();
            $this->assertSame(0, $cloture->soldes()->count(), $date);
        }
        $this->assertSame(['Fournisseur A' => ['nature' => 'dette', 'montant' => 400_000.0]], $this->lignes('2025-10-27'));
        $this->assertFalse(ClotureJournaliere::query()->where('date', '2025-10-23')->exists(), 'jamais avant la première donnée');
        $this->assertFalse(ClotureJournaliere::query()->where('date', '2025-10-28')->exists(), 'la journée en cours n\'est pas clôturée');
    }

    public function test_a_debt_appears_every_day_until_it_is_settled_at_closing_time(): void
    {
        $this->cloturer('2025-10-28 00:01:00');

        foreach (['2025-10-25', '2025-10-26', '2025-10-27'] as $date) {
            $this->assertSame(['nature' => 'dette', 'montant' => 1_000_000.0], $this->lignes($date)['Fournisseur A'], $date);
        }
    }

    public function test_a_debt_and_a_credit_settled_the_next_day_stay_in_the_previous_day(): void
    {
        $this->cloturer('2025-10-26 00:01:00');
        $avant = $this->lignes('2025-10-25');

        Carbon::setTestNow('2025-10-26 12:00:00');
        $this->journees26et27();
        $this->cloturer('2025-10-27 00:01:00');

        $this->assertSame($avant, $this->lignes('2025-10-25'), 'un remboursement le lendemain ne modifie jamais la veille');
        $this->assertSame([], $this->lignes('2025-10-26'));
        $this->assertSame(1, ClotureJournaliere::query()->where('date', '2025-10-25')->count());
    }

    // ------------------------------------------------------------------ clôture automatique, limite de minuit

    public function test_closing_is_scheduled_after_midnight_in_abidjan_time_and_includes_the_last_second(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'soldes:cloturer'));
        $this->assertCount(2, $events, 'clôture quotidienne + rattrapage horaire');
        $daily = $events->first(fn ($e) => str_contains((string) $e->command, '--verifier'));
        $this->assertSame('1 0 * * *', $daily->expression);
        $this->assertSame('Africa/Abidjan', $daily->timezone);

        // opération à 23:59:59 : dans la journée ; à 00:00:00 le lendemain : dans la suivante
        app(UvDistributionService::class)->distribute($this->koffi, 50_000, null, '2025-10-25 23:59:59');
        app(UvDistributionService::class)->distribute($this->koffi, 7_000, null, '2025-10-26 00:00:00');

        Carbon::setTestNow('2025-10-26 00:01:00');
        $this->artisan('soldes:cloturer --verifier')->assertSuccessful();

        $this->assertSame(400_000.0, $this->lignes('2025-10-25')['Agent Koffi']['montant']);
        $this->assertFalse(ClotureJournaliere::query()->where('date', '2025-10-26')->exists());
    }

    public function test_missed_closings_are_recovered_from_recorded_movements(): void
    {
        $this->journees26et27();

        // serveur arrêté du 26 au 29 : la première exécution reconstitue toutes les journées manquées
        $faites = $this->cloturer('2025-10-30 10:17:00');

        $this->assertSame(['2025-10-24', '2025-10-25', '2025-10-26', '2025-10-27', '2025-10-28', '2025-10-29'], $faites);
        $this->assertSame(1_000_000.0, $this->lignes('2025-10-25')['Fournisseur A']['montant']);
        $this->assertSame([], $this->lignes('2025-10-26'));
        $this->assertSame(['Fournisseur A' => ['nature' => 'dette', 'montant' => 400_000.0]], $this->lignes('2025-10-29'));
        $this->assertSame(ClotureJournaliere::SOURCE_RATTRAPAGE, ClotureJournaliere::query()->where('date', '2025-10-25')->value('source'));
        $this->assertSame(ClotureJournaliere::SOURCE_AUTOMATIQUE, ClotureJournaliere::query()->where('date', '2025-10-29')->value('source'));
    }

    public function test_running_again_never_creates_duplicates(): void
    {
        $this->cloturer('2025-10-27 00:01:00');
        $counts = [ClotureJournaliere::count(), ClotureSolde::count()];

        $this->assertSame([], $this->cloturer('2025-10-27 00:02:00'));
        $this->artisan('soldes:cloturer')->assertSuccessful();
        $this->assertSame($counts, [ClotureJournaliere::count(), ClotureSolde::count()]);

        // garde-fou en base : une seule clôture par entreprise et par date
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('clotures_journalieres')->insert(['tenant_id' => 1, 'date' => '2025-10-25', 'closed_at' => now(), 'source' => 'automatique', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ conservation et corrections rétroactives

    public function test_history_is_kept_after_repayment_and_retroactive_changes_are_flagged_not_applied(): void
    {
        $this->cloturer('2025-10-26 00:01:00');
        $avant = $this->lignes('2025-10-25');

        // le 26, on saisit un paiement antidaté au 25 : le solde actuel est soldé, la clôture du 25 ne bouge pas
        Carbon::setTestNow('2025-10-26 15:00:00');
        $remb = app(FournisseurDebtService::class)->process($this->a, 1_000_000, 'cash', '2025-10-25 20:00:00');
        $this->assertSame(0.0, app(FournisseurDebtService::class)->detteRestante($this->a), 'solde actuel soldé');

        $this->assertSame(1, app(ClotureSoldesService::class)->verifier());
        $cloture = ClotureJournaliere::query()->where('date', '2025-10-25')->firstOrFail();
        $this->assertTrue($cloture->hasEcart());
        $this->assertSame([['compte' => 'fournisseur:'.$this->a->id, 'nom' => 'Fournisseur A', 'solde_cloture' => '1000000.00', 'solde_recalcule' => '0.00']], $cloture->ecart_details);
        $this->assertSame($avant, $this->lignes('2025-10-25'), 'les soldes figés ne changent jamais');
        $this->assertDatabaseHas('activity_log', ['event' => 'cloture_ecart', 'subject_id' => $cloture->id]);

        // l'annulation du paiement fait disparaître l'écart (tracée elle aussi)
        app(FournisseurDebtService::class)->cancel($remb, 'Saisie erronée');
        app(ClotureSoldesService::class)->verifier();
        $this->assertFalse($cloture->fresh()->hasEcart());
        $this->assertDatabaseHas('activity_log', ['event' => 'cloture_ecart_resolu', 'subject_id' => $cloture->id]);

        // un compte renommé puis supprimé reste dans l'historique, sous son nom d'alors
        $this->b->update(['name' => 'Fournisseur B renommé']);
        $this->b->delete();
        $this->assertArrayHasKey('Fournisseur B', $this->lignes('2025-10-25'));
    }

    // ------------------------------------------------------------------ lecture, filtres, exports

    public function test_filters_and_search_apply_to_the_frozen_records(): void
    {
        $this->journees26et27();
        $this->cloturer('2025-10-28 00:01:00');
        $service = app(ClotureHistoryService::class);
        $range = $this->range('2025-10-24', '2025-10-27');
        $dates = fn ($days) => $days->map(fn ($d) => $d['date']->toDateString())->all();

        $all = $service->tous($range, []);
        $this->assertSame(['2025-10-27', '2025-10-26', '2025-10-25', '2025-10-24'], $dates($all), 'plus récente en premier');
        $this->assertSame('2 dettes • 1 crédit', $all[2]['resume']);
        $this->assertSame('Aucun solde', $all[1]['resume']);
        $this->assertSame(['Fournisseur A', 'Agent Koffi', 'Fournisseur B'], $all[2]['lignes']->pluck('nom')->all(), 'montant décroissant');

        $this->assertSame(['Agent Koffi'], $service->tous($range, ['nature' => 'credit'])[2]['lignes']->pluck('nom')->all());
        $this->assertSame(['Fournisseur A', 'Fournisseur B'], $service->tous($range, ['type' => 'fournisseur'])[2]['lignes']->pluck('nom')->all());
        $this->assertSame(['2025-10-27', '2025-10-25'], $dates($service->tous($range, ['q' => 'fournisseur a', 'jours' => 'avec'])));
        $this->assertSame(['2025-10-26', '2025-10-24'], $dates($service->tous($range, ['jours' => 'sans'])));
        $this->assertSame(['2025-10-27', '2025-10-26', '2025-10-24'], $dates($service->tous($range, ['type' => 'agent', 'jours' => 'sans'])));

        // le solde actuel de Koffi (0) n'intervient pas : le filtre porte sur la clôture du 25
        $this->assertSame(['2025-10-25'], $dates($service->tous($range, ['q' => 'koffi', 'jours' => 'avec'])));

        // pagination par journée
        $page = $service->jours($range, [], 3, 2);
        $this->assertSame(4, $page->total());
        $this->assertSame(['2025-10-24'], $dates($page->getCollection()));
    }

    public function test_pdf_and_excel_exports_keep_days_without_balance(): void
    {
        $this->journees26et27();
        $this->cloturer('2025-10-28 10:00:00');
        $query = ['periode' => 'custom', 'du' => '2025-10-24', 'au' => '2025-10-27'];

        $category = app(ReportCatalog::class)->get('historique_dettes');
        $rows = (new ReportCategoryExport($category, $this->range('2025-10-24', '2025-10-27')))->array();
        $this->assertSame(['27/10/2025', 'Fournisseur', 'Fournisseur A', 'Dette', 400_000.0], $rows[0]);
        $this->assertSame(['26/10/2025', '—', 'Aucun solde', 'Aucun solde', 0.0], $rows[1]);
        $this->assertSame(['25/10/2025', 'Agent', 'Agent Koffi', 'Crédit', 350_000.0], $rows[3]);
        $this->assertCount(6, $rows, 'pas de ligne de total');

        foreach (['exports.categorie', 'm.exports.categorie'] as $route) {
            $pdf = $this->get(route($route, ['historique_dettes', 'pdf'] + $query + ['nature' => 'dette']));
            $pdf->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $pdf->streamedContent());

            $xlsx = $this->get(route($route, ['historique_dettes', 'xlsx'] + $query + ['jours' => 'sans']));
            $xlsx->assertOk();
            $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('Content-Type'));
        }
    }

    public function test_each_master_only_sees_and_closes_its_own_history(): void
    {
        $this->cloturer('2025-10-26 00:01:00');

        TenantContext::setFallback(null);
        auth()->guard()->forgetUser();
        $autre = Tenant::create(['name' => 'Société B']);
        TenantContext::runAs($autre->id, function () {
            $f = Fournisseur::factory()->create(['name' => 'Grossiste Secret', 'type' => 'AGENT_PREMIUM', 'is_active' => true]);
            $u = User::factory()->create();
            app(ApprovisionnementService::class)->create(['user_id' => $u->id, 'fournisseur_id' => $f->id, 'amount' => 90_000, 'date_approvisionnement' => '2025-10-25 08:00:00']);
            app(ClotureSoldesService::class)->cloturerJourneesManquantes();
        });

        $this->actingAs($this->master);
        $this->assertSame(['Agent Koffi', 'Fournisseur A', 'Fournisseur B'], collect($this->lignes('2025-10-25'))->keys()->sort()->values()->all());
        $this->assertSame(['Grossiste Secret'], TenantContext::runAs($autre->id, fn () => array_keys($this->lignes('2025-10-25'))));
        $this->assertSame(2, TenantContext::withoutTenancy(fn () => ClotureJournaliere::query()->where('date', '2025-10-25')->count()), 'une clôture par Master');

        $this->get('/m/dettes/historique?periode=custom&du=2025-10-24&au=2025-10-25')->assertOk()->assertSee('Fournisseur A')->assertDontSee('Grossiste Secret');
        $xlsx = (new ReportCategoryExport(app(ReportCatalog::class)->get('historique_dettes'), $this->range('2025-10-24', '2025-10-25')))->array();
        $this->assertNotContains('Grossiste Secret', array_column($xlsx, 2));
    }

    // ------------------------------------------------------------------ écrans Web et mobile

    public function test_web_and_mobile_screens_list_days_with_collapsible_sections_and_no_dashboard_widgets(): void
    {
        $this->journees26et27();
        $this->cloturer('2025-10-28 00:01:00');
        Carbon::setTestNow('2025-10-28 09:00:00');
        $query = ['periode' => 'custom', 'du' => '2025-10-24', 'au' => '2025-10-27'];

        $mobile = Livewire::withQueryParams($query)->test(Historique::class)
            ->assertSee('Historique des dettes et crédits')
            ->assertSeeInOrder(['27/10/2025', '1 dette', '26/10/2025', 'Aucun solde', '25/10/2025', '2 dettes', '1 crédit'])
            ->assertSee('Fournisseur A')->assertSee('Agent Koffi')
            ->assertSeeHtml('class="m-hday"')
            ->assertSeeHtml('exports/rapports/historique_dettes/pdf')
            ->assertDontSee('Solde net')->assertDontSee('Total dettes')
            ->set('nature', 'credit')
            ->assertSee('Agent Koffi')->assertDontSee('Fournisseur B')
            ->assertSeeHtml('nature=credit');
        $this->assertStringNotContainsString('<table', $mobile->html(), 'cartes, pas de tableau (pas de défilement horizontal)');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get(HistoriqueDettesPage::getUrl($query))->assertOk();
        Livewire::withQueryParams($query)->test(HistoriqueDettesPage::class)
            ->assertSeeInOrder(['27/10/2025', '26/10/2025', 'Aucun solde', '25/10/2025'])
            ->assertSee('1 000 000 FCFA')->assertSee('350 000 FCFA')
            ->assertDontSee('Total des dettes')->assertDontSee('Solde net global')
            ->assertSeeHtml('exports/rapports/historique_dettes/xlsx')
            ->set('q', 'koffi')->set('jours', 'avec')
            ->assertSee('Agent Koffi')->assertDontSee('Fournisseur A')->assertSee('1 journée');

        $css = (string) file_get_contents(resource_path('css/mobile.css'));
        $this->assertStringContainsString('.m-hday__tag--danger { background: var(--danger-soft); color: var(--danger); }', $css);
        $this->assertStringContainsString('.m-hday__tag--ok { background: var(--ok-soft); color: var(--ok); }', $css);
    }

    public function test_the_command_validates_its_dates_and_respects_bounds(): void
    {
        Carbon::setTestNow('2025-10-30 10:00:00');
        $this->artisan('soldes:cloturer --depuis=2025-13-01')->assertFailed();
        $this->artisan('soldes:cloturer --depuis=2025-10-01 --jusqua=2025-10-26')->assertSuccessful();

        $this->assertSame(['2025-10-24', '2025-10-25', '2025-10-26'], ClotureJournaliere::query()->orderBy('date')->pluck('date')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertSame(['2025-10-27', '2025-10-28', '2025-10-29'], app(ClotureSoldesService::class)->cloturerJourneesManquantes(CarbonImmutable::parse('2025-12-31')));
    }
}
