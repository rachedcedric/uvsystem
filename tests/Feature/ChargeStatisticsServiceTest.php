<?php

use App\Models\Charge;
use App\Models\Commission;
use App\Services\ChargeStatisticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function statsService(): ChargeStatisticsService
{
    return app(ChargeStatisticsService::class);
}

/**
 * Crée une ligne de commission globale déjà calculée pour une date donnée
 * (même structure que celles produites par CommissionService).
 */
function statsCommission(Carbon $date, float $montant): Commission
{
    return Commission::create([
        'agent_id' => null,
        'is_global' => true,
        'date_commission' => $date->toDateString(),
        'volume_total_jour' => $montant * 100,
        'commission_globale' => $montant,
        'volume_agent' => 0,
        'part_agent' => 0,
        'pourcentage_contribution' => 100,
        'nombre_transactions' => 1,
        'montant_vente' => $montant * 100,
        'montant_commission' => $montant,
        'seuil_min' => 0,
        'seuil_max' => 0,
        'statut' => Commission::STATUT_CALCULATED,
        'calculated_at' => now(),
    ]);
}

function statsCharge(Carbon $date, float $montant): Charge
{
    return Charge::create([
        'libelle' => 'Charge test',
        'montant' => $montant,
        'date_charge' => $date->toDateString(),
    ]);
}

describe('ChargeStatisticsService', function () {

    it('returns zero revenue when there is no commission in the period', function () {
        $summary = statsService()->summary('this_month');

        expect($summary['revenue'])->toBe(0.0);
    });

    it('returns zero charges when commissions exist but no charge was recorded', function () {
        statsCommission(Carbon::now()->startOfMonth()->addDay(), 10000);

        $summary = statsService()->summary('this_month');

        expect($summary['revenue'])->toBe(10000.0)
            ->and($summary['charges'])->toBe(0.0)
            ->and($summary['net'])->toBe(10000.0);
    });

    it('returns zero revenue when charges exist but no commission was generated', function () {
        statsCharge(Carbon::now()->startOfMonth()->addDay(), 5000);

        $summary = statsService()->summary('this_month');

        expect($summary['revenue'])->toBe(0.0)
            ->and($summary['charges'])->toBe(5000.0)
            ->and($summary['net'])->toBe(-5000.0);
    });

    it('sums multiple commission rows within the period', function () {
        $start = Carbon::now()->startOfMonth();
        statsCommission($start->copy()->addDay(), 2000);
        statsCommission($start->copy()->addDays(2), 3500);
        statsCommission($start->copy()->addDays(3), 1500);

        expect(statsService()->totalRevenue($start, Carbon::now()->endOfMonth()))->toBe(7000.0);
    });

    it('sums multiple charge rows within the period', function () {
        $start = Carbon::now()->startOfMonth();
        statsCharge($start->copy()->addDay(), 30000);
        statsCharge($start->copy()->addDays(1), 50000);
        statsCharge($start->copy()->addDays(2), 150000);
        statsCharge($start->copy()->addDays(3), 25000);

        expect(statsService()->totalCharges($start, Carbon::now()->endOfMonth()))->toBe(255000.0);
    });

    it('computes a positive net revenue', function () {
        $start = Carbon::now()->startOfMonth();
        statsCommission($start->copy()->addDay(), 500000);
        statsCharge($start->copy()->addDay(), 150000);

        $summary = statsService()->summary('this_month');

        expect($summary['net'])->toBe(350000.0);
    });

    it('computes a zero net revenue when revenue equals charges', function () {
        $start = Carbon::now()->startOfMonth();
        statsCommission($start->copy()->addDay(), 100000);
        statsCharge($start->copy()->addDay(), 100000);

        expect(statsService()->summary('this_month')['net'])->toBe(0.0);
    });

    it('computes a negative net revenue when charges exceed revenue', function () {
        $start = Carbon::now()->startOfMonth();
        statsCommission($start->copy()->addDay(), 50000);
        statsCharge($start->copy()->addDay(), 80000);

        expect(statsService()->summary('this_month')['net'])->toBe(-30000.0);
    });

    it('excludes a commission dated outside the "today" period', function () {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0));

        statsCommission(Carbon::create(2026, 6, 14), 10000); // hier
        statsCommission(Carbon::create(2026, 6, 15), 20000); // aujourd'hui

        expect(statsService()->summary('today')['revenue'])->toBe(20000.0);
    });

    it('excludes a charge dated on the last day of the previous month from "this_month"', function () {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0));

        statsCharge(Carbon::create(2026, 5, 31), 10000); // mois précédent
        statsCharge(Carbon::create(2026, 6, 1), 20000); // ce mois

        expect(statsService()->summary('this_month')['charges'])->toBe(20000.0);
    });

    it('resolves "last_month" to the correct boundaries, inverse of "this_month"', function () {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0));

        statsCharge(Carbon::create(2026, 5, 31), 10000);
        statsCharge(Carbon::create(2026, 6, 1), 20000);

        expect(statsService()->summary('last_month')['charges'])->toBe(10000.0);
    });

    it('flips period boundaries correctly across a month change', function () {
        Carbon::setTestNow(Carbon::create(2026, 1, 31, 23, 0));
        $endOfJanuary = statsService()->resolvePeriod('this_month');
        expect($endOfJanuary['end']->format('Y-m-d'))->toBe('2026-01-31');

        Carbon::setTestNow(Carbon::create(2026, 2, 1, 0, 30));
        $startOfFebruary = statsService()->resolvePeriod('this_month');
        expect($startOfFebruary['start']->format('Y-m-d'))->toBe('2026-02-01')
            ->and($startOfFebruary['label'])->toContain('01/02/2026');
    });

    it('defaults to "this_month" when no filter key is given', function () {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 10, 0));

        $period = statsService()->resolvePeriod(null);

        expect($period['start']->format('Y-m-d'))->toBe('2026-08-01')
            ->and($period['end']->format('Y-m-d'))->toBe('2026-08-31')
            ->and($period['label'])->toBe('Mois en cours : du 01/08/2026 au 31/08/2026');
    });
});
