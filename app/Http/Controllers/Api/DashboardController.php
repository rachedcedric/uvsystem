<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Get main dashboard statistics.
     */
    public function index(Request $request): JsonResponse
    {
        $cacheKey = 'api_dashboard_stats_'.now()->format('Y-m-d-H');

        $stats = Cache::remember($cacheKey, 300, function () {
            return [
                'agents' => $this->getAgentStats(),
                'today' => $this->getTodayStats(),
                'month' => $this->getMonthStats(),
                'debts' => $this->getDebtStats(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get real-time dashboard data (not cached).
     */
    public function realtime(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'agents' => $this->getAgentStats(),
                'today' => $this->getTodayStats(),
                'debts' => $this->getDebtStats(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get chart data for dashboard.
     */
    public function charts(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);

        $salesTrend = UvTransaction::selectRaw('DATE(transaction_date) as date, SUM(amount) as total')
            ->where('transaction_date', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('total', 'date');

        $reimbursementTrend = Remboursement::selectRaw('DATE(payment_date) as date, SUM(amount) as total')
            ->where('payment_date', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('total', 'date');

        $agentsByStatus = Agent::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        $debtDistribution = Agent::selectRaw('
                CASE
                    WHEN current_debt = 0 THEN "clean"
                    WHEN debt_limit > 0 AND (current_debt / debt_limit * 100) >= 90 THEN "critical"
                    WHEN debt_limit > 0 AND (current_debt / debt_limit * 100) >= 70 THEN "warning"
                    ELSE "normal"
                END as category,
                COUNT(*) as count
            ')
            ->where('status', 'active')
            ->groupBy('category')
            ->get()
            ->pluck('count', 'category');

        return response()->json([
            'success' => true,
            'data' => [
                'sales_trend' => $salesTrend,
                'reimbursement_trend' => $reimbursementTrend,
                'agents_by_status' => $agentsByStatus,
                'debt_distribution' => $debtDistribution,
            ],
        ]);
    }

    /**
     * Get top agents ranking.
     */
    public function topAgents(Request $request): JsonResponse
    {
        $period = $request->get('period', 'month');
        $limit = min($request->get('limit', 10), 50);

        $startDate = match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        $topByVolume = UvTransaction::selectRaw('agent_id, SUM(amount) as total_volume, COUNT(*) as transactions_count')
            ->where('transaction_date', '>=', $startDate)
            ->groupBy('agent_id')
            ->orderByDesc('total_volume')
            ->limit($limit)
            ->with('agent:id,name,phone')
            ->get();

        $topByReimbursement = Remboursement::selectRaw('agent_id, SUM(amount) as total_paid')
            ->where('payment_date', '>=', $startDate)
            ->groupBy('agent_id')
            ->orderByDesc('total_paid')
            ->limit($limit)
            ->with('agent:id,name,phone')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'start_date' => $startDate->toDateString(),
                'top_by_volume' => $topByVolume,
                'top_by_reimbursement' => $topByReimbursement,
            ],
        ]);
    }

    /**
     * Get debt alerts.
     */
    public function alerts(Request $request): JsonResponse
    {
        $criticalAgents = Agent::criticalDebt()
            ->with('solde')
            ->get()
            ->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'phone' => $agent->phone,
                'current_debt' => $agent->current_debt,
                'debt_limit' => $agent->debt_limit,
                'debt_ratio' => $agent->debt_ratio,
                'level' => 'critical',
            ]);

        $warningAgents = Agent::warningDebt()
            ->with('solde')
            ->get()
            ->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'phone' => $agent->phone,
                'current_debt' => $agent->current_debt,
                'debt_limit' => $agent->debt_limit,
                'debt_ratio' => $agent->debt_ratio,
                'level' => 'warning',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'critical' => $criticalAgents,
                'warning' => $warningAgents,
                'summary' => [
                    'critical_count' => $criticalAgents->count(),
                    'warning_count' => $warningAgents->count(),
                    'total_critical_debt' => $criticalAgents->sum('current_debt'),
                    'total_warning_debt' => $warningAgents->sum('current_debt'),
                ],
            ],
        ]);
    }

    // Private helper methods

    private function getAgentStats(): array
    {
        return [
            'total' => Agent::count(),
            'active' => Agent::active()->count(),
            'inactive' => Agent::where('status', 'inactive')->count(),
            'suspended' => Agent::where('status', 'suspended')->count(),
            'with_debt' => Agent::withDebt()->count(),
        ];
    }

    private function getTodayStats(): array
    {
        return [
            'transactions' => UvTransaction::whereDate('transaction_date', today())->count(),
            'chiffre_affaires' => UvTransaction::whereDate('transaction_date', today())->sum('amount'),
            'remboursements' => Remboursement::whereDate('payment_date', today())->sum('amount'),
        ];
    }

    private function getMonthStats(): array
    {
        return [
            'transactions' => UvTransaction::whereMonth('transaction_date', now()->month)
                ->whereYear('transaction_date', now()->year)
                ->count(),
            'chiffre_affaires' => UvTransaction::whereMonth('transaction_date', now()->month)
                ->whereYear('transaction_date', now()->year)
                ->sum('amount'),
            'remboursements' => Remboursement::whereMonth('payment_date', now()->month)
                ->whereYear('payment_date', now()->year)
                ->sum('amount'),
        ];
    }

    private function getDebtStats(): array
    {
        $totalDebt = Agent::active()->sum('current_debt');
        $criticalCount = Agent::criticalDebt()->count();
        $warningCount = Agent::warningDebt()->count();

        return [
            'total_debt' => $totalDebt,
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'clean_count' => Agent::active()->where('current_debt', 0)->count(),
        ];
    }
}
