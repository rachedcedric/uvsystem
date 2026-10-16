<?php

namespace App\Http\Controllers;

use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Appareils connectés, tokens API et changement de mot de passe du compte connecté.
 * Chaque action est limitée au compte de l'utilisateur authentifié (jamais d'identifiant de compte
 * fourni par le client) ; les actions sensibles redemandent le mot de passe.
 */
class AccountSecurityController extends Controller
{
    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $current = $request->session()->getId();

        $sessions = $this->usesDatabaseSessions()
            ? DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->orderByDesc('last_activity')->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'current' => $row->id === $current,
                    'device' => $this->describe((string) $row->user_agent),
                    'ip' => $row->ip_address,
                    'last_activity' => Carbon::createFromTimestamp($row->last_activity),
                ])
            : collect();

        return view('auth.account-security', [
            'sessions' => $sessions,
            'sessionsListable' => $this->usesDatabaseSessions(),
            'tokens' => $user->tokens()->orderByDesc('last_used_at')->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']),
            'twoFactor' => $user->hasTwoFactorEnabled(),
        ]);
    }

    /** Ferme UNE session du compte. L'appartenance est vérifiée côté serveur (anti-IDOR). */
    public function revokeSession(Request $request)
    {
        $request->validate(['session_id' => 'required|string|max:255']);

        abort_unless($this->usesDatabaseSessions(), 404);

        $closed = DB::table(config('session.table', 'sessions'))
            ->where('id', $request->session_id)
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        // Supprimer la ligne ne suffit pas : un appareil connecté avec « Rester connecté » se reconnecte seul grâce
        // au cookie « se souvenir de moi ». Le jeton est unique par compte : on le fait tourner (les cookies déjà émis
        // cessent de valoir, les sessions encore ouvertes continuent jusqu'à leur expiration).
        if ($closed > 0) {
            $request->user()->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        }

        return back()->with('status', 'Appareil déconnecté.');
    }

    /** Déconnecte tous les autres appareils et révoque les tokens API. */
    public function revokeOthers(Request $request)
    {
        $this->confirmPassword($request);

        $request->user()->revokeAllAccess($request->session()->getId());

        return back()->with('status', 'Tous les autres appareils et tokens ont été déconnectés.');
    }

    public function revokeToken(Request $request, int $token)
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('status', 'Token révoqué.');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols(), 'different:current_password'],
        ]);

        $this->confirmPassword($request, 'current_password');

        $user = $request->user();
        $user->forceFill(['password' => $request->password])->save();
        $user->revokeAllAccess($request->session()->getId());

        TenantContext::runAs($user->tenant_id, fn () => activity()->causedBy($user)->performedOn($user)->log('Mot de passe modifié'));

        return back()->with('status', 'Mot de passe modifié ; vos autres appareils ont été déconnectés.');
    }

    private function confirmPassword(Request $request, string $field = 'password'): void
    {
        $request->validate([$field => 'required|string']);

        if (! Hash::check($request->input($field), $request->user()->password)) {
            throw ValidationException::withMessages([$field => 'Mot de passe incorrect.']);
        }
    }

    private function describe(string $userAgent): string
    {
        $os = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Appareil inconnu',
        };
        $app = match (true) {
            str_contains($userAgent, '; wv)') => 'Application '.config('branding.name'),
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };

        return "{$app} · {$os}";
    }
}
