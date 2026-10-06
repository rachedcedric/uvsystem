<?php

namespace App\Services\Mobile;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Services\BusinessPeriodResolver;
use App\Services\ChargeStatisticsService;
use App\Services\CommissionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Widgets « mois en cours » du bureau, repris tels quels pour l'accueil, les commissions et les ventes :
 * Revenu / Charges / Revenu net, Performance mensuelle, Évolution du CA et des commissions, Top 5 du jour.
 *
 * Mêmes formules et mêmes sources que MonthlyPerformanceWidget, RevenueChargesNetWidget, MonthlyChartWidget,
 * CommissionMonthlyChartWidget et Top5AgentsTodayWidget, avec une différence volontaire : les ventes annulées
 * ne comptent jamais dans le chiffre d'affaires (comme dans les cartes Ventes UV).
 */
class MonthOverview
{
    /** @return array{revenue: float, charges: float, net: float, label: string} */
    public function revenueCharges(): array
    {
        $summary = app(ChargeStatisticsService::class)->summary(ChargeStatisticsService::PERIOD_THIS_MONTH);

        return [
            'revenue' => (float) $summary['revenue'],
            'charges' => (float) $summary['charges'],
            'net' => (float) $summary['net'],
            'label' => $summary['period_label'],
        ];
    }

    /**
     * Les 6 indicateurs de « Performance mensuelle » avec leur valeur du mois précédent.
     *
     * @return list<array{key: string, label: string, icon: string, now: float, before: float, good: string, money: bool, vs: string}>
     */
    public function performance(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();
        $prevStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $sales = fn (Carbon $a, Carbon $b) => UvTransaction::where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')->whereBetween('transaction_date', [$a, $b]);

        $sum = fn ($query) => (float) $query->sum('amount');

        $appro = fn (Carbon $a, Carbon $b) => (float) Approvisionnement::whereBetween('date_approvisionnement', [$a, $b])->sum('amount');
        $rembs = fn (Carbon $a, Carbon $b) => (float) Remboursement::whereBetween('payment_date', [$a, $b])->sum('amount');
        $commissions = fn (Carbon $a, Carbon $b) => (float) Commission::globales()->whereBetween('date_commission', [$a, $b])->sum('commission_globale');

        $vsMonth = 'vs mois précédent';

        return [
            ['key' => 'ca', 'label' => 'Ventes du mois (CA)', 'icon' => 'shopping-cart', 'now' => $sum($sales($start, $end)), 'before' => $sum($sales($prevStart, $prevEnd)), 'good' => 'up', 'money' => true, 'vs' => $vsMonth],
            ['key' => 'appro', 'label' => 'Approvisionnements', 'icon' => 'truck', 'now' => $appro($start, $end), 'before' => $appro($prevStart, $prevEnd), 'good' => 'up', 'money' => true, 'vs' => $vsMonth],
            ['key' => 'rembs', 'label' => 'Remboursements', 'icon' => 'arrow-uturn-left', 'now' => $rembs($start, $end), 'before' => $rembs($prevStart, $prevEnd), 'good' => 'up', 'money' => true, 'vs' => $vsMonth],
            ['key' => 'commissions', 'label' => 'Commissions générées', 'icon' => 'star', 'now' => $commissions($start, $end), 'before' => $commissions($prevStart, $prevEnd), 'good' => 'up', 'money' => true, 'vs' => $vsMonth],
            ['key' => 'transactions', 'label' => 'Transactions (ventes)', 'icon' => 'receipt-percent', 'now' => (float) $sales($start, $end)->count(), 'before' => (float) $sales($prevStart, $prevEnd)->count(), 'good' => 'up', 'money' => false, 'vs' => $vsMonth],
            ['key' => 'dettes', 'label' => 'Dettes en cours', 'icon' => 'exclamation-triangle', 'now' => (float) Agent::active()->withDebt()->sum('current_debt'), 'before' => (float) Agent::totalDetteActiveAsOf($start->copy()->subSecond()), 'good' => 'down', 'money' => true, 'vs' => 'vs début du mois'],
        ];
    }

    /** Variation en % entre deux valeurs (même règle que le bureau : base nulle → 100 % si la valeur est positive). */
    public static function pct(float $now, float $before): float
    {
        if ($before == 0.0) {
            return $now == 0.0 ? 0.0 : 100.0;
        }

        return (($now - $before) / $before) * 100;
    }

    /**
     * Évolution du chiffre d'affaires, un total par jour du 1er à aujourd'hui (0 explicite les jours sans vente).
     *
     * @return list<array{date: string, label: string, value: float, today: bool}>
     */
    public function dailySales(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $totals = UvTransaction::where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$now->copy()->startOfMonth(), $now])
            ->selectRaw('DATE(transaction_date) as jour, SUM(amount) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        return $this->days($totals, $now);
    }

    /**
     * Évolution des commissions du mois (commission globale de chaque journée métier).
     *
     * @return list<array{date: string, label: string, value: float, today: bool}>
     */
    public function dailyCommissions(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $totals = Commission::globales()
            ->forPeriod($now->copy()->startOfMonth(), $now->copy()->startOfDay())
            ->selectRaw('DATE(date_commission) as jour, SUM(commission_globale) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        return $this->days($totals, $now);
    }

    /** Commission de la dernière journée métier clôturée (« Commission d'hier »). */
    public function commissionYesterday(): float
    {
        $yesterday = app(BusinessPeriodResolver::class)->currentBusinessDate()->copy()->subDay();

        return app(CommissionService::class)->commissionPourJourneeMetier($yesterday);
    }

    /**
     * Top 5 des agents de la journée métier en cours (clôture 18h02).
     *
     * @return Collection<int, array{id: ?int, name: string, amount: float}>
     */
    public function topAgentsToday(?Carbon $now = null): Collection
    {
        $today = app(BusinessPeriodResolver::class)->today($now);

        return UvTransaction::query()
            ->selectRaw('agent_id, SUM(amount) as total')
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$today['start'], $today['end']])
            ->groupBy('agent_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('agent:id,name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->agent?->id,
                'name' => $row->agent?->name ?? 'Agent inconnu',
                'amount' => (float) $row->total,
            ]);
    }

    /**
     * @param  Collection<string, mixed>  $totals  total par date « Y-m-d »
     * @return list<array{date: string, label: string, value: float, today: bool}>
     */
    private function days(Collection $totals, Carbon $now): array
    {
        $days = [];

        for ($d = $now->copy()->startOfMonth(); $d->lte($now); $d->addDay()) {
            $key = $d->toDateString();
            $days[] = ['date' => $key, 'label' => (string) $d->day, 'value' => (float) ($totals[$key] ?? 0), 'today' => $d->isSameDay($now)];
        }

        return $days;
    }
}
