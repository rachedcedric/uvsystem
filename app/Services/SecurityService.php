<?php

namespace App\Services;

use App\Models\User;
use App\Models\UvTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

class SecurityService
{
    /**
     * Vérifier les tentatives de connexion échouées
     */
    public function checkLoginAttempts(string $identifier): array
    {
        $key = 'login_attempts:'.$identifier;
        $attempts = Cache::get($key, 0);
        $maxAttempts = config('security.authentication.max_login_attempts', 3);
        $lockoutDuration = config('security.authentication.lockout_duration', 15);

        if ($attempts >= $maxAttempts) {
            $ttl = Cache::get($key.':locked_until');

            return [
                'locked' => true,
                'remaining_time' => $ttl ? Carbon::parse($ttl)->diffInMinutes(now()) : 0,
                'attempts' => $attempts,
            ];
        }

        return [
            'locked' => false,
            'attempts' => $attempts,
            'remaining_attempts' => $maxAttempts - $attempts,
        ];
    }

    /**
     * Enregistrer une tentative de connexion échouée
     */
    public function recordFailedLogin(string $identifier, ?string $ipAddress = null): void
    {
        $key = 'login_attempts:'.$identifier;
        $attempts = Cache::increment($key);
        $maxAttempts = config('security.authentication.max_login_attempts', 3);
        $lockoutDuration = config('security.authentication.lockout_duration', 15);

        // Définir TTL de 30 minutes pour les tentatives
        Cache::put($key, $attempts, now()->addMinutes(30));

        if ($attempts >= $maxAttempts) {
            $lockedUntil = now()->addMinutes($lockoutDuration);
            Cache::put($key.':locked_until', $lockedUntil, $lockedUntil);

            // Logger et notifier
            Log::warning('Account locked due to failed login attempts', [
                'identifier' => $identifier,
                'ip_address' => $ipAddress,
                'attempts' => $attempts,
                'locked_until' => $lockedUntil,
            ]);

            $this->sendSecurityAlert('account_locked', [
                'identifier' => $identifier,
                'ip_address' => $ipAddress,
                'locked_until' => $lockedUntil,
            ]);
        }
    }

    /**
     * Réinitialiser les tentatives de connexion après succès
     */
    public function clearLoginAttempts(string $identifier): void
    {
        Cache::forget('login_attempts:'.$identifier);
        Cache::forget('login_attempts:'.$identifier.':locked_until');
    }

    /**
     * Vérifier si une transaction est suspecte
     */
    public function detectSuspiciousTransaction(UvTransaction $transaction, User $user): array
    {
        $flags = [];
        $riskScore = 0;

        // 1. Vérifier les transactions multiples dans un court laps de temps
        if (config('security.fraud_detection.suspicious_patterns.multiple_transactions_short_time.enabled')) {
            $recentCount = UvTransaction::where('agent_id', $transaction->agent_id)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->count();

            $threshold = config('security.fraud_detection.suspicious_patterns.multiple_transactions_short_time.threshold', 5);

            if ($recentCount >= $threshold) {
                $flags[] = 'multiple_transactions_short_time';
                $riskScore += 30;
            }
        }

        // 2. Vérifier le montant inhabituel
        if (config('security.fraud_detection.suspicious_patterns.unusual_amount.enabled')) {
            $avgAmount = UvTransaction::where('agent_id', $transaction->agent_id)
                ->where('created_at', '>=', now()->subDays(30))
                ->avg('amount');

            if ($avgAmount > 0) {
                $deviationFactor = config('security.fraud_detection.suspicious_patterns.unusual_amount.deviation_factor', 3);
                if ($transaction->amount > ($avgAmount * $deviationFactor)) {
                    $flags[] = 'unusual_amount';
                    $riskScore += 40;
                }
            }
        }

        // 3. Vérifier les heures inhabituelles
        if (config('security.fraud_detection.suspicious_patterns.off_hours_transactions.enabled')) {
            $allowedHours = config('security.fraud_detection.suspicious_patterns.off_hours_transactions.allowed_hours', [6, 22]);
            $currentHour = now()->hour;

            if ($currentHour < $allowedHours[0] || $currentHour > $allowedHours[1]) {
                $flags[] = 'off_hours_transaction';
                $riskScore += 20;
            }
        }

        // 4. Vérifier la limite journalière
        $dailyLimit = config('security.transactions.daily_limits.agent');
        if ($dailyLimit) {
            $dailyTotal = UvTransaction::where('agent_id', $transaction->agent_id)
                ->whereDate('created_at', today())
                ->sum('amount');

            if (($dailyTotal + $transaction->amount) > $dailyLimit) {
                $flags[] = 'daily_limit_exceeded';
                $riskScore += 50;
            }
        }

        return [
            'suspicious' => $riskScore > 50,
            'risk_score' => $riskScore,
            'flags' => $flags,
            'recommended_action' => $this->getRecommendedAction($riskScore),
        ];
    }

