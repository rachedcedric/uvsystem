<?php

namespace App\Console\Commands;

use App\Services\MailtrapService;
use Illuminate\Console\Command;

class CheckMailtrapConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailtrap:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifier la configuration Mailtrap';

    /**
     * Execute the console command.
     */
    public function handle(MailtrapService $mailtrap): int
    {
        $this->info('🔍 Vérification de la configuration Mailtrap...');
        $this->newLine();

        $check = $mailtrap->checkConfiguration();
        $config = config('mailtrap');

        // Afficher le statut global
        if ($check['configured']) {
            $this->info('✅ Configuration Mailtrap valide et complète');
        } else {
            $this->error('❌ Configuration Mailtrap incomplète');
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
                    'API Token',
                    $check['config']['api_token_set'] ? '✅' : '❌',
                    $check['config']['api_token_set'] ? 'Configuré (****)' : 'Non configuré',
                ],
                [
                    'Account ID',
                    ! empty($config['account_id']) ? '✅' : '⚠️',
                    ! empty($config['account_id']) ? $config['account_id'] : 'Non configuré',
                ],
                [
                    'Mode Sandbox',
                    '📋',
                    $check['config']['sandbox_mode'] ? 'Activé (Testing)' : 'Désactivé (Production)',
                ],
                [
                    'Inbox ID (Production)',
                    $check['config']['inbox_id_set'] ? '✅' : '❌',
                    $check['config']['inbox_id_set'] ? 'Configuré' : 'Non configuré',
                ],
                [
                    'Testing Inbox ID',
                    $check['config']['testing_inbox_set'] ? '✅' : '❌',
                    $check['config']['testing_inbox_set'] ? 'Configuré' : 'Non configuré',
                ],
                [
                    'From Email',
                    ! empty($config['sending']['from_email']) ? '✅' : '❌',
                    $config['sending']['from_email'] ?? 'Non configuré',
                ],
                [
                    'From Name',
                    ! empty($config['sending']['from_name']) ? '✅' : '❌',
                    $config['sending']['from_name'] ?? 'Non configuré',
                ],
            ]
        );

        $this->newLine();

        // Features
        $this->info('📦 Fonctionnalités :');
        $this->table(
            ['Feature', 'Statut', 'Configuration'],
            [
                [
                    'Email Testing',
                    $config['testing']['enabled'] ? '✅ Activé' : '❌ Désactivé',
                    'Inbox: '.($config['testing']['inbox_id'] ?: 'Non configuré'),
                ],
                [
                    'Bulk Sending',
                    $config['bulk']['enabled'] ? '✅ Activé' : '❌ Désactivé',
                    'Max: '.$config['bulk']['max_recipients'].' destinataires',
                ],
                [
                    'Webhooks',
                    $config['webhooks']['enabled'] ? '✅ Activé' : '❌ Désactivé',
                    'Events: '.count($config['webhooks']['events']),
                ],
                [
                    'Logging',
                    $config['logging']['enabled'] ? '✅ Activé' : '❌ Désactivé',
                    'Channel: '.$config['logging']['channel'],
                ],
            ]
        );

        $this->newLine();

        // Rate Limiting
        $this->info('⚡ Rate Limiting :');
        $this->line("   • Requêtes par seconde : {$config['rate_limit']['requests_per_second']}");
        $this->line("   • Emails par heure : {$config['rate_limit']['emails_per_hour']}");

        $this->newLine();

        // Statistiques
        $stats = $mailtrap->getStats();
        if ($stats['success']) {
            $this->info('📊 Statistiques :');
            $this->line('   • Mode Sandbox : '.($stats['sandbox_mode'] ? 'Activé' : 'Désactivé'));
            $this->line('   • Bulk Sending : '.($stats['bulk_enabled'] ? 'Activé' : 'Désactivé'));
        }

        $this->newLine();

        // Recommandations
        if (! $check['configured']) {
            $this->warn('💡 Actions recommandées :');
            $this->newLine();
            $this->line('1. Créez un compte sur https://mailtrap.io');
            $this->line('2. Générez un API Token : https://mailtrap.io/api-tokens');
            $this->line('3. Créez un Inbox (Testing ou Production)');
            $this->line('4. Configurez les variables dans .env :');
            $this->newLine();
            $this->comment('   MAILTRAP_API_TOKEN=your_token_here');
            $this->comment('   MAILTRAP_ACCOUNT_ID=your_account_id');
            $this->comment('   MAILTRAP_INBOX_ID=your_inbox_id  # Production');
            $this->comment('   MAILTRAP_TESTING_INBOX_ID=your_testing_inbox_id  # Testing');
            $this->newLine();
            $this->line('5. Testez l\'envoi : php artisan mailtrap:test your-email@example.com');
        } else {
            $this->info('💡 Prêt pour l\'envoi d\'emails !');
            $this->newLine();
            $this->line('Testez l\'envoi avec :');
            $this->comment('   php artisan mailtrap:test your-email@example.com');
            $this->newLine();
            $this->line('Types de test disponibles :');
            $this->comment('   --type=simple       Email HTML simple (défaut)');
            $this->comment('   --type=notification Email de notification système');
            $this->comment('   --type=template     Email avec template (requis: --template=UUID)');
        }

        return $check['configured'] ? Command::SUCCESS : Command::FAILURE;
    }
}
