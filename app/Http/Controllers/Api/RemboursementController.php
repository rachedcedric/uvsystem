<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Services\RemboursementService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RemboursementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected RemboursementService $remboursementService
    ) {}

    /**
     * List all remboursements with pagination.
     *
     * period=today + agent_id réutilise Remboursement::scopeAgentDuJour()
     * (même source de vérité que la page /admin/remboursements/create) et
     * ajoute un résumé (total_amount / total_transactions) à la réponse.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Remboursement::class);

        $isTodayForAgent = $request->get('period') === 'today' && $request->filled('agent_id');

        if ($isTodayForAgent) {
            $agent = Agent::find($request->integer('agent_id'));

            if (! $agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent introuvable.',
                ], 404);
            }

            $query = Remboursement::query()->agentDuJour($agent->id)->with('agent');
        } else {
            $query = Remboursement::query()->with('agent');

            // Filters
            if ($request->has('agent_id')) {
                $query->where('agent_id', $request->agent_id);
            }

            if ($request->has('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Date filters
            if ($request->has('from')) {
                $query->whereDate('payment_date', '>=', $request->from);
            }
            if ($request->has('to')) {
                $query->whereDate('payment_date', '<=', $request->to);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'payment_date');
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
            $summary = Remboursement::query()->agentDuJour($agent->id)->where('status', '!=', 'cancelled');

            $response['agent_id'] = $agent->id;
            $response['total_transactions'] = (clone $summary)->count();
            $response['total_amount'] = (float) (clone $summary)->sum('amount');
        }

        return response()->json($response);
    }

    /**
     * Get a specific remboursement.
     */
    public function show(Remboursement $remboursement): JsonResponse
    {
        $this->authorize('view', $remboursement);

        $remboursement->load('agent');

        return response()->json([
            'success' => true,
            'data' => $remboursement,
        ]);
    }

    /**
     * Create a new remboursement.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Remboursement::class);

        $validated = $request->validate([
            'agent_id' => ['required', Rule::exists('agents', 'id')->where('tenant_id', TenantContext::id())],
            'montant' => 'required|numeric|min:0.01|max:999999999',
            'mode_paiement' => 'required|in:cash,virement,cheque,mobile_money',
            'reference_paiement' => 'nullable|string|max:100',
            'date_remboursement' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:1000',
        ]);

        $agent = Agent::findOrFail($validated['agent_id']);

        // Validate remboursement amount
        $validation = $this->remboursementService->validateRemboursement($agent, $validated['montant']);

        if (! $validation['valid']) {
            return response()->json([
                'success' => false,
                'message' => $validation['message'],
                'data' => [
                    'current_debt' => $agent->current_debt,
                    'montant_demande' => $validated['montant'],
                ],
            ], 422);
        }

        try {
            $remboursement = DB::transaction(function () use ($validated) {
                return Remboursement::create([
                    'agent_id' => $validated['agent_id'],
                    'montant' => $validated['montant'],
                    'mode_paiement' => $validated['mode_paiement'],
                    'reference_paiement' => $validated['reference_paiement'] ?? null,
                    'date_remboursement' => $validated['date_remboursement'] ?? now(),
                    'status' => 'completed',
                    'notes' => $validated['notes'] ?? null,
                ]);
            });

            $remboursement->load('agent');

            return response()->json([
                'success' => true,
                'message' => 'Remboursement enregistré avec succès',
                'data' => $remboursement,
                'agent_debt' => [
                    'previous_debt' => $agent->current_debt,
                    'new_debt' => $remboursement->agent->fresh()->current_debt,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du remboursement',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur',
            ], 500);
        }
    }

    /**
     * Update remboursement status.
     */
    public function update(Request $request, Remboursement $remboursement): JsonResponse
    {
        $this->authorize('update', $remboursement);

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,completed,cancelled',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Prevent status change if already completed (data integrity)
        if ($remboursement->status === 'completed' && isset($validated['status']) && $validated['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier le statut d\'un remboursement validé',
            ], 422);
        }

        $remboursement->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Remboursement mis à jour',
            'data' => $remboursement->fresh('agent'),
        ]);
    }

    /**
     * Get remboursement statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Remboursement::class);

        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());

        $stats = Remboursement::whereBetween('date_remboursement', [$from, $to])
            ->where('status', 'completed')
            ->selectRaw('
                COUNT(*) as total_remboursements,
                SUM(montant) as total_montant,
                AVG(montant) as moyenne_montant,
                COUNT(DISTINCT agent_id) as agents_ayant_rembourse
            ')
            ->first();

        $byPaymentMode = Remboursement::whereBetween('date_remboursement', [$from, $to])
            ->where('status', 'completed')
            ->selectRaw('mode_paiement, COUNT(*) as count, SUM(montant) as total')
            ->groupBy('mode_paiement')
            ->get();

        $topPayers = Remboursement::whereBetween('date_remboursement', [$from, $to])
            ->where('status', 'completed')
            ->selectRaw('agent_id, SUM(montant) as total')
            ->groupBy('agent_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('agent:id,nom,prenom')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'stats' => $stats,
                'by_payment_mode' => $byPaymentMode,
                'top_payers' => $topPayers,
            ],
        ]);
    }
}