    /**
     * Obtenir l'action recommandée en fonction du score de risque
     */
    private function getRecommendedAction(int $riskScore): string
    {
        if ($riskScore >= 80) {
            return 'block_and_alert';
        } elseif ($riskScore >= 50) {
            return 'require_2fa_and_alert';
        } elseif ($riskScore >= 30) {
            return 'flag_for_review';
        }

        return 'allow';
    }

    /**
     * Générer une signature de transaction
     */
    public function generateTransactionSignature(array $data): string
    {
        if (! config('security.transactions.signature_enabled', true)) {
            return '';
        }

        $algorithm = config('security.transactions.signature_algorithm', 'sha256');
        $secret = config('app.key');

        // Trier les données pour assurer la cohérence
        ksort($data);
        $payload = json_encode($data);

        return hash_hmac($algorithm, $payload, $secret);
    }

    /**
     * Vérifier la signature d'une transaction
     */
    public function verifyTransactionSignature(array $data, string $signature): bool
    {
        if (! config('security.transactions.signature_enabled', true)) {
            return true;
        }

        $expectedSignature = $this->generateTransactionSignature($data);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Vérifier l'intégrité des transactions
     */
    public function verifyTransactionIntegrity(): array
    {
        $issues = [];

        // Vérifier les signatures
        $transactions = UvTransaction::whereNotNull('signature')
            ->get();

        foreach ($transactions as $transaction) {
            $data = [
                'agent_id' => $transaction->agent_id,
                'amount' => $transaction->amount,
                'commission_amount' => $transaction->commission_amount ?? 0,
                'created_at' => $transaction->created_at->toIso8601String(),
            ];

            if (! $this->verifyTransactionSignature($data, $transaction->signature)) {
                $issues[] = [
                    'type' => 'invalid_signature',
                    'transaction_id' => $transaction->id,
                    'message' => 'Signature de transaction invalide',
                ];
            }
        }

        // Vérifier les soldes agents
        $agents = DB::table('agents')
            ->join('agent_soldes', 'agents.id', '=', 'agent_soldes.agent_id')
            ->select('agents.id', 'agents.name', 'agent_soldes.*')
            ->get();

        foreach ($agents as $agent) {
            $calculatedTotal = UvTransaction::where('agent_id', $agent->id)
                ->sum('amount');

            if (abs($calculatedTotal - $agent->total_uv_distribues) > 0.01) {
                $issues[] = [
                    'type' => 'solde_mismatch',
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->nom,
                    'expected' => $calculatedTotal,
                    'actual' => $agent->total_uv_distribues,
                    'difference' => $calculatedTotal - $agent->total_uv_distribues,
                ];
            }
        }

        return $issues;
    }

    /**
     * Vérifier si une transaction nécessite une double validation
     */
    public function requiresDualValidation(float $amount): bool
    {
        if (! config('security.transactions.dual_validation.enabled', true)) {
            return false;
        }

        $threshold = config('security.transactions.dual_validation.threshold', 1000000);

        return $amount >= $threshold;
    }

    /**
     * Envoyer une alerte de sécurité
     */
    public function sendSecurityAlert(string $type, array $data): void
    {
        $emails = config('security.audit.notification_emails', []);

        if (empty($emails)) {
            Log::warning('Security alert not sent - no notification emails configured', [
                'type' => $type,
                'data' => $data,
            ]);

            return;
        }

        Log::critical('Security alert: '.$type, $data);

        // Envoyer l'email via ResendService si disponible
        try {
            $resend = app(ResendService::class);

            $subject = '🚨 Alerte Sécurité: '.ucfirst(str_replace('_', ' ', $type));
            $content = $this->formatSecurityAlertContent($type, $data);

            foreach ($emails as $email) {
                $resend->sendNotification($email, $subject, $content, 'high');
            }

            Log::info('Security alert emails sent successfully', ['type' => $type, 'recipients' => $emails]);
        } catch (\Exception $e) {
            Log::error('Failed to send security alert email', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Formater le contenu de l'alerte de sécurité
     */
    private function formatSecurityAlertContent(string $type, array $data): string
    {
        $content = '<h2>🚨 Alerte de Sécurité</h2>';
        $content .= '<p><strong>Type:</strong> '.ucfirst(str_replace('_', ' ', $type)).'</p>';
        $content .= '<p><strong>Date:</strong> '.now()->format('d/m/Y H:i:s').'</p>';
        $content .= '<hr>';
        $content .= '<h3>Détails:</h3>';
        $content .= '<pre>'.json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).'</pre>';
        $content .= '<hr>';
        $content .= '<p><em>Cette alerte a été générée automatiquement par le système de sécurité.</em></p>';

        return $content;
    }

    /**
     * Logger une activité suspecte
     */
    public function logSuspiciousActivity(string $activity, array $context): void
    {
        $user = Auth::check() ? Auth::user() : null;

        activity()
            ->causedBy($user)
            ->withProperties([
                'activity' => $activity,
                'context' => $context,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('suspicious_activity');

        $this->sendSecurityAlert('suspicious_activity', [
            'activity' => $activity,
            'user' => $user?->email ?? 'guest',
            'ip' => request()->ip(),
            'context' => $context,
        ]);
    }

    /**
     * Valider la force d'un mot de passe
     */
    public function validatePasswordStrength(string $password): array
    {
        $errors = [];
        $score = 0;

        $minLength = config('security.password.min_length', 12);
        if (strlen($password) < $minLength) {
            $errors[] = "Le mot de passe doit contenir au moins {$minLength} caractères";
        } else {
            $score += 20;
        }

        if (config('security.password.require_uppercase', true) && ! preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une majuscule';
        } else {
            $score += 20;
        }

        if (config('security.password.require_lowercase', true) && ! preg_match('/[a-z]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une minuscule';
        } else {
            $score += 20;
        }

        if (config('security.password.require_numbers', true) && ! preg_match('/[0-9]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins un chiffre';
        } else {
            $score += 20;
        }

        if (config('security.password.require_special_chars', true)) {
            $specialChars = preg_quote(config('security.password.special_chars', '!@#$%^&*()'), '/');
            if (! preg_match('/['.$specialChars.']/', $password)) {
                $errors[] = 'Le mot de passe doit contenir au moins un caractère spécial';
            } else {
                $score += 20;
            }
        }

        // Vérifier les mots de passe communs
        if (config('security.password.prevent_common_passwords', true)) {
            $commonPasswords = ['password', '123456', 'qwerty', 'admin', 'letmein', 'welcome'];
            if (in_array(strtolower($password), $commonPasswords)) {
                $errors[] = 'Ce mot de passe est trop commun';
                $score = 0;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'score' => $score,
            'strength' => $this->getPasswordStrengthLabel($score),
        ];
    }

    /**
     * Obtenir le label de force du mot de passe
     */
    private function getPasswordStrengthLabel(int $score): string
    {
        if ($score >= 90) {
            return 'Très fort';
        }
        if ($score >= 70) {
            return 'Fort';
        }
        if ($score >= 50) {
            return 'Moyen';
        }
        if ($score >= 30) {
            return 'Faible';
        }

        return 'Très faible';
    }

    /**
     * Chiffrer une donnée sensible
     */
    public function encryptSensitiveData(string $data): string
    {
        if (! config('security.encryption.enabled', true)) {
            return $data;
        }

        return encrypt($data);
    }

    /**
     * Déchiffrer une donnée sensible
     */
    public function decryptSensitiveData(string $encryptedData): string
    {
        if (! config('security.encryption.enabled', true)) {
            return $encryptedData;
        }

        try {
            return decrypt($encryptedData);
        } catch (\Exception $e) {
            Log::error('Decryption failed', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Générer un rapport de sécurité
     */
    public function generateSecurityReport(Carbon $startDate, Carbon $endDate): array
    {
        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'failed_logins' => Activity::where('description', 'failed_login')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'suspicious_activities' => Activity::where('description', 'suspicious_activity')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'blocked_transactions' => Activity::where('description', 'transaction_blocked')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'integrity_checks' => $this->verifyTransactionIntegrity(),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
