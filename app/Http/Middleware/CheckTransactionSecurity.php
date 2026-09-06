<?php

namespace App\Http\Middleware;

use App\Services\SecurityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckTransactionSecurity
{
    protected SecurityService $securityService;

    public function __construct(SecurityService $securityService)
    {
        $this->securityService = $securityService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Vérifier le rate limiting pour les opérations financières
        $this->checkRateLimit($request);

        // Vérifier l'IP whitelisting si activé
        if (config('security.api.ip_whitelist.enabled')) {
            $this->checkIpWhitelist($request);
        }

        // Logger la requête
        $this->logRequest($request);

        return $next($request);
    }

    /**
     * Vérifier le rate limiting
     */
    protected function checkRateLimit(Request $request): void
    {
        $limit = config('security.api.rate_limit.financial_operations', 30);
        $key = 'financial_operations:'.$request->user()?->id ?? $request->ip();

        $attempts = cache()->increment($key);

        if ($attempts === 1) {
            cache()->put($key, 1, now()->addMinute());
        }

        if ($attempts > $limit) {
            Log::warning('Rate limit exceeded for financial operation', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'attempts' => $attempts,
            ]);

            abort(429, 'Trop de requêtes. Veuillez réessayer dans 1 minute.');
        }
    }

    /**
     * Vérifier l'IP whitelisting
     */
    protected function checkIpWhitelist(Request $request): void
    {
        $allowedIps = config('security.api.ip_whitelist.ips', []);
        $clientIp = $request->ip();

        if (! empty($allowedIps) && ! in_array($clientIp, $allowedIps)) {
            $this->securityService->logSuspiciousActivity('unauthorized_ip_access', [
                'ip' => $clientIp,
                'path' => $request->path(),
            ]);

            abort(403, 'Accès refusé depuis cette adresse IP.');
        }
    }

    /**
     * Logger la requête
     */
    protected function logRequest(Request $request): void
    {
        if (config('security.audit.level') === 'strict') {
            activity()
                ->causedBy($request->user())
                ->withProperties([
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log('financial_operation_request');
        }
    }
}
