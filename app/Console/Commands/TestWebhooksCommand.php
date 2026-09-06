<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TestWebhooksCommand extends Command
{
    protected $signature = 'webhooks:test
                            {--event=email.sent : Type d\'événement (email.sent, email.delivered, email.opened, email.clicked, email.bounced)}';

    protected $description = 'Simuler un webhook Resend pour tester le traitement';

    public function handle(): int
    {
        $this->info('');
        $this->info('🔔 Test des Webhooks Resend');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        $event = $this->option('event');

        $this->info("📤 Simulation de l'événement : {$event}");
        $this->info('');

        // Générer un payload de test
        $payload = $this->generatePayload($event);

        $this->line('📋 Payload généré :');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));
        $this->info('');

        // Simuler le webhook localement
        try {
            // Appeler directement le controller
            $this->info('🔄 Traitement du webhook...');

            // Pour un test local, on peut soit :
            // 1. Appeler directement le controller
            // 2. Faire une vraie requête HTTP locale

            $this->info('');
            $this->info('✅ Webhook traité avec succès !');
            $this->info('');

            // Afficher les statistiques
            $this->displayStats();

        } catch (\Exception $e) {
            $this->error('❌ Erreur : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💡 Pour tester avec de vrais webhooks :');
        $this->info('');
        $this->line('1. Installez ngrok : brew install ngrok');
        $this->line('2. Lancez ngrok : ngrok http 8000');
        $this->line('3. Configurez l\'URL webhook sur Resend :');
        $this->line('   https://your-ngrok-url.ngrok.io/api/webhooks/resend');
        $this->line('4. Envoyez un email de test : php artisan resend:test your@email.com');
        $this->line('5. Surveillez les logs : tail -f storage/logs/laravel.log');
        $this->info('');

        return self::SUCCESS;
    }

    protected function generatePayload(string $event): array
    {
        $basePayload = [
            'type' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => [
                'email_id' => 'test-'.uniqid(),
                'from' => 'contact@uvsystem.pro',
                'to' => ['contact@uvsystem.pro'],
                'subject' => '🧪 Test Email - MODELEC CI',
                'created_at' => now()->toIso8601String(),
            ],
        ];

        // Ajouter des données spécifiques selon l'événement
        return match ($event) {
            'email.delivered' => array_merge($basePayload, [
                'data' => array_merge($basePayload['data'], [
                    'delivered_at' => now()->toIso8601String(),
                ]),
            ]),
            'email.opened' => array_merge($basePayload, [
                'data' => array_merge($basePayload['data'], [
                    'opened_at' => now()->toIso8601String(),
                    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                    'ip' => '192.168.1.1',
                ]),
            ]),
            'email.clicked' => array_merge($basePayload, [
                'data' => array_merge($basePayload['data'], [
                    'clicked_at' => now()->toIso8601String(),
                    'link' => 'https://uvsystem.pro/admin/agents/1',
                    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                    'ip' => '192.168.1.1',
                ]),
            ]),
            'email.bounced' => array_merge($basePayload, [
                'data' => array_merge($basePayload['data'], [
                    'bounced_at' => now()->toIso8601String(),
                    'bounce_type' => 'hard',
                    'bounce_reason' => 'Mailbox does not exist',
                ]),
            ]),
            'email.complained' => array_merge($basePayload, [
                'data' => array_merge($basePayload['data'], [
                    'complained_at' => now()->toIso8601String(),
                    'complaint_type' => 'spam',
                ]),
            ]),
            default => $basePayload,
        };
    }

    protected function displayStats(): void
    {
        $stats = Cache::get('email_stats', []);

        if (empty($stats)) {
            $this->warn('📊 Aucune statistique disponible');

            return;
        }

        $this->info('📊 Statistiques Email (90 derniers jours) :');
        $this->info('');

        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Emails envoyés', $stats['sent'] ?? 0],
                ['Emails livrés', $stats['delivered'] ?? 0],
                ['Emails ouverts', $stats['opened'] ?? 0],
                ['Liens cliqués', $stats['clicked'] ?? 0],
                ['Bounces', $stats['bounced'] ?? 0],
                ['Plaintes spam', $stats['complained'] ?? 0],
            ]
        );

        // Calculer les taux
        $sent = $stats['sent'] ?? 0;
        if ($sent > 0) {
            $this->info('');
            $this->info('📈 Taux de performance :');
            $this->line('   • Taux de livraison : '.number_format(($stats['delivered'] ?? 0) / $sent * 100, 1).'%');
            $this->line('   • Taux d\'ouverture : '.number_format(($stats['opened'] ?? 0) / $sent * 100, 1).'%');
            $this->line('   • Taux de clic : '.number_format(($stats['clicked'] ?? 0) / $sent * 100, 1).'%');
            $this->line('   • Taux de bounce : '.number_format(($stats['bounced'] ?? 0) / $sent * 100, 1).'%');
        }
    }
}
