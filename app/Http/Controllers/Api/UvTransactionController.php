<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\UvTransaction;
use App\Services\UvDistributionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UvTransactionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected UvDistributionService $distributionService
    ) {}

    /**
     * List all UV transactions with pagination.
     *
     * period=today + agent_id + type=distribution reuses
     * UvTransaction::scopeVentesAgentDuJour() (même source de vérité que la
     * page /admin/uv-transactions/create) et ajoute un résumé
     * (total_transactions / total_amount) à la réponse.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UvTransaction::class);

        $isTodayForAgent = $request->get('period') === 'today'
            && $request->filled('agent_id')
            && $request->get('type', 'distribution') === 'distribution';

        if ($isTodayForAgent) {
            $agent = Agent::find($request->integer('agent_id'));

            if (! $agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent introuvable.',
                ], 404);
            }

            $query = UvTransaction::query()
                ->ventesAgentDuJour($agent->id)
                ->with(['agent', 'fournisseur']);
        } else {
            $query = UvTransaction::query()->with(['agent', 'fournisseur']);

            // Filters
            if ($request->has('agent_id')) {
                $query->where('agent_id', $request->agent_id);
            }

            if ($request->has('fournisseur_id')) {
                $query->where('fournisseur_id', $request->fournisseur_id);
            }

            if ($request->has('type')) {
                $query->where('transaction_type', $request->type);
            }

            // Date filters
            if ($request->has('from')) {
                $query->whereDate('transaction_date', '>=', $request->from);
            }
            if ($request->has('to')) {
                $query->whereDate('transaction_date', '<=', $request->to);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'transaction_date');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);
        }

        // Pagination
        $perPage = min($request->get('per_page', $isTodayForAgent ? 10 : 15), 100);
        $paginated = $query->paginate($perPage);

        $response = [
            'success' => true,
            'data' => $paginated,
        ];

        if ($isTodayForAgent) {
            $summary = UvTransaction::query()
                ->ventesAgentDuJour($agent->id)
                ->where('status', '!=', 'cancelled');

            $response['agent_id'] = $agent->id;
            $response['total_transactions'] = (clone $summary)->count();
            $response['total_amount'] = (float) (clone $summary)->sum('amount');
        }

        return response()->json($response);
    }

    /**
     * Get a specific transaction.
     */
    public function show(UvTransaction $uvTransaction): JsonResponse
    {
        $this->authorize('view', $uvTransaction);

        $uvTransaction->load(['agent', 'fournisseur']);

        return response()->json([
            'success' => true,
            'data' => $uvTransaction,
        ]);
    }

    /**
     * Create a new UV distribution.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', UvTransaction::class);

        $validated = $request->validate([
            'agent_id' => ['required', Rule::exists('agents', 'id')->where('tenant_id', TenantContext::id())],
            'fournisseur_id' => ['nullable', Rule::exists('fournisseurs', 'id')->where('tenant_id', TenantContext::id())],
            'amount' => 'required|numeric|min:1',
            'date_transaction' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:1000',
        ]);

        $agent = Agent::findOrFail($validated['agent_id']);
        $montantTotal = $validated['amount'];

        // Validate debt ceiling
        $validation = $this->distributionService->validateDistribution($agent, $montantTotal);

        if (! $validation['valid']) {
            return response()->json([
                'success' => false,
                'message' => $validation['message'],
                'data' => [
                    'current_debt' => $agent->current_debt,
                    'plafond_dette' => $agent->debt_limit,
                    'montant_demande' => $montantTotal,
                    'available_credit' => $agent->available_credit,
                ],
            ], 422);
        }

        try {
            $fournisseur = isset($validated['fournisseur_id'])
                ? Fournisseur::find($validated['fournisseur_id'])
                : null;

            // Passe par le point d'entrée unique et sécurisé : vérifie ET
            // débite le Solde UV Disponible de façon atomique (verrouillée),
            // en plus du plafond de dette déjà vérifié ci-dessus.
            $transaction = $this->distributionService->distribute(
                $agent,
                $montantTotal,
                $fournisseur,
                $validated['date_transaction'] ?? null,
                $validated['notes'] ?? null,
            );

            $transaction->load(['agent', 'fournisseur']);

            return response()->json([
                'success' => true,
                'message' => 'Distribution UV créée avec succès',
                'data' => $transaction,
                'agent_debt' => [
                    'current_debt' => $transaction->agent->fresh()->current_debt,
                    'debt_ratio' => $transaction->agent->fresh()->debt_ratio,
                ],
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->implode(' '),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la transaction',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur',
            ], 500);
        }
    }

    /**
     * Update a transaction (limited fields).
     */
    public function update(Request $request, UvTransaction $uvTransaction): JsonResponse
    {
        $this->authorize('update', $uvTransaction);

        // Only notes can be updated for data integrity
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $uvTransaction->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transaction mise à jour',
            'data' => $uvTransaction->fresh(['agent', 'fournisseur']),
        ]);
    }

    /**
     * Get daily statistics.
     */
    public function dailyStats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UvTransaction::class);

        $date = $request->get('date', now()->toDateString());

        $stats = UvTransaction::whereDate('transaction_date', $date)
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(amount) as total_montant,
                COUNT(DISTINCT agent_id) as agents_actifs
            ')
            ->first();

        $topAgents = UvTransaction::whereDate('transaction_date', $date)
            ->selectRaw('agent_id, SUM(amount) as total')
            ->groupBy('agent_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('agent:id,nom,prenom')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'stats' => $stats,
                'top_agents' => $topAgents,
            ],
        ]);
    }

    /**
     * Get monthly statistics.
     */
    public function monthlyStats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UvTransaction::class);

        $year = $request->get('year', now()->year);
        $month = $request->get('month', now()->month);

        $stats = UvTransaction::whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(amount) as total_montant,
                COUNT(DISTINCT agent_id) as agents_actifs,
                AVG(amount) as moyenne_transaction
            ')
            ->first();

        $dailyBreakdown = UvTransaction::whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->selectRaw('DATE(transaction_date) as date, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'month' => $month,
                'stats' => $stats,
                'daily_breakdown' => $dailyBreakdown,
            ],
        ]);
    }
}
