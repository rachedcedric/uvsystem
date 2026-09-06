<?php

namespace App\Console\Commands;

use App\Services\ResendService;
use Illuminate\Console\Command;

class TestResend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resend:test 
                            {email : Email destinataire}
                            {--type=simple : Type de test (simple, notification)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tester l\'envoi d\'email via Resend API';

    /**
     * Execute the console command.
     */
    public function handle(ResendService $resend): int
    {
        $email = $this->argument('email');
        $type = $this->option('type');

        $this->info("🚀 Test d'envoi Resend...");
        $this->newLine();

        // Vérifier la configuration
        $this->info('📋 Vérification de la configuration...');
        $check = $resend->checkConfiguration();

        if (! $check['configured']) {
            $this->error('❌ Configuration Resend incomplète :');
            foreach ($check['errors'] as $error) {
                $this->line("   - {$error}");
            }
            $this->newLine();
            $this->info('💡 Consultez le fichier .env.example pour les variables requises.');
            $this->newLine();
            $this->comment('Configuration requise :');
            $this->comment('   RESEND_API_KEY=re_your_api_key');
            $this->comment('   MAIL_MAILER=resend');
            $this->comment('   MAIL_FROM_ADDRESS=onboarding@resend.dev');
            $this->comment('   MAIL_FROM_NAME="Your Name"');

            return Command::FAILURE;
        }

        $this->info('✅ Configuration OK');
        $this->line('   - API Key: '.($check['config']['api_key_set'] ? 'Configuré' : 'Non configuré'));
        $this->line('   - Mailer: '.$check['config']['mailer']);
        $this->line('   - From: '.$check['config']['from_name'].' <'.$check['config']['from_address'].'>');
        $this->newLine();

        // Envoyer l'email selon le type
        $this->info("📧 Envoi de l'email de test à : {$email}");

        $result = match ($type) {
            'notification' => $this->sendNotificationTest($resend, $email),
            default => $this->sendSimpleTest($resend, $email),
        };

        $this->newLine();

        if ($result['success']) {
            $this->info('✅ Email envoyé avec succès !');
            $this->line('   - Email ID: '.($result['id'] ?? 'N/A'));
            $this->newLine();
            $this->comment('💡 Vérifiez votre boîte email pour voir le message.');
            $this->comment('   Pour voir les statistiques : https://resend.com/emails/'.($result['id'] ?? ''));

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
    protected function sendSimpleTest(ResendService $resend, string $email): array
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; }
                .content { padding: 40px; }
                .content h2 { color: #333; margin-top: 0; }
                .info-box { background: #f0f4ff; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .checklist { list-style: none; padding: 0; }
                .checklist li { padding: 8px 0; }
                .checklist li:before { content: "✅ "; margin-right: 10px; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 14px; }
                .badge { display: inline-block; background: #10b981; color: white; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎉 Test Resend API</h1>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;">Email de test réussi</p>
                </div>
                <div class="content">
                    <h2>Félicitations !</h2>
                    <p>Si vous lisez cet email, c'est que <strong>l'intégration Resend fonctionne parfaitement</strong> ! 🚀</p>
                    
                    <div class="info-box">
                        <strong>📊 Informations de test</strong><br>
                        <strong>Date:</strong> {date}<br>
                        <strong>Environnement:</strong> <span class="badge">{env}</span><br>
                        <strong>Service:</strong> Resend API
                    </div>
                    
                    <h3>✅ Fonctionnalités validées</h3>
                    <ul class="checklist">
                        <li>Connexion API Resend établie</li>
                        <li>Email HTML envoyé avec succès</li>
                        <li>Configuration Laravel correcte</li>
                        <li>Service ResendService fonctionnel</li>
                    </ul>
                    
                    <p>Vous pouvez maintenant utiliser Resend pour envoyer vos emails transactionnels en production.</p>
                </div>
                <div class="footer">
                    <p><strong>{app_name}</strong> - Système de Gestion UV</p>
                    <p style="margin: 5px 0; font-size: 12px; color: #999;">
                        Email envoyé via Resend API
                    </p>
                </div>
            </div>
        </body>
        </html>
        HTML;

        $html = str_replace('{date}', now()->format('d/m/Y H:i:s'), $html);
        $html = str_replace('{env}', config('app.env'), $html);
        $html = str_replace('{app_name}', config('app.name'), $html);

        return $resend->send(
            $email,
            '🧪 Test Resend API - '.now()->format('d/m/Y H:i'),
            $html,
            [
                'text' => 'Ceci est un email de test envoyé via Resend API. Si vous recevez cet email, l\'intégration fonctionne correctement !',
                'tags' => [
                    [
                        'name' => 'category',
                        'value' => 'test',
                    ],
                    [
                        'name' => 'environment',
                        'value' => config('app.env'),
                    ],
                ],
            ]
        );
    }

    /**
     * Envoyer une notification de test
     */
    protected function sendNotificationTest(ResendService $resend, string $email): array
    {
        return $resend->sendNotification(
            $email,
            '🔔 Notification de Test Resend',
            'Ceci est une notification de test envoyée le '.now()->format('d/m/Y à H:i:s').".\n\n".
            "Si vous recevez cet email, l'intégration Resend fonctionne correctement !\n\n".
            'Environnement: '.config('app.env')."\n".
            "Service: Resend API\n".
            'Application: '.config('app.name'),
            'normal'
        );
    }
}
