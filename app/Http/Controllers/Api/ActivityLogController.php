<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Controller for Activity Log (Audit Trail)
 *
 * Uses Spatie Laravel Activitylog for tracking all critical actions
 */
class ActivityLogController extends Controller
{
    /**
     * Get paginated list of activities
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'log_name' => ['sometimes', 'string'],
            'subject_type' => ['sometimes', 'string'],
            'subject_id' => ['sometimes', 'integer'],
            'causer_id' => ['sometimes', 'integer'],
            'event' => ['sometimes', 'string'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ]);

        $query = Activity::query()
            ->with(['causer:id,name,email', 'subject'])
            ->orderByDesc('created_at');

        // Filters
        if (isset($validated['log_name'])) {
            $query->where('log_name', $validated['log_name']);
        }

        if (isset($validated['subject_type'])) {
            $modelClass = match ($validated['subject_type']) {
                'agent' => 'App\\Models\\Agent',
                'transaction' => 'App\\Models\\UvTransaction',
                'remboursement' => 'App\\Models\\Remboursement',
                'fournisseur' => 'App\\Models\\Fournisseur',
                'user' => 'App\\Models\\User',
                default => $validated['subject_type'],
            };
            $query->where('subject_type', $modelClass);
        }

        if (isset($validated['subject_id'])) {
            $query->where('subject_id', $validated['subject_id']);
        }

        if (isset($validated['causer_id'])) {
            $query->where('causer_id', $validated['causer_id']);
        }

        if (isset($validated['event'])) {
            $query->where('event', $validated['event']);
        }

        if (isset($validated['start_date'])) {
            $query->whereDate('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->whereDate('created_at', '<=', $validated['end_date']);
        }

        $perPage = $validated['per_page'] ?? 25;
        $activities = $query->paginate($perPage);

        return response()->json([
            'data' => $activities->items(),
            'current_page' => $activities->currentPage(),
            'last_page' => $activities->lastPage(),
            'per_page' => $activities->perPage(),
            'total' => $activities->total(),
        ]);
    }

    /**
     * Get a single activity detail
     */
    public function show(int $id): JsonResponse
    {
        $activity = Activity::with(['causer:id,name,email', 'subject'])
            ->findOrFail($id);

        return response()->json([
            'data' => $activity,
        ]);
    }

    /**
     * Get activity statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ]);

        $query = Activity::query();

        if (isset($validated['start_date'])) {
            $query->whereDate('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->whereDate('created_at', '<=', $validated['end_date']);
        }

        $stats = [
            'total' => $query->count(),
            'by_event' => Activity::query()
                ->when(isset($validated['start_date']), fn ($q) => $q->whereDate('created_at', '>=', $validated['start_date']))
                ->when(isset($validated['end_date']), fn ($q) => $q->whereDate('created_at', '<=', $validated['end_date']))
                ->selectRaw('event, COUNT(*) as count')
                ->groupBy('event')
                ->pluck('count', 'event'),
            'by_subject' => Activity::query()
                ->when(isset($validated['start_date']), fn ($q) => $q->whereDate('created_at', '>=', $validated['start_date']))
                ->when(isset($validated['end_date']), fn ($q) => $q->whereDate('created_at', '<=', $validated['end_date']))
                ->selectRaw('subject_type, COUNT(*) as count')
                ->groupBy('subject_type')
                ->get()
                ->mapWithKeys(fn ($item) => [
                    class_basename($item->subject_type) => $item->count,
                ]),
            'by_causer' => Activity::query()
                ->when(isset($validated['start_date']), fn ($q) => $q->whereDate('created_at', '>=', $validated['start_date']))
                ->when(isset($validated['end_date']), fn ($q) => $q->whereDate('created_at', '<=', $validated['end_date']))
                ->join('users', 'activity_log.causer_id', '=', 'users.id')
                ->selectRaw('users.name, COUNT(*) as count')
                ->groupBy('users.name')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'name'),
            'recent' => Activity::with(['causer:id,name'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'description' => $a->description,
                    'event' => $a->event,
                    'causer' => $a->causer?->name,
                    'created_at' => $a->created_at,
                ]),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get available log names (for filtering)
     */
    public function logNames(): JsonResponse
    {
        $logNames = Activity::distinct()
            ->pluck('log_name')
            ->filter()
            ->values();

        return response()->json([
            'data' => $logNames,
        ]);
    }

    /**
     * Get available events (for filtering)
     */
    public function events(): JsonResponse
    {
        $events = Activity::distinct()
            ->pluck('event')
            ->filter()
            ->values();

        return response()->json([
            'data' => $events,
        ]);
    }
}
