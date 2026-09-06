<?php

namespace App\Console\Commands;

use App\Services\ResendService;
use Illuminate\Console\Command;

class CheckResendConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resend:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifier la configuration Resend';

    /**
     * Execute the console command.
     */
    public function handle(ResendService $resend): int
    {
        $this->info('🔍 Vérification de la configuration Resend...');
        $this->newLine();

        $check = $resend->checkConfiguration();

        // Afficher le statut global
        if ($check['configured']) {
            $this->info('✅ Configuration Resend valide et complète');
        } else {
            $this->error('❌ Configuration Resend incomplète');
            $this->newLine();
            $this->warn('Erreurs détectées :');
            foreach ($check['errors'] as $error) {
                $this->line("   • {$error}");
            }
        }

        $this->newLine();

        // Tableau de configuration
        $this->table(
            ['Paramètre', 'Statut', 'Valeur'],
            [
                [
                    'API Key',
                    $check['config']['api_key_set'] ? '✅' : '❌',
                    $check['config']['api_key_set'] ? 'Configuré (re_****)' : 'Non configuré',
                ],
                [
                    'Mail Mailer',
                    config('mail.default') === 'resend' ? '✅' : '⚠️',
                    config('mail.default'),
                ],
                [
                    'From Address',
                    ! empty($check['config']['from_address']) ? '✅' : '❌',
                    $check['config']['from_address'] ?? 'Non configuré',
                ],
                [
                    'From Name',
                    ! empty($check['config']['from_name']) ? '✅' : '❌',
                    $check['config']['from_name'] ?? 'Non configuré',
                ],
            ]
        );

        $this->newLine();

        // Informations Resend
        $this->info('📦 Informations Resend :');
        $this->table(
            ['Feature', 'Détails'],
            [
                ['Plan Gratuit', '100 emails/jour, 3 000 emails/mois'],
                ['Plan Pro', '50 000 emails/mois, $20/mois'],
                ['Plan Business', '100 000 emails/mois, $80/mois'],
                ['Delivery', '99.9% de délivrabilité'],
                ['Analytics', 'Opens, Clicks, Bounces en temps réel'],
                ['Webhooks', 'Événements: sent, delivered, opened, clicked, bounced'],
            ]
        );

        $this->newLine();

        // Recommandations
        if (! $check['configured']) {
            $this->warn('💡 Actions recommandées :');
            $this->newLine();
            $this->line('1. Créez un compte sur https://resend.com');
            $this->line('2. Vérifiez votre domaine : https://resend.com/domains');
            $this->line('3. Générez une API Key : https://resend.com/api-keys');
            $this->line('4. Configurez les variables dans .env :');
            $this->newLine();
            $this->comment('   RESEND_API_KEY=re_your_api_key');
            $this->comment('   MAIL_MAILER=resend');
            $this->comment('   MAIL_FROM_ADDRESS=onboarding@resend.dev');
            $this->comment('   MAIL_FROM_NAME="Your Name"');
            $this->newLine();
            $this->line('5. Testez l\'envoi : php artisan resend:test your-email@example.com');
        } else {
            $this->info('💡 Prêt pour l\'envoi d\'emails !');
            $this->newLine();
            $this->line('Testez l\'envoi avec :');
            $this->comment('   php artisan resend:test your-email@example.com');
            $this->newLine();
            $this->line('Types de test disponibles :');
            $this->comment('   --type=simple       Email HTML simple (défaut)');
            $this->comment('   --type=notification Email de notification système');
            $this->newLine();
            $this->line('Avantages de Resend :');
            $this->comment('   ✅ API simple et moderne');
            $this->comment('   ✅ Dashboard intuitif avec analytics en temps réel');
            $this->comment('   ✅ 99.9% de délivrabilité garantie');
            $this->comment('   ✅ Webhooks pour tous les événements');
            $this->comment('   ✅ Support React Email pour templates');
            $this->comment('   ✅ Gratuit : 100 emails/jour (3000/mois)');
        }

        $this->newLine();

        // Comparaison avec Mailtrap
        $this->info('📊 Comparaison Resend vs Mailtrap :');
        $this->table(
            ['Feature', 'Resend', 'Mailtrap'],
            [
                ['Plan Gratuit', '3 000 emails/mois', '500 emails/mois (Sandbox)'],
                ['Prix départ', '$20/mois (50K)', '$15/mois (10K)'],
                ['Délivrabilité', '99.9%', 'Standard'],
                ['Analytics', 'Temps réel', 'Temps réel'],
                ['Webhooks', 'Inclus', 'Inclus'],
                ['Interface', 'Moderne, simple', 'Complète, sandbox'],
                ['Support', 'Email + Discord', 'Email'],
                ['Use case', 'Production', 'Dev + Production'],
            ]
        );

        return $check['configured'] ? Command::SUCCESS : Command::FAILURE;
    }
}
