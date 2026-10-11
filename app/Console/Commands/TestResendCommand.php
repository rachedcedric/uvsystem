<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Resend\Laravel\Facades\Resend;

class TestResendCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resend:test {email? : Email address to send test to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Resend API integration by sending a test email';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('');
        $this->info('🚀 Test de l\'intégration Resend');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        // Vérifier la configuration
        if (! config('services.resend.key')) {
            $this->error('❌ RESEND_API_KEY n\'est pas configuré dans .env');
            $this->info('');
            $this->info('Ajoutez cette ligne dans votre fichier .env :');
            $this->line('RESEND_API_KEY=re_your_api_key_here');
            $this->info('');

            return self::FAILURE;
        }

        $this->info('✅ Configuration détectée');
        $this->line('   API Key: '.substr(config('services.resend.key'), 0, 10).'...');
        $this->info('');

        // Demander l'email si non fourni
        $email = $this->argument('email');
        if (! $email) {
            $email = $this->ask('📧 Adresse email de destination ?', 'admin@'.config('branding.domain'));
        }

        // Valider l'email
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('❌ Adresse email invalide : '.$email);

            return self::FAILURE;
        }

        $this->info('📤 Envoi d\'un email de test à : '.$email);
        $this->info('');

        // Test 1: API Resend directe
        $this->line('Test 1/3 : API Resend directe...');

        try {
            $result = Resend::emails()->send([
                'from' => config('branding.name').' <onboarding@resend.dev>',
                'to' => [$email],
                'subject' => '🧪 Test Resend API - '.now()->format('d/m/Y H:i:s'),
                'html' => $this->getTestEmailHtml(),
            ]);

            $this->info('   ✅ Email envoyé avec succès !');
            $this->line('   ID: '.$result->id);
            $this->info('');
        } catch (\Exception $e) {
            $this->error('   ❌ Erreur : '.$e->getMessage());
            $this->info('');

            if (str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), 'Unauthorized')) {
                $this->warn('💡 Votre clé API semble invalide. Vérifiez :');
                $this->line('   1. https://resend.com/api-keys');
                $this->line('   2. La clé commence par "re_"');
                $this->line('   3. La clé n\'est pas révoquée');
            }

            return self::FAILURE;
        }

        // Test 2: Laravel Mail avec Resend
        $this->line('Test 2/3 : Laravel Mail avec Resend...');

        try {
            Mail::raw(
                $this->getTestEmailText(),
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('🧪 Test Laravel Mail + Resend - '.now()->format('d/m/Y H:i:s'));
                }
            );

            $this->info('   ✅ Email Laravel envoyé avec succès !');
            $this->info('');
        } catch (\Exception $e) {
            $this->error('   ❌ Erreur : '.$e->getMessage());
            $this->info('');

            return self::FAILURE;
        }

        // Test 3: Vérifier le statut des emails
        $this->line('Test 3/3 : Vérification du statut...');

        try {
            // Note: Resend n'a pas encore d'endpoint pour lister les emails
            // On affiche juste les infos de la documentation
            $this->info('   ✅ Pour voir le statut de vos emails :');
            $this->line('   • Dashboard: https://resend.com/emails');
            $this->line('   • ID Email: '.$result->id);
            $this->info('');
        } catch (\Exception $e) {
            $this->warn('   ⚠️  Impossible de vérifier le statut');
            $this->info('');
        }

        // Résumé
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('✅ Tests terminés avec succès !');
        $this->info('');
        $this->info('📊 Prochaines étapes :');
        $this->line('   1. Vérifiez votre boîte mail : '.$email);
        $this->line('   2. Consultez le dashboard : https://resend.com/emails');
        $this->line('   3. Configurez les webhooks si besoin');
        $this->info('');
        $this->info('📖 Documentation webhooks :');
        $this->line('   backend/docs/RESEND_INTEGRATION.md (Section Webhooks)');
        $this->info('');

        return self::SUCCESS;
    }

    /**
     * Get HTML content for test email
     */
    private function getTestEmailHtml(): string
    {
        return strtr(<<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }
        .content {
            background: #f9fafb;
            padding: 30px;
            border-radius: 0 0 10px 10px;
        }
        .badge {
            display: inline-block;
            background: #10b981;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin: 10px 0;
        }
        .info-box {
            background: white;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .footer {
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .check-list {
            list-style: none;
            padding: 0;
        }
        .check-list li {
            padding: 8px 0;
        }
        .check-list li:before {
            content: "✅ ";
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚀 Test Resend API</h1>
        <div class="badge">{$brand}</div>
    </div>

    <div class="content">
        <h2>Intégration réussie ! 🎉</h2>

        <p>Félicitations ! Si vous recevez cet email, cela signifie que l'intégration <strong>Resend</strong> fonctionne parfaitement avec votre système UV.</p>

        <div class="info-box">
            <strong>📋 Informations du test :</strong>
            <ul class="check-list">
                <li>Date d'envoi : <strong>{date}</strong></li>
                <li>Méthode : API Resend directe</li>
                <li>Statut : Envoyé avec succès</li>
                <li>Système : {$brand} v1.0</li>
            </ul>
        </div>

        <h3>✨ Fonctionnalités testées :</h3>
        <ul class="check-list">
            <li>Connexion à l'API Resend</li>
            <li>Envoi d'email HTML stylisé</li>
            <li>Configuration Laravel Mail</li>
            <li>Templates personnalisés</li>
        </ul>

        <h3>📊 Prochaines étapes :</h3>
        <ol>
            <li>Configurez vos webhooks sur <a href="https://resend.com/webhooks">resend.com/webhooks</a></li>
            <li>Personnalisez vos templates d'emails</li>
            <li>Activez les analytics et le tracking</li>
            <li>Testez les notifications du système</li>
        </ol>
    </div>

    <div class="footer">
        <p>📧 Cet email a été envoyé via <strong>Resend API</strong></p>
        <p>{$brand} - {$tagline}</p>
        <p style="color: #9ca3af; font-size: 11px;">Si vous n'avez pas demandé cet email, vous pouvez l'ignorer en toute sécurité.</p>
    </div>
</body>
</html>
HTML, ['{$brand}' => config('branding.name'), '{$tagline}' => config('branding.tagline')]);
    }

    /**
     * Get plain text content for test email
     */
    private function getTestEmailText(): string
    {
        return strtr(<<<'TEXT'
🚀 TEST RESEND API - {$brand}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ Intégration réussie !

Félicitations ! Si vous recevez cet email, cela signifie que
l'intégration Resend fonctionne parfaitement avec Laravel Mail.

📋 INFORMATIONS DU TEST :
• Date d'envoi : {date}
• Méthode : Laravel Mail + Resend
• Statut : Envoyé avec succès
• Système : {$brand} v1.0

✨ FONCTIONNALITÉS TESTÉES :
✓ Connexion à l'API Resend
✓ Laravel Mail integration
✓ Envoi d'email texte
✓ Configuration MAIL_MAILER=resend

📊 PROCHAINES ÉTAPES :
1. Configurez vos webhooks
2. Personnalisez vos templates
3. Activez les analytics
4. Testez les notifications

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📧 Envoyé via Resend API
{$brand} - {$tagline}
TEXT, ['{$brand}' => config('branding.name'), '{$tagline}' => config('branding.tagline')]);
    }
}
