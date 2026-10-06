<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentCollection;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all agents with pagination.
     */
    public function index(Request $request): AgentCollection
    {
        $this->authorize('viewAny', Agent::class);

        $query = Agent::query()->with(['solde']);

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('zone')) {
            $query->where('zone', 'like', '%'.$request->zone.'%');
        }

        if ($request->has('with_debt')) {
            $query->withDebt();
        }

        if ($request->has('critical_debt')) {
            $query->criticalDebt();
        }

        // Sorting
        $sortBy = in_array($request->get('sort_by'), ['name', 'created_at', 'current_debt', 'status', 'zone'], true)
            ? $request->get('sort_by')
            : 'created_at';
        $sortDirection = $request->get('sort_direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);

        return new AgentCollection($query->paginate($perPage));
    }

    /**
     * Get a specific agent.
     */
    public function show(Agent $agent): AgentResource
    {
        $this->authorize('view', $agent);

        $agent->load(['solde', 'transactions', 'remboursements']);

        return new AgentResource($agent);
    }

    /**
     * Create a new agent.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Agent::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'regex:/^[0-9]{8,15}$/', Rule::unique('agents', 'phone')->where('tenant_id', TenantContext::id())],
            'email' => ['nullable', 'email', Rule::unique('agents', 'email')->where('tenant_id', TenantContext::id())],
            'address' => 'nullable|string|max:500',
            'zone' => 'nullable|string|max:100',
            'type' => 'sometimes|in:standard,premium,vip',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'debt_limit' => 'nullable|numeric|min:0|max:999999999',
            'notes' => 'nullable|string',
        ]);

        $validated['status'] = 'active';

        $agent = Agent::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Agent créé avec succès',
            'data' => new AgentResource($agent),
        ], 201);
    }

    /**
     * Update an agent.
     */
    public function update(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => ['sometimes', 'required', 'regex:/^[0-9]{8,15}$/', Rule::unique('agents', 'phone')->where('tenant_id', TenantContext::id())->ignore($agent->id)],
            'email' => ['nullable', 'email', Rule::unique('agents', 'email')->where('tenant_id', TenantContext::id())->ignore($agent->id)],
            'address' => 'nullable|string|max:500',
            'zone' => 'nullable|string|max:100',
            'type' => 'sometimes|in:standard,premium,vip',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'debt_limit' => 'nullable|numeric|min:0|max:999999999',
            'status' => 'sometimes|in:active,inactive,suspended',
            'notes' => 'nullable|string',
        ]);

        $agent->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Agent mis à jour avec succès',
            'data' => new AgentResource($agent->fresh()),
        ]);
    }

    /**
     * Soft delete an agent.
     */
    public function destroy(Agent $agent): JsonResponse
    {
        $this->authorize('delete', $agent);

        // Check for pending debts
        if ($agent->current_debt > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un agent avec des dettes en cours',
                'data' => [
                    'current_debt' => $agent->current_debt,
                ],
            ], 422);
        }

        $agent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Agent supprimé avec succès',
        ]);
    }

    /**
     * Get agent's financial situation.
     */
    public function situation(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $agent->load(['solde', 'transactions', 'remboursements']);

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => new AgentResource($agent),
                'financial' => [
                    'total_uv_distribues' => $agent->solde?->total_uv_distribues ?? 0,
                    'total_montant_distribue' => $agent->solde?->total_montant ?? 0,
                    'total_rembourse' => $agent->solde?->total_rembourse ?? 0,
                    'solde_restant' => $agent->solde?->solde_restant ?? 0,
                    'current_debt' => $agent->current_debt,
                    'plafond_dette' => $agent->debt_limit,
                    'debt_ratio' => $agent->debt_ratio,
                    'available_credit' => $agent->available_credit,
                ],
                'stats' => [
                    'total_transactions' => $agent->transactions()->count(),
                    'total_remboursements' => $agent->remboursements()->count(),
                    'last_transaction_at' => $agent->transactions()->latest()->value('created_at'),
                    'last_remboursement_at' => $agent->remboursements()->latest()->value('created_at'),
                ],
            ],
        ]);
    }

    /**
     * Get agent's transactions.
     */
    public function transactions(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);
        $this->authorize('viewAny', UvTransaction::class);

        $query = $agent->transactions()->with('fournisseur');

        // Date filters
        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $perPage = min($request->get('per_page', 15), 100);

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('created_at', 'desc')->paginate($perPage),
        ]);
    }

    /**
     * Get agent's remboursements.
     */
    public function remboursements(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);
        $this->authorize('viewAny', Remboursement::class);

        $query = $agent->remboursements();

        // Date filters
        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $perPage = min($request->get('per_page', 15), 100);

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('created_at', 'desc')->paginate($perPage),
        ]);
    }
}
