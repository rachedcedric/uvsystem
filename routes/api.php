<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\JekoWebhookController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RemboursementController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UvTransactionController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - UVMASTER
|--------------------------------------------------------------------------
|
| Routes for mobile application and external integrations.
| All routes are prefixed with /api
|
| Rate Limiting:
| - auth: 5 requests/minute (brute force protection)
| - api: 60 requests/minute (global)
| - transactions: 30 requests/minute (financial operations)
| - dashboard: 20 requests/minute (cached data)
| - heavy: 10 requests/minute (exports, reports)
|
*/

// ============================================
// Public Routes (No Authentication Required)
// ============================================

// Authentication with rate limiting (brute force protection)
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// API Health Check (no rate limit needed)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// ============================================
// Protected Routes (Authentication Required)
// ============================================

Route::middleware(['auth:sanctum', 'staff', 'can:access_api', 'throttle:api'])->group(function () {

    // Authentication (standard API rate limit)
    Route::prefix('auth')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

    // Dashboard & Statistics (cached, lower rate limit)
    Route::prefix('dashboard')->middleware(['throttle:dashboard', 'can:view_dashboard'])->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/realtime', [DashboardController::class, 'realtime']);
        Route::get('/charts', [DashboardController::class, 'charts']);
        Route::get('/top-agents', [DashboardController::class, 'topAgents']);
        Route::get('/alerts', [DashboardController::class, 'alerts']);
    });

    // Agents (standard API rate limit for reads)
    Route::apiResource('agents', AgentController::class)->only(['index', 'show']);

    // Agent write operations (transaction rate limit)
    Route::apiResource('agents', AgentController::class)
        ->only(['store', 'update', 'destroy'])
        ->middleware('throttle:transactions');

    Route::prefix('agents/{agent}')->group(function () {
        Route::get('/situation', [AgentController::class, 'situation']);
        Route::get('/transactions', [AgentController::class, 'transactions']);
        Route::get('/remboursements', [AgentController::class, 'remboursements']);
    });

    // UV Transactions (stricter rate limit for financial operations)
    Route::middleware('throttle:transactions')->group(function () {
        // Le paramètre doit s'appeler comme l'argument du contrôleur ($uvTransaction), sinon aucune liaison au
        // modèle n'a lieu : la route répondait 200 pour n'importe quel identifiant.
        Route::apiResource('transactions', UvTransactionController::class)->parameters(['transactions' => 'uvTransaction'])->except(['destroy']);
    });

    // Transaction stats (standard rate)
    Route::prefix('transactions')->group(function () {
        Route::get('/stats/daily', [UvTransactionController::class, 'dailyStats']);
        Route::get('/stats/monthly', [UvTransactionController::class, 'monthlyStats']);
    });

    // Remboursements (stricter rate limit for financial operations)
    Route::middleware('throttle:transactions')->group(function () {
        Route::apiResource('remboursements', RemboursementController::class)->except(['destroy']);
    });

    Route::get('/remboursements-stats', [RemboursementController::class, 'stats']);

    // Fournisseurs (Suppliers)
    Route::get('/fournisseurs/list', [FournisseurController::class, 'list']); // Lightweight list for dropdowns
    Route::apiResource('fournisseurs', FournisseurController::class);
    Route::prefix('fournisseurs/{fournisseur}')->group(function () {
        Route::get('/transactions', [FournisseurController::class, 'transactions']);
        Route::get('/stats', [FournisseurController::class, 'stats']);
    });

    // Reports (heavy operations, stricter rate limit)
    Route::prefix('reports')->middleware(['throttle:heavy', 'can:view_reports'])->group(function () {
        Route::get('/', [ReportController::class, 'index']);
        Route::get('/agents', [ReportController::class, 'agents']);
        Route::get('/transactions', [ReportController::class, 'transactions']);
        Route::get('/export', [ReportController::class, 'export']);
    });

    // Activity Log (Audit Trail)
    Route::prefix('activities')->middleware('can:view_activity_log')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index']);
        Route::get('/stats', [ActivityLogController::class, 'stats']);
        Route::get('/log-names', [ActivityLogController::class, 'logNames']);
        Route::get('/events', [ActivityLogController::class, 'events']);
        Route::get('/{id}', [ActivityLogController::class, 'show']);
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::post('/clear-read', [NotificationController::class, 'clearRead']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });

    // Two-Factor Authentication (2FA)
    Route::prefix('2fa')->group(function () {
        Route::get('/status', [TwoFactorController::class, 'status']);
        Route::post('/setup', [TwoFactorController::class, 'setup']);
        Route::post('/enable', [TwoFactorController::class, 'enable']);
        Route::post('/disable', [TwoFactorController::class, 'disable']);
        Route::post('/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::post('/regenerate-recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::post('/verify', [TwoFactorController::class, 'verify']);
    });
});

/*
|--------------------------------------------------------------------------
| Webhook Routes (No Authentication Required)
|--------------------------------------------------------------------------
|
| These routes are used by external services (Resend)
| to send event notifications. They are excluded from CSRF protection
| and use signature verification for security.
|
*/

Route::post('/webhooks/resend', [WebhookController::class, 'resend'])
    ->name('webhooks.resend');

Route::post('/webhooks/jeko', JekoWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.jeko');
