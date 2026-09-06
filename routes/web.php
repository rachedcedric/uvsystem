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
