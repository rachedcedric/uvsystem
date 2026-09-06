<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorAuthService $twoFactorService
    ) {}

    /**
     * Get 2FA status for the authenticated user.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $user->two_factor_enabled ?? false,
                'confirmed_at' => $user->two_factor_confirmed_at,
                'recovery_codes_count' => count($user->two_factor_recovery_codes ?? []),
            ],
        ]);
    }

    /**
     * Generate a new 2FA secret and QR code.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if 2FA is already enabled
        if ($this->twoFactorService->isEnabled($user)) {
            return response()->json([
                'success' => false,
                'message' => 'L\'authentification à deux facteurs est déjà activée.',
            ], 400);
        }

        // Generate new secret
        $secret = $this->twoFactorService->generateSecretKey();

        // Store temporarily in session or cache
        cache()->put("2fa_setup_{$user->id}", $secret, now()->addMinutes(10));

        // Generate QR code
        $qrCode = $this->twoFactorService->generateQrCodeSvg($user, $secret);

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $secret,
                'qr_code' => $qrCode,
            ],
        ]);
    }

    /**
     * Enable 2FA by confirming with a code.
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // Verify password
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Le mot de passe est incorrect.'],
            ]);
        }

        // Get the temporary secret
        $secret = cache()->get("2fa_setup_{$user->id}");

        if (! $secret) {
            return response()->json([
                'success' => false,
                'message' => 'Session de configuration expirée. Veuillez recommencer.',
            ], 400);
        }

        // Enable 2FA
        if (! $this->twoFactorService->enable($user, $secret, $request->code)) {
            throw ValidationException::withMessages([
                'code' => ['Le code de vérification est invalide.'],
            ]);
        }

        // Clear the temporary secret
        cache()->forget("2fa_setup_{$user->id}");

        // Return recovery codes
        return response()->json([
            'success' => true,
            'message' => 'Authentification à deux facteurs activée avec succès.',
            'data' => [
                'recovery_codes' => $user->fresh()->two_factor_recovery_codes,
            ],
        ]);
    }

    /**
     * Disable 2FA.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // Verify password
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Le mot de passe est incorrect.'],
            ]);
        }

        // Check if 2FA is enabled
        if (! $this->twoFactorService->isEnabled($user)) {
            return response()->json([
                'success' => false,
                'message' => 'L\'authentification à deux facteurs n\'est pas activée.',
            ], 400);
        }

        $this->twoFactorService->disable($user);

        return response()->json([
            'success' => true,
            'message' => 'Authentification à deux facteurs désactivée.',
        ]);
    }

    /**
     * Get recovery codes.
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // Verify password
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Le mot de passe est incorrect.'],
            ]);
        }

        if (! $this->twoFactorService->isEnabled($user)) {
            return response()->json([
                'success' => false,
                'message' => 'L\'authentification à deux facteurs n\'est pas activée.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'recovery_codes' => $user->two_factor_recovery_codes ?? [],
            ],
        ]);
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // Verify password
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Le mot de passe est incorrect.'],
            ]);
        }

        if (! $this->twoFactorService->isEnabled($user)) {
            return response()->json([
                'success' => false,
                'message' => 'L\'authentification à deux facteurs n\'est pas activée.',
            ], 400);
        }

        $codes = $this->twoFactorService->regenerateRecoveryCodes($user);

        return response()->json([
            'success' => true,
            'message' => 'Codes de récupération régénérés.',
            'data' => [
                'recovery_codes' => $codes,
            ],
        ]);
    }

    /**
     * Verify 2FA code during login.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();

        if (! $this->twoFactorService->isEnabled($user)) {
            return response()->json([
                'success' => true,
                'message' => '2FA non activé, accès autorisé.',
            ]);
        }

        // Try OTP code first (6 digits)
        if (strlen($request->code) === 6) {
            if ($this->twoFactorService->validateOtp($user, $request->code)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Code vérifié avec succès.',
                ]);
            }
        }

        // Try recovery code
        if ($this->twoFactorService->verifyRecoveryCode($user, $request->code)) {
            return response()->json([
                'success' => true,
                'message' => 'Code de récupération utilisé.',
                'data' => [
                    'recovery_code_used' => true,
                    'remaining_codes' => count($user->fresh()->two_factor_recovery_codes ?? []),
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'code' => ['Le code est invalide.'],
        ]);
    }
}
