<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TriggerDebtReminderCycle
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if (! $user?->hasAnyRole(['master', 'admin'])) {
            return $response;
        }

        try {
            app(NotificationService::class)->checkAndSendDebtRemindersOncePerCycle();
        } catch (\Throwable $exception) {
            Log::error('DebtReminder: échec du déclenchement via le panneau admin', [
                'message' => $exception->getMessage(),
            ]);
        }

        return $response;
    }
}
