<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\User;
use App\Notifications\DebtAlertNotification;
use App\Notifications\PaymentReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class TestNotificationsCommand extends Command
{
    protected $signature = 'notifications:test
                            {type=debt : Type de notification (debt, payment, all)}
                            {--email= : Email du destinataire}';

    protected $description = 'Tester l\'envoi de notifications email via Resend';

    public function handle(): int
    {
        $this->info('');
        $this->info('🔔 Test des Notifications Email');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        // Vérifier la configuration Resend
        if (! config('services.resend.key')) {
            $this->error('❌ RESEND_KEY n\'est pas configuré');

            return self::FAILURE;
        }

        $type = $this->argument('type');
        $email = $this->option('email');

        // Demander l'email si non fourni
        if (! $email) {
            $email = $this->ask('📧 Email du destinataire ?', 'contact@uvsystem.pro');
        }

        // Valider l'email
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('❌ Email invalide : '.$email);

            return self::FAILURE;
        }

        // Créer un utilisateur temporaire pour recevoir la notification
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
            ]
        );

        $this->info("✅ Destinataire : {$user->name} ({$email})");
        $this->info('');

        // Récupérer ou créer un agent de test
        $agent = $this->getOrCreateTestAgent();

        if (! $agent) {
            $this->error('❌ Impossible de créer un agent de test');

            return self::FAILURE;
        }

        $this->info("✅ Agent de test : {$agent->name}");
        $this->info('');

        // Envoyer les notifications selon le type
        match ($type) {
            'debt' => $this->testDebtNotification($user, $agent),
            'payment' => $this->testPaymentNotification($user, $agent),
            'all' => $this->testAllNotifications($user, $agent),
            default => $this->error("❌ Type invalide : $type (utilisez: debt, payment, all)"),
        };

        $this->info('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('✅ Tests terminés !');
        $this->info('');
        $this->info('📊 Prochaines étapes :');
        $this->line('   1. Vérifiez votre boîte email : '.$email);
        $this->line('   2. Consultez le dashboard Resend : https://resend.com/emails');
        $this->line('   3. Vérifiez les logs : tail -f storage/logs/laravel.log');
        $this->info('');

        return self::SUCCESS;
    }

    protected function testDebtNotification(User $user, Agent $agent): void
    {
        $this->line('📧 Test 1/3 : Notification d\'alerte de dette...');

        try {
            // Alerte de dette normale (warning)
            $user->notify(new DebtAlertNotification($agent, 'warning'));
            $this->info('   ✅ Alerte de dette (warning) envoyée');

            // Alerte critique
            $user->notify(new DebtAlertNotification($agent, 'critical', 95.5));
            $this->info('   ✅ Alerte de dette (critical) envoyée');

            // Rappel
            $user->notify(new DebtAlertNotification($agent, 'reminder'));
            $this->info('   ✅ Rappel de dette envoyé');

        } catch (\Exception $e) {
            $this->error('   ❌ Erreur : '.$e->getMessage());
        }
    }

    protected function testPaymentNotification(User $user, Agent $agent): void
    {
        $this->line('📧 Test 2/3 : Notification de rappel de paiement...');

        try {
            $user->notify(new PaymentReminderNotification($agent));
            $this->info('   ✅ Rappel de paiement envoyé');
        } catch (\Exception $e) {
            $this->error('   ❌ Erreur : '.$e->getMessage());
        }
    }

    protected function testAllNotifications(User $user, Agent $agent): void
    {
        $this->testDebtNotification($user, $agent);
        $this->info('');
        $this->testPaymentNotification($user, $agent);
    }

    protected function getOrCreateTestAgent(): ?Agent
    {
        // Chercher un agent existant
        $agent = Agent::first();

        if ($agent) {
            return $agent;
        }

        // Créer un agent de test si aucun n'existe
        try {
            $agent = Agent::create([
                'name' => 'Agent Test',
                'phone' => '+225 07 00 00 00 00',
                'email' => 'agent.test@uvsystem.pro',
                'address' => 'Abidjan, Côte d\'Ivoire',
                'status' => 'active',
                'debt_limit' => 1000000, // 1M FCFA
            ]);

            // Simuler une dette
            $agent->current_debt = 750000; // 75% du plafond
            $agent->save();

            $this->warn('   ℹ️  Agent de test créé (sera utilisé pour les tests)');

            return $agent;
        } catch (\Exception $e) {
            $this->error('   ❌ Erreur création agent : '.$e->getMessage());

            return null;
        }
    }
}
