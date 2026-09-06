<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for generating reports and statistics
 */
class ReportController extends Controller
{
    /**
     * Get general reports with date filters
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'in:today,week,month,year,custom'],
            'start_date' => ['required_if:period,custom', 'date'],
            'end_date' => ['required_if:period,custom', 'date', 'after_or_equal:start_date'],
        ]);

        $period = $validated['period'] ?? 'month';
        [$startDate, $endDate] = $this->getPeriodDates($period, $validated);

        // Transactions stats
        $transactionsStats = UvTransaction::whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->first();

        // Remboursements stats
        $remboursementsStats = Remboursement::whereBetween('payment_date', [$startDate, $endDate])
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->first();

        // Daily breakdown
        $dailyStats = UvTransaction::whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('DATE(transaction_date) as date')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(amount) as amount')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top agents for period
        $topAgents = Agent::withCount(['transactions' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('transaction_date', [$startDate, $endDate]);
        }])
            ->withSum(['transactions' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('transaction_date', [$startDate, $endDate]);
            }], 'amount')
            ->orderByDesc('transactions_sum_amount')
            ->limit(10)
            ->get()
            ->filter(fn ($agent) => $agent->transactions_count > 0)
            ->values();

        // Debt summary
        $debtSummary = Agent::where('status', 'active')
            ->with('solde')
            ->get()
            ->map(function ($agent) {
                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'debt' => $agent->solde?->solde_restant ?? 0,
                    'debt_limit' => $agent->debt_limit,
                    'percentage' => $agent->debt_limit > 0
                        ? round(($agent->solde?->solde_restant ?? 0) / $agent->debt_limit * 100, 2)
                        : 0,
                ];
            })
            ->filter(fn ($a) => $a['debt'] > 0)
            ->sortByDesc('debt')
            ->values()
            ->take(10);

        return response()->json([
            'data' => [
                'period' => [
                    'type' => $period,
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'transactions' => [
                    'count' => $transactionsStats->total_count ?? 0,
                    'total_uv' => $transactionsStats->total_uv ?? 0,
                    'total_amount' => $transactionsStats->total_amount ?? 0,
                ],
                'remboursements' => [
                    'count' => $remboursementsStats->total_count ?? 0,
                    'total_amount' => $remboursementsStats->total_amount ?? 0,
                ],
                'daily_stats' => $dailyStats,
                'top_agents' => $topAgents,
                'debt_summary' => $debtSummary,
            ],
        ]);
    }

    /**
     * Get agents report
     */
    public function agents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'in:today,week,month,year,custom'],
            'start_date' => ['required_if:period,custom', 'date'],
            'end_date' => ['required_if:period,custom', 'date'],
            'status' => ['sometimes', 'in:active,inactive,suspended'],
        ]);

        $period = $validated['period'] ?? 'month';
        [$startDate, $endDate] = $this->getPeriodDates($period, $validated);

        $query = Agent::query()
            ->with('solde')
            ->withCount(['transactions' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('transaction_date', [$startDate, $endDate]);
            }])
            ->withSum(['transactions' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('transaction_date', [$startDate, $endDate]);
            }], 'amount')
            ->withSum(['remboursements' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('payment_date', [$startDate, $endDate]);
            }], 'amount');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $agents = $query->orderByDesc('transactions_sum_amount')->get();

        return response()->json([
            'data' => [
                'period' => [
                    'type' => $period,
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'agents' => $agents->map(function ($agent) {
                    return [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'phone' => $agent->phone,
                        'type' => $agent->type,
                        'status' => $agent->status,
                        'transactions_count' => $agent->transactions_count,
                        'total_amount' => $agent->transactions_sum_amount ?? 0,
                        'remboursements_amount' => $agent->remboursements_sum_amount ?? 0,
                        'current_debt' => $agent->solde?->solde_restant ?? 0,
                        'debt_limit' => $agent->debt_limit,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Get transactions report
     */
    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'in:today,week,month,year,custom'],
            'start_date' => ['required_if:period,custom', 'date'],
            'end_date' => ['required_if:period,custom', 'date'],
            'agent_id' => ['sometimes', 'exists:agents,id'],
        ]);

        $period = $validated['period'] ?? 'month';
        [$startDate, $endDate] = $this->getPeriodDates($period, $validated);

        $query = UvTransaction::with('agent:id,name,phone')
            ->whereBetween('transaction_date', [$startDate, $endDate]);

        if (isset($validated['agent_id'])) {
            $query->where('agent_id', $validated['agent_id']);
        }

        $transactions = $query->orderByDesc('transaction_date')->get();

        // Summary
        $summary = [
            'total_count' => $transactions->count(),
            'total_amount' => $transactions->sum('amount'),
            'by_type' => $transactions->groupBy('type')->map(fn ($group) => [
                'count' => $group->count(),
                'amount' => $group->sum('amount'),
            ]),
        ];

        return response()->json([
            'data' => [
                'period' => [
                    'type' => $period,
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'summary' => $summary,
                'transactions' => $transactions,
            ],
        ]);
    }

    /**
     * Export report to Excel format (returns data for client-side export)
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:agents,transactions,remboursements,summary'],
            'period' => ['sometimes', 'in:today,week,month,year,custom'],
            'start_date' => ['required_if:period,custom', 'date'],
            'end_date' => ['required_if:period,custom', 'date'],
            'format' => ['sometimes', 'in:json,csv'],
        ]);

        $period = $validated['period'] ?? 'month';
        [$startDate, $endDate] = $this->getPeriodDates($period, $validated);

        $data = match ($validated['type']) {
            'agents' => $this->exportAgentsData($startDate, $endDate),
            'transactions' => $this->exportTransactionsData($startDate, $endDate),
            'remboursements' => $this->exportRemboursementsData($startDate, $endDate),
            'summary' => $this->exportSummaryData($startDate, $endDate),
        };

        return response()->json([
            'data' => $data,
            'meta' => [
                'type' => $validated['type'],
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get period dates from request
     */
    private function getPeriodDates(string $period, array $validated): array
    {
        return match ($period) {
            'today' => [Carbon::today(), Carbon::today()],
            'week' => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            'year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
            'custom' => [Carbon::parse($validated['start_date']), Carbon::parse($validated['end_date'])],
            default => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };
    }

    private function exportAgentsData(Carbon $startDate, Carbon $endDate): array
    {
        return Agent::with('solde')
            ->withCount(['transactions' => fn ($q) => $q->whereBetween('transaction_date', [$startDate, $endDate])])
            ->withSum(['transactions' => fn ($q) => $q->whereBetween('transaction_date', [$startDate, $endDate])], 'amount')
            ->get()
            ->map(fn ($a) => [
                'Nom' => $a->name,
                'Téléphone' => $a->phone,
                'Type' => $a->type,
                'Statut' => $a->status,
                'Transactions' => $a->transactions_count,
                'Montant Total' => $a->transactions_sum_amount ?? 0,
                'Dette Actuelle' => $a->solde?->solde_restant ?? 0,
            ])
            ->toArray();
    }

    private function exportTransactionsData(Carbon $startDate, Carbon $endDate): array
    {
        return UvTransaction::with('agent:id,name')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->get()
            ->map(fn ($t) => [
                'Référence' => $t->reference,
                'Agent' => $t->agent?->name,
                'Montant' => $t->amount,
                'Type' => $t->type,
                'Date' => $t->transaction_date,
            ])
            ->toArray();
    }

    private function exportRemboursementsData(Carbon $startDate, Carbon $endDate): array
    {
        return Remboursement::with('agent:id,name')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->get()
            ->map(fn ($r) => [
                'Référence' => $r->reference,
                'Agent' => $r->agent?->name,
                'Montant' => $r->amount,
                'Méthode' => $r->payment_method,
                'Statut' => $r->status,
                'Date' => $r->payment_date,
            ])
            ->toArray();
    }

    private function exportSummaryData(Carbon $startDate, Carbon $endDate): array
    {
        $transactions = UvTransaction::whereBetween('transaction_date', [$startDate, $endDate]);
        $remboursements = Remboursement::whereBetween('payment_date', [$startDate, $endDate]);

        return [
            ['Métrique' => 'Période', 'Valeur' => "{$startDate->toDateString()} - {$endDate->toDateString()}"],
            ['Métrique' => 'Total Transactions', 'Valeur' => $transactions->count()],
            ['Métrique' => 'CA Total', 'Valeur' => $transactions->sum('amount')],
            ['Métrique' => 'Total Remboursements', 'Valeur' => $remboursements->count()],
            ['Métrique' => 'Montant Remboursé', 'Valeur' => $remboursements->sum('amount')],
        ];
    }
}
