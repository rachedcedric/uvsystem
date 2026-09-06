<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Require2FA
{
    /**
     * Handle an incoming request.
     * Redirect to 2FA challenge if user has 2FA enabled but hasn't verified this session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Check if user has 2FA enabled
        if ($user->two_factor_enabled && $user->two_factor_secret) {
            // Check if already verified in this session
            if (! session()->has('2fa_verified')) {
                // Store intended URL
                session()->put('2fa_intended_url', $request->url());

                return redirect()->route('2fa.challenge');
            }
        }

        return $next($request);
    }
}
