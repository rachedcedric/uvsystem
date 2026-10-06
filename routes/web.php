<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\ChargeJustificatifController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\RemboursementReceiptController;
use App\Http\Controllers\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 2FA Challenge Routes (accessible after login but before panel access)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/2fa/challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('2fa.challenge');

    Route::post('/2fa/verify', [TwoFactorChallengeController::class, 'verify'])
        ->name('2fa.verify');

    Route::post('/2fa/verify-recovery', [TwoFactorChallengeController::class, 'verifyRecoveryCode'])
        ->name('2fa.verify.recovery');
});

// Mot de passe oublié (invités) : 5 demandes par minute
Route::middleware(['web', 'guest', 'throttle:5,1'])->group(function () {
    Route::get('/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'sendLink'])->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\PasswordResetController::class, 'update'])->name('password.update');
});

// Appareils connectés et mot de passe du compte
Route::middleware(['web', 'auth'])->prefix('security')->group(function () {
    Route::get('/sessions', [\App\Http\Controllers\AccountSecurityController::class, 'show'])->name('security.sessions');
    Route::post('/sessions/revoke', [\App\Http\Controllers\AccountSecurityController::class, 'revokeSession'])->name('security.sessions.revoke');
    Route::post('/sessions/revoke-others', [\App\Http\Controllers\AccountSecurityController::class, 'revokeOthers'])->middleware('throttle:5,1')->name('security.sessions.revoke-others');
    Route::post('/tokens/{token}/revoke', [\App\Http\Controllers\AccountSecurityController::class, 'revokeToken'])->whereNumber('token')->name('security.tokens.revoke');
    Route::post('/password', [\App\Http\Controllers\AccountSecurityController::class, 'changePassword'])->middleware('throttle:5,1')->name('security.password');
});

// Activation / désactivation de la 2FA (page autonome, accessible même quand la 2FA est obligatoire)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/security/2fa', [\App\Http\Controllers\TwoFactorEnrollmentController::class, 'show'])->name('security.2fa');
    Route::post('/security/2fa/confirm', [\App\Http\Controllers\TwoFactorEnrollmentController::class, 'confirm'])->name('security.2fa.confirm');
    Route::post('/security/2fa/disable', [\App\Http\Controllers\TwoFactorEnrollmentController::class, 'disable'])->name('security.2fa.disable');
});

// Export complet des données du tenant (master) : 3 par 10 minutes, c'est une opération lourde
Route::middleware(['web', 'auth', '2fa', 'staff', 'throttle:3,10'])->get('/exports/mes-donnees', [\App\Http\Controllers\TenantDataExportController::class, 'download'])
    ->name('exports.tenant-data');

// Export Download Route (protected)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/exports/download/{filename}', [ExportDownloadController::class, 'download'])
        ->name('exports.download')
        ->where('filename', '.*\.xlsx');
});

// Backup Download Route (protected)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/backup/download/{path}', [BackupController::class, 'download'])
        ->name('backup.download')
        ->where('path', '.*');
});

// Charge Justificatif Download Route (protected)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/charges/justificatif/{charge}/download', [ChargeJustificatifController::class, 'download'])
        ->name('charges.justificatif.download');
});

// Remboursement Receipt Route (protected)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/remboursement/{remboursement}/receipt', [RemboursementReceiptController::class, 'show'])
        ->name('remboursement.receipt');
    Route::get('/remboursement/{remboursement}/receipt/download', [RemboursementReceiptController::class, 'download'])
        ->name('remboursement.receipt.download');
});
