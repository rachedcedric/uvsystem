<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login and get token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'nullable|string|max:255',
            'otp' => 'nullable|string|max:20',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Fail closed : mot de passe faux, compte inconnu OU compte sans rôle métier => même message.
        if (! $user
            || ! Hash::check($validated['password'], $user->password)
            || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            throw ValidationException::withMessages([
                'email' => ['Les informations d\'identification fournies sont incorrectes.'],
            ]);
        }

        // 2FA : un compte qui l'a activée ne reçoit de token qu'avec un code valide (ou un code de récupération).
        if ($user->hasTwoFactorEnabled()) {
            $otp = (string) ($validated['otp'] ?? '');
            $service = app(\App\Services\TwoFactorAuthService::class);

            if ($otp === '') {
                return response()->json(['success' => false, 'message' => 'Code de double authentification requis.', 'otp_required' => true], 422);
            }

            $rateKey = '2fa-api|'.$user->getKey().'|'.$request->ip();
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, 5)) {
                throw ValidationException::withMessages(['otp' => ['Trop d\'essais. Réessayez plus tard.']]);
            }

            if (! $service->validateOtp($user, $otp) && ! $service->verifyRecoveryCode($user, $otp)) {
                \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 300);

                throw ValidationException::withMessages(['otp' => ['Code de double authentification invalide.']]);
            }

            \Illuminate\Support\Facades\RateLimiter::clear($rateKey);
        }

        // Utiliser device_name fourni ou générer un nom par défaut
        $deviceName = $validated['device_name'] ?? $request->userAgent() ?? 'web-browser';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'data' => [
                'user' => $this->present($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->present($request->user()),
        ]);
    }

    /**
     * Logout (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie',
        ]);
    }

    /**
     * Logout from all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnecté de tous les appareils',
        ]);
    }

    /**
     * Refresh token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_name' => 'required|string|max:255',
        ]);

        $user = $request->user();

        // Revoke current token
        $user->currentAccessToken()->delete();

        // Create new token
        $token = $user->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Token renouvelé',
            'data' => [
                'token' => $token,
            ],
        ]);
    }

    /**
     * Représentation publique d'un utilisateur : jamais le modèle brut.
     *
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }
}
