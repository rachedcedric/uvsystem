<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fournisseur;
use App\Models\UvTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FournisseurController extends Controller
{
    /**
     * Liste paginée des fournisseurs
     */
    public function index(Request $request): JsonResponse
    {
        $query = Fournisseur::query();

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by type
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->input('per_page', 15);
        $fournisseurs = $query->paginate($perPage);

        return response()->json($fournisseurs);
    }

    /**
     * Liste simplifiée pour les dropdowns
     */
    public function list(): JsonResponse
    {
        $fournisseurs = Fournisseur::where('is_active', true)
            ->select('id', 'name', 'type')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $fournisseurs]);
    }

    /**
     * Créer un nouveau fournisseur
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['bank', 'premium_agent', 'other'])],
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $fournisseur = Fournisseur::create($validated);

        activity()
            ->performedOn($fournisseur)
            ->withProperties(['action' => 'created', 'data' => $validated])
            ->log("Fournisseur {$fournisseur->name} créé");

        return response()->json([
            'message' => 'Fournisseur créé avec succès',
            'data' => $fournisseur,
        ], 201);
    }

    /**
     * Afficher un fournisseur
     */
    public function show(Fournisseur $fournisseur): JsonResponse
    {
        return response()->json(['data' => $fournisseur]);
    }

    /**
     * Mettre à jour un fournisseur
     */
    public function update(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => ['sometimes', 'required', Rule::in(['bank', 'premium_agent', 'other'])],
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $oldData = $fournisseur->toArray();
        $fournisseur->update($validated);

        activity()
            ->performedOn($fournisseur)
            ->withProperties([
                'action' => 'updated',
                'old' => $oldData,
                'new' => $validated,
            ])
            ->log("Fournisseur {$fournisseur->name} mis à jour");

        return response()->json([
            'message' => 'Fournisseur mis à jour avec succès',
            'data' => $fournisseur->fresh(),
        ]);
    }

    /**
     * Supprimer un fournisseur (soft delete)
     */
    public function destroy(Fournisseur $fournisseur): JsonResponse
    {
        // Vérifier s'il y a des transactions liées
        $transactionCount = UvTransaction::where('fournisseur_id', $fournisseur->id)->count();

        if ($transactionCount > 0) {
            // Soft delete - désactiver au lieu de supprimer
            $fournisseur->update(['is_active' => false]);

            activity()
                ->performedOn($fournisseur)
                ->withProperties(['action' => 'deactivated', 'transaction_count' => $transactionCount])
                ->log("Fournisseur {$fournisseur->name} désactivé (transactions existantes)");

            return response()->json([
                'message' => 'Fournisseur désactivé (transactions existantes)',
                'deactivated' => true,
            ]);
        }

        $name = $fournisseur->name;
        $fournisseur->delete();

        activity()
            ->withProperties(['action' => 'deleted', 'fournisseur_name' => $name])
            ->log("Fournisseur {$name} supprimé");

        return response()->json([
            'message' => 'Fournisseur supprimé avec succès',
        ]);
    }

    /**
     * Transactions d'un fournisseur
     */
    public function transactions(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $query = UvTransaction::where('fournisseur_id', $fournisseur->id)
            ->with('agent:id,name');

        // Filter by date range
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $request->input('per_page', 20);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Statistiques d'un fournisseur
     */
    public function stats(Fournisseur $fournisseur): JsonResponse
    {
        $transactions = UvTransaction::where('fournisseur_id', $fournisseur->id);

        $stats = [
            'total_transactions' => $transactions->count(),
            'total_amount' => $transactions->sum('amount'),
            'first_transaction' => $transactions->min('created_at'),
            'last_transaction' => $transactions->max('created_at'),
            'unique_agents' => $transactions->distinct('agent_id')->count('agent_id'),
        ];

        // Monthly breakdown (last 6 months)
        $monthlyStats = UvTransaction::where('fournisseur_id', $fournisseur->id)
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as count,
                SUM(amount) as total_amount
            ')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $stats['monthly_breakdown'] = $monthlyStats;

        return response()->json(['data' => $stats]);
    }
}
