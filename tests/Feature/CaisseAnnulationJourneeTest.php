<?php

namespace Tests\Feature;

use App\Filament\Pages\MaCaisseJourPage;
use App\Livewire\Mobile\Caisse\Jour;
use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Phase 5 / 13 — « Supprimer les mouvements de cette journée » : annulation logique tracée (jamais de suppression
 * physique), confirmation renforcée, refus d'une seconde annulation, soldes recalculés, réservée au master / admin.
 */
class CaisseAnnulationJourneeTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = CaissePeriodResolver::TIMEZONE;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-20 15:00:00', self::TZ));
        CaisseCategorie::firstOrCreate(['name' => 'Non catégorisé'], ['is_system' => true, 'is_active' => true, 'sort_order' => 999]);

        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        $this->actingAs($this->master);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mouvement(string $when, string $type, int $amount): CaisseMouvement
    {
        return CaisseMouvement::create([
            'type' => $type, 'amount' => $amount, 'libelle' => 'Test', 'user_id' => $this->master->id,
            'caisse_categorie_id' => CaisseCategorie::where('is_system', true)->value('id'),
            'date_mouvement' => Carbon::parse($when, self::TZ),
        ]);
    }

    private function day(string $date): Carbon
    {
        return Carbon::parse($date, self::TZ)->startOfDay();
    }

    public function test_cancelling_a_day_neutralises_its_movements_keeps_them_and_leaves_other_days_untouched(): void
    {
        $this->mouvement('2026-10-19 09:00', 'entree', 100_000);
        $this->mouvement('2026-10-19 10:00', 'sortie', 30_000);
        $autre = $this->mouvement('2026-10-18 09:00', 'entree', 50_000);
        $service = app(CaisseService::class);

        $result = $service->annulerJournee($this->day('2026-10-19'), 'Journée saisie par erreur', '19/10/2026');

        $this->assertSame(2, $result['count']);
        $this->assertSame('100000.00', $result['entrees']);
        $this->assertSame(['count' => 0, 'entrees' => '0', 'sorties' => '0', 'solde' => '0.00'], $service->dayTotals($this->day('2026-10-19')));
        $this->assertSame('50000.00', $service->dayTotals($this->day('2026-10-18'))['solde'], 'les autres journées ne changent pas');
        $this->assertNull($autre->fresh()->cancelled_at);

        // jamais de suppression physique : les lignes restent, tracées et regroupées dans un même lot
        $annules = $service->mouvementsAnnulesQuery($this->day('2026-10-19'))->get();
        $this->assertCount(2, $annules);
        $this->assertSame(3, CaisseMouvement::query()->withoutGlobalScope(CaisseMouvement::SCOPE_ACTIFS)->count());
        $this->assertSame([$result['batch']], $annules->pluck('cancellation_batch')->unique()->values()->all());
        $this->assertSame($this->master->id, $annules->first()->cancelled_by);

        $log = Activity::query()->where('event', 'day_cancelled')->latest('id')->first();
        $this->assertSame('2026-10-19', $log->properties['journee']);
        $this->assertSame('Journée saisie par erreur', $log->properties['motif']);
        $this->assertCount(2, $log->properties['references']);
    }

    public function test_reinforced_confirmation_reason_future_days_and_repeated_cancellations_are_refused(): void
    {
        $this->mouvement('2026-10-19 09:00', 'entree', 10_000);
        $service = app(CaisseService::class);

        $this->assertThrows(fn () => $service->annulerJournee($this->day('2026-10-19'), 'Erreur', '20/10/2026'), ValidationException::class);
        $this->assertThrows(fn () => $service->annulerJournee($this->day('2026-10-19'), '', '19/10/2026'), ValidationException::class);
        $this->assertThrows(fn () => $service->annulerJournee($this->day('2026-10-21'), 'Erreur', '21/10/2026'), ValidationException::class);
        $this->assertSame(1, $service->dayTotals($this->day('2026-10-19'))['count'], 'rien n\'a été annulé');

        $service->annulerJournee($this->day('2026-10-19'), 'Erreur', '19/10/2026');
        $this->assertThrows(fn () => $service->annulerJournee($this->day('2026-10-19'), 'Encore', '19/10/2026'), ValidationException::class);
        $this->assertSame(1, Activity::query()->where('event', 'day_cancelled')->count());
    }

    public function test_only_master_and_admin_may_cancel_a_day(): void
    {
        $comptable = User::factory()->create();
        $comptable->assignRole('comptable'); // a pourtant delete_caisse
        $lecture = User::factory()->create();
        $lecture->assignRole(User::ROLE_READ_ONLY);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($this->master->can('cancelDay', CaisseMouvement::class));
        $this->assertTrue($admin->can('cancelDay', CaisseMouvement::class));
        $this->assertFalse($comptable->can('cancelDay', CaisseMouvement::class));
        $this->assertFalse($lecture->can('cancelDay', CaisseMouvement::class));

        $this->mouvement('2026-10-19 09:00', 'entree', 10_000);
        $this->actingAs($comptable);
        Livewire::test(Jour::class, ['date' => '2026-10-19'])
            ->assertDontSee('Supprimer les mouvements de cette journée')
            ->set('cancelReason', 'Erreur')->set('cancelConfirmation', '19/10/2026')
            ->call('cancelDay')->assertForbidden();
        $this->assertSame(1, app(CaisseService::class)->dayTotals($this->day('2026-10-19'))['count']);
    }

    public function test_mobile_day_screen_cancels_after_reinforced_confirmation_and_lists_cancelled_movements(): void
    {
        $this->mouvement('2026-10-19 09:00', 'entree', 25_000);

        Livewire::test(Jour::class, ['date' => '2026-10-19'])
            ->assertSee('Supprimer les mouvements de cette journée')
            ->assertSee('Attention : vous êtes sur le point de supprimer les mouvements du 19/10/2026. Cette action modifiera les historiques et les soldes concernés.')
            ->set('cancelReason', 'Doublon')->set('cancelConfirmation', '19-10-2026')
            ->call('cancelDay')->assertDispatched('m-toast')
            ->set('cancelConfirmation', '19/10/2026')
            ->call('cancelDay')->assertDispatched('m-toast')
            ->assertSee('Mouvements annulés')
            ->assertSee('Doublon');

        $this->assertSame(0, app(CaisseService::class)->dayTotals($this->day('2026-10-19'))['count']);
    }

    public function test_web_day_page_action_cancels_the_day(): void
    {
        $this->mouvement('2026-10-19 09:00', 'entree', 25_000);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MaCaisseJourPage::class, ['date' => '2026-10-19'])
            ->assertActionVisible('cancelDay')
            ->callAction('cancelDay', data: ['reason' => 'Doublon', 'confirmation' => '19/10/2026'])
            ->assertHasNoActionErrors()
            ->assertSee('Mouvements annulés');

        $this->assertSame(0, app(CaisseService::class)->dayTotals($this->day('2026-10-19'))['count']);
    }

    public function test_cancellation_never_reaches_another_company_and_the_full_data_export_keeps_cancelled_lines(): void
    {
        $this->mouvement('2026-10-19 09:00', 'entree', 10_000);

        TenantContext::setFallback(null);
        $autre = Tenant::create(['name' => 'Société B']);
        TenantContext::runAs($autre->id, function () {
            $cat = CaisseCategorie::create(['name' => 'Non catégorisé', 'is_system' => true, 'is_active' => true, 'sort_order' => 999]);
            CaisseMouvement::create(['type' => 'entree', 'amount' => 70_000, 'libelle' => 'B', 'caisse_categorie_id' => $cat->id, 'date_mouvement' => Carbon::parse('2026-10-19 09:00', self::TZ)]);
        });

        $this->actingAs($this->master);
        app(CaisseService::class)->annulerJournee($this->day('2026-10-19'), 'Erreur', '19/10/2026');

        $this->assertSame(1, TenantContext::runAs($autre->id, fn () => app(CaisseService::class)->dayTotals($this->day('2026-10-19'))['count']));
        $this->assertSame(1, CaisseMouvement::query()->withoutGlobalScope(CaisseMouvement::SCOPE_ACTIFS)->count(), 'scope d\'entreprise conservé');
    }
}
