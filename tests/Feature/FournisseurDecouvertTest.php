<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\RemboursementFournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FournisseurDebtService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Phase 2 / 4 / 13 — solde fournisseur signé : dette (> 0), découvert (< 0, toujours affiché avec « − »), équilibre.
 * Un remboursement excédentaire crée un découvert ; un approvisionnement à crédit le compense automatiquement ;
 * l'annulation d'un remboursement le retire du solde sans jamais effacer l'opération d'origine.
 */
class FournisseurDecouvertTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private FournisseurDebtService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        $this->actingAs($this->master);
        $this->service = app(FournisseurDebtService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fournisseur(string $type = 'AGENT_PREMIUM', string $name = 'Grossiste B'): Fournisseur
    {
        return Fournisseur::factory()->create(['name' => $name, 'type' => $type, 'is_active' => true]);
    }

    private function appro(Fournisseur $fournisseur, int $amount, ?string $at = null): Approvisionnement
    {
        return Approvisionnement::create([
            'user_id' => $this->master->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => $amount,
            'date_approvisionnement' => $at ? Carbon::parse($at) : now(),
        ]);
    }

    private function paye(Fournisseur $fournisseur, int $amount, ?string $at = null): RemboursementFournisseur
    {
        return $this->service->process($fournisseur, $amount, 'cash', $at);
    }

    public function test_overpaying_a_debt_of_100000_with_150000_leaves_a_signed_overdraft_of_minus_50000(): void
    {
        $f = $this->fournisseur();
        $this->appro($f, 100_000);
        $this->paye($f, 150_000);

        $situation = $this->service->situation($f);

        $this->assertSame(-50_000.0, $situation['solde']);
        $this->assertSame(0.0, $situation['dette']);
        $this->assertSame(-50_000.0, $situation['decouvert']);
        $this->assertSame(FournisseurDebtService::STATUT_DECOUVERT, $situation['statut']);
        $this->assertSame(-50_000.0, $this->service->detteRestante($f));
    }

    public function test_an_overdraft_of_minus_50000_is_offset_by_a_150000_credit_supply_leaving_a_debt_of_100000(): void
    {
        $f = $this->fournisseur();
        $this->appro($f, 100_000, '2026-10-18 09:00');
        $this->paye($f, 150_000, '2026-10-19 09:00');
        $this->appro($f, 150_000, '2026-10-20 09:00');

        $situation = $this->service->situation($f);
        $this->assertSame(100_000.0, $situation['solde']);
        $this->assertSame(100_000.0, $situation['dette']);
        $this->assertSame(0.0, $situation['decouvert']);

        // compensation traçable dans l'historique
        $last = $this->service->history($f)->last();
        $this->assertSame(-50_000.0, $last['solde_avant']);
        $this->assertSame(100_000.0, $last['solde_apres']);
        $this->assertStringContainsString('50 000 FCFA compensés par le découvert', $last['note']);
    }

    public function test_an_overdraft_of_minus_100000_with_a_60000_credit_supply_leaves_minus_40000(): void
    {
        $f = $this->fournisseur();
        $this->paye($f, 100_000, '2026-10-19 09:00');
        $this->appro($f, 60_000, '2026-10-20 09:00');

        $situation = $this->service->situation($f);
        $this->assertSame(-40_000.0, $situation['solde']);
        $this->assertSame(0.0, $situation['dette']);
        $this->assertSame(-40_000.0, $situation['decouvert']);
        $this->assertStringContainsString('60 000 FCFA compensés', $this->service->history($f)->last()['note']);
    }

    public function test_history_replays_operations_of_the_same_minute_in_their_entry_order(): void
    {
        // Les formulaires saisissent l'heure à la minute : un paiement puis un approvisionnement dans la même minute
        // ont la même date d'opération, seul l'ordre d'enregistrement les départage.
        $f = $this->fournisseur();
        $this->appro($f, 100_000, '2026-10-20 09:00');
        Carbon::setTestNow('2026-10-20 10:00:10');
        $this->paye($f, 150_000, '2026-10-20 10:00');
        Carbon::setTestNow('2026-10-20 10:00:40');
        $this->appro($f, 100_000, '2026-10-20 10:00');

        $history = $this->service->history($f);

        $this->assertSame(['approvisionnement', 'remboursement', 'approvisionnement'], $history->pluck('type')->all());
        $this->assertSame([100_000.0, -50_000.0, 50_000.0], $history->pluck('solde_apres')->all());
        $this->assertStringContainsString('50 000 FCFA en avance', $history[1]['note']);
        $this->assertStringContainsString('50 000 FCFA compensés par le découvert', $history[2]['note']);
    }

    public function test_exact_partial_and_excess_repayments(): void
    {
        $exact = $this->fournisseur(name: 'Exact');
        $this->appro($exact, 100_000);
        $this->paye($exact, 100_000);
        $this->assertSame(0.0, $this->service->situation($exact)['solde']);
        $this->assertSame(FournisseurDebtService::STATUT_EQUILIBRE, $this->service->situation($exact)['statut']);

        $partiel = $this->fournisseur(name: 'Partiel');
        $this->appro($partiel, 100_000);
        $this->paye($partiel, 30_000);
        $this->assertSame(70_000.0, $this->service->situation($partiel)['dette']);
        $this->assertSame(FournisseurDebtService::STATUT_DETTE, $this->service->situation($partiel)['statut']);

        $exces = $this->fournisseur(name: 'Excès');
        $this->appro($exces, 100_000);
        $this->paye($exces, 130_000);
        $this->assertStringContainsString('30 000 FCFA en avance', $this->service->history($exces)->last()['note']);
    }

    public function test_a_cash_paid_supplier_never_accrues_debt_nor_is_double_counted(): void
    {
        $banque = $this->fournisseur('BANQUE', 'Banque');
        $this->appro($banque, 1_000_000);

        $situation = $this->service->situation($banque);
        $this->assertSame(1_000_000.0, $situation['total_approvisionne']);
        $this->assertSame(0.0, $situation['solde']);
        $this->assertSame(0.0, $this->service->globalSummary()['totalDette']);
    }

    public function test_the_global_summary_never_nets_one_suppliers_overdraft_against_another_suppliers_debt(): void
    {
        $a = $this->fournisseur(name: 'A');
        $b = $this->fournisseur(name: 'B');
        $this->appro($a, 200_000);
        $this->paye($b, 50_000);

        $summary = $this->service->globalSummary();
        $this->assertSame(200_000.0, $summary['resteAPayer']);
        $this->assertSame(-50_000.0, $summary['totalDecouvert']);
        $this->assertSame(150_000.0, $summary['soldeNet']);
        $this->assertSame(1, $summary['nbEndettes']);
        $this->assertSame(1, $summary['nbDecouverts']);
    }

    public function test_balance_at_a_past_date_is_rebuilt_from_recorded_movements(): void
    {
        $f = $this->fournisseur();
        $this->appro($f, 100_000, '2026-10-10 09:00');
        $this->paye($f, 150_000, '2026-10-12 09:00');
        $this->appro($f, 150_000, '2026-10-15 09:00');

        $this->assertSame(100_000.0, $this->service->situation($f, Carbon::parse('2026-10-11 23:59'))['solde']);
        $this->assertSame(-50_000.0, $this->service->situation($f, Carbon::parse('2026-10-13 00:00'))['solde']);
        $this->assertSame(100_000.0, $this->service->situation($f)['solde']);
    }

    public function test_cancelling_a_repayment_restores_the_balance_keeps_the_original_and_is_audited(): void
    {
        $f = $this->fournisseur();
        $this->appro($f, 100_000);
        $paiement = $this->paye($f, 150_000);
        $this->assertSame(-50_000.0, $this->service->detteRestante($f));

        $annule = $this->service->cancel($paiement, 'Saisie en double');

        $this->assertTrue($annule->isCancelled());
        $this->assertSame($this->master->id, $annule->cancelled_by);
        $this->assertSame('Saisie en double', $annule->cancellation_reason);
        $this->assertSame(100_000.0, $this->service->detteRestante($f));
        $this->assertSame(100_000.0, $this->service->globalSummary()['resteAPayer']);
        // l'opération d'origine reste en base et dans l'historique, avec un impact nul
        $this->assertSame(1, RemboursementFournisseur::count());
        $ligne = $this->service->history($f)->last();
        $this->assertSame('Annulé', $ligne['statut']);
        $this->assertSame(0.0, $ligne['impact']);
        $this->assertTrue($ligne['annule']);

        $log = Activity::query()->where('event', 'cancelled')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Saisie en double', $log->properties['motif']);
        $this->assertSame(-50_000.0, (float) $log->properties['solde_avant']);
        $this->assertSame(100_000.0, (float) $log->properties['solde_apres']);
        $this->assertSame($this->master->id, $log->causer_id);
    }

    public function test_a_repayment_can_never_be_cancelled_twice_and_a_reason_is_required(): void
    {
        $f = $this->fournisseur();
        $this->appro($f, 100_000);
        $paiement = $this->paye($f, 40_000);

        $this->assertThrows(fn () => $this->service->cancel($paiement, ' '), ValidationException::class);
        $this->service->cancel($paiement, 'Erreur de montant');
        $this->assertThrows(fn () => $this->service->cancel($paiement->fresh(), 'Encore'), ValidationException::class);
        $this->assertSame(100_000.0, $this->service->detteRestante($f), 'la seconde tentative ne change rien');
    }

    public function test_a_cancelled_repayment_is_frozen_and_only_authorized_users_may_cancel(): void
    {
        $f = $this->fournisseur();
        $this->appro($f, 100_000);
        $paiement = $this->paye($f, 40_000);

        $commercial = User::factory()->create();
        $commercial->assignRole('commercial');
        $lecture = User::factory()->create();
        $lecture->assignRole(User::ROLE_READ_ONLY);

        $this->assertTrue($this->master->can('cancel', $paiement));
        $this->assertFalse($commercial->can('cancel', $paiement));
        $this->assertFalse($lecture->can('cancel', $paiement));

        $this->service->cancel($paiement, 'Erreur');
        $this->assertFalse($this->master->can('cancel', $paiement->fresh()));
        $this->assertFalse($this->master->can('update', $paiement->fresh()));
    }

    public function test_an_identical_double_submission_is_refused(): void
    {
        $f = $this->fournisseur();
        $this->appro($f, 100_000);
        $this->paye($f, 40_000, '2026-10-20 09:30');

        $this->assertThrows(fn () => $this->paye($f, 40_000, '2026-10-20 09:30'), ValidationException::class);
        $this->assertSame(1, RemboursementFournisseur::count());
        // un paiement différent passe
        $this->paye($f, 40_000, '2026-10-20 09:31');
        $this->assertSame(2, RemboursementFournisseur::count());
    }

    public function test_a_master_never_sees_nor_cancels_another_masters_supplier_payment(): void
    {
        TenantContext::setFallback(null);
        $autre = Tenant::create(['name' => 'Société B']);
        $paiementB = TenantContext::runAs($autre->id, function () {
            $masterB = User::factory()->create();
            $masterB->assignRole('master');
            $this->actingAs($masterB);
            $fB = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true]);
            Approvisionnement::create(['user_id' => $masterB->id, 'fournisseur_id' => $fB->id, 'amount' => 80_000, 'date_approvisionnement' => now()]);

            return app(FournisseurDebtService::class)->process($fB, 80_000);
        });

        $this->actingAs($this->master);
        $this->assertNull(RemboursementFournisseur::find($paiementB->id), 'scope d\'entreprise');
        $this->assertThrows(fn () => $this->service->cancel($paiementB, 'Intrusion'), ValidationException::class);
        $this->assertNull(TenantContext::withoutTenancy(fn () => RemboursementFournisseur::find($paiementB->id))->cancelled_at);
    }
}
