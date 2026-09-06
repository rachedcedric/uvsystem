<?php

namespace App\Console\Commands;

use App\Services\MailtrapService;
use Illuminate\Console\Command;

class TestMailtrap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailtrap:test
                            {email : Email destinataire}
                            {--type=simple : Type de test (simple, template, notification)}
                            {--template= : UUID du template (pour type=template)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tester l\'envoi d\'email via Mailtrap API';

    /**
     * Execute the console command.
     */
    public function handle(MailtrapService $mailtrap): int
    {
        $email = $this->argument('email');
        $type = $this->option('type');

        $this->info("🚀 Test d'envoi Mailtrap...");
        $this->newLine();

        // Vérifier la configuration
        $this->info('📋 Vérification de la configuration...');
        $check = $mailtrap->checkConfiguration();

        if (! $check['configured']) {
            $this->error('❌ Configuration Mailtrap incomplète :');
            foreach ($check['errors'] as $error) {
                $this->line("   - {$error}");
            }
            $this->newLine();
            $this->info('💡 Consultez le fichier .env.example pour les variables requises.');

            return Command::FAILURE;
        }

        $this->info('✅ Configuration OK');
        $this->line('   - API Token: '.($check['config']['api_token_set'] ? 'Configuré' : 'Non configuré'));
        $this->line('   - Mode Sandbox: '.($check['config']['sandbox_mode'] ? 'Activé' : 'Désactivé'));
        $this->line('   - Inbox ID: '.($check['config']['inbox_id_set'] ? 'Configuré' : 'Non configuré'));
        $this->newLine();

        // Envoyer l'email selon le type
        $this->info("📧 Envoi de l'email de test à : {$email}");

        $result = match ($type) {
            'template' => $this->sendTemplateTest($mailtrap, $email),
            'notification' => $this->sendNotificationTest($mailtrap, $email),
            default => $this->sendSimpleTest($mailtrap, $email),
        };

        $this->newLine();

        if ($result['success']) {
            $this->info('✅ Email envoyé avec succès !');
            $this->line('   - Message ID: '.($result['message_id'] ?? 'N/A'));

            if ($check['config']['sandbox_mode']) {
                $this->newLine();
                $this->comment('💡 Mode Sandbox activé : Consultez votre inbox Mailtrap pour voir l\'email');
                $this->comment('   https://mailtrap.io/inboxes');
            }

            return Command::SUCCESS;
        } else {
            $this->error('❌ Échec de l\'envoi :');
            $this->line('   '.($result['error'] ?? 'Erreur inconnue'));

            return Command::FAILURE;
        }
    }

    /**
     * Envoyer un email simple de test
     */
    protected function sendSimpleTest(MailtrapService $mailtrap, string $email): array
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #f9fafb; padding: 30px; border-radius: 10px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; }
                .content { background: white; padding: 20px; margin-top: 20px; border-radius: 8px; }
                .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎉 Test Mailtrap API</h1>
                </div>
                <div class="content">
                    <h2>Email de Test</h2>
                    <p>Ceci est un email de test envoyé via Mailtrap API.</p>
                    <p><strong>Date:</strong> {date}</p>
                    <p><strong>Configuration:</strong> {config}</p>
                    <ul>
                        <li>✅ Connexion API établie</li>
                        <li>✅ Email envoyé avec succès</li>
                        <li>✅ Système fonctionnel</li>
                    </ul>
                </div>
                <div class="footer">
                    <p>Système de Gestion UV - Mailtrap Integration</p>
                </div>
            </div>
        </body>
        </html>
        HTML;

        $html = str_replace('{date}', now()->format('d/m/Y H:i:s'), $html);
        $html = str_replace('{config}', config('app.env'), $html);

        return $mailtrap->send(
            $email,
            '🧪 Test Mailtrap API - '.now()->format('d/m/Y H:i'),
            $html,
            'Ceci est un email de test envoyé via Mailtrap API.',
            [
                'category' => 'test',
                'custom_variables' => [
                    'test_type' => 'simple',
                    'environment' => config('app.env'),
                    'timestamp' => now()->timestamp,
                ],
            ]
        );
    }

    /**
     * Envoyer un email avec template de test
     */
    protected function sendTemplateTest(MailtrapService $mailtrap, string $email): array
    {
        $templateUuid = $this->option('template');

        if (! $templateUuid) {
            return [
                'success' => false,
                'error' => 'Template UUID requis. Utilisez --template=UUID',
            ];
        }

        return $mailtrap->sendWithTemplate(
            $email,
            $templateUuid,
            [
                'user_name' => 'Utilisateur Test',
                'test_date' => now()->format('d/m/Y H:i:s'),
                'environment' => config('app.env'),
            ],
            [
                'category' => 'test',
            ]
        );
    }

    /**
     * Envoyer une notification de test
     */
    protected function sendNotificationTest(MailtrapService $mailtrap, string $email): array
    {
        return $mailtrap->sendNotification(
            $email,
            '🔔 Notification de Test Mailtrap',
            'Ceci est une notification de test envoyée le '.now()->format('d/m/Y à H:i:s').".\n\n".
            "Si vous recevez cet email, l'intégration Mailtrap fonctionne correctement !\n\n".
            'Environnement: '.config('app.env')."\n".
            'Mode Sandbox: '.(config('mailtrap.sending.sandbox') ? 'Activé' : 'Désactivé'),
            'normal'
        );
    }
}
