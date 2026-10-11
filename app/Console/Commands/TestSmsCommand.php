<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForOneTenant;
use App\Models\Agent;
use App\Services\SmsService;
use Illuminate\Console\Command;

class TestSmsCommand extends Command
{
    use RunsForOneTenant;

    protected $signature = 'sms:test {--tenant= : Identifiant du tenant (obligatoire)} 
                            {phone? : Numéro de téléphone}
                            {--type=simple : Type de SMS (simple, debt, reminder, transaction, remboursement)}
                            {--agent= : ID de l\'agent (pour tests avec données réelles)}';

    protected $description = 'Tester l\'envoi de SMS via Twilio';

    public function __construct(protected SmsService $sms)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('📱 Test SMS Twilio');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        // Vérifier la configuration
        if (! $this->checkConfiguration()) {
            return self::FAILURE;
        }

        $phone = $this->argument('phone');
        $type = $this->option('type');

        // Demander le numéro si non fourni
        if (! $phone) {
            $phone = $this->ask('📞 Numéro de téléphone (avec indicatif pays) ?', '+22507XXXXXXXX');
        }

        // Valider le format
        if (! preg_match('/^\+?[0-9]{8,15}$/', preg_replace('/[\s\-\.]/', '', $phone))) {
            $this->error('❌ Format de numéro invalide');

            return self::FAILURE;
        }

        $this->info("📤 Envoi SMS à : {$phone}");
        $this->info("📋 Type : {$type}");
        $this->info('');

        // Récupérer ou créer un agent de test
        $agent = $this->getTestAgent();

        // Envoyer selon le type
        $result = match ($type) {
            'debt' => $this->testDebtAlert($agent, $phone),
            'reminder' => $this->testPaymentReminder($agent, $phone),
            'transaction' => $this->testTransactionConfirmation($agent, $phone),
            'remboursement' => $this->testRemboursementConfirmation($agent, $phone),
            default => $this->testSimpleSms($phone),
        };

        $this->info('');

        if ($result) {
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info('✅ SMS envoyé avec succès !');
            $this->info('');
            $this->info('📊 Vérifiez :');
            $this->line('   1. Le téléphone '.$phone.' pour le message');
            $this->line('   2. La console Twilio : https://console.twilio.com');
            $this->line('   3. Les logs : tail -f storage/logs/laravel.log');
        } else {
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->error('❌ Échec de l\'envoi SMS');
            $this->info('');
            $this->warn('💡 Vérifiez :');
            $this->line('   1. Les credentials Twilio dans .env');
            $this->line('   2. Le numéro FROM (TWILIO_FROM)');
            $this->line('   3. Le solde Twilio');
            $this->line('   4. Les logs d\'erreur');
        }

        $this->info('');

        return $result ? self::SUCCESS : self::FAILURE;
    }

    protected function checkConfiguration(): bool
    {
        $this->line('📋 Vérification de la configuration...');

        $checks = [
            'SMS_ENABLED' => config('services.sms.enabled', false),
            'TWILIO_ACCOUNT_SID' => ! empty(config('services.twilio.account_sid')),
            'TWILIO_AUTH_TOKEN' => ! empty(config('services.twilio.auth_token')),
            'TWILIO_FROM' => ! empty(config('services.twilio.from')),
        ];

        $allOk = true;
        foreach ($checks as $key => $ok) {
            if ($ok) {
                $this->info("   ✅ {$key} : Configuré");
            } else {
                $this->error("   ❌ {$key} : Non configuré");
                $allOk = false;
            }
        }

        $this->info('');

        if (! $allOk) {
            $this->error('❌ Configuration SMS incomplète');
            $this->info('');
            $this->warn('Ajoutez ces variables dans .env :');
            $this->line('');
            $this->line('   SMS_ENABLED=true');
            $this->line('   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            $this->line('   TWILIO_AUTH_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            $this->line('   TWILIO_FROM=+12345678901');
            $this->info('');
            $this->info('📖 Documentation : https://console.twilio.com');

            return false;
        }

        if (! $this->sms->isEnabled()) {
            $this->warn('⚠️  Service SMS désactivé (SMS_ENABLED=false)');

            if ($this->confirm('Voulez-vous activer les SMS pour ce test ?', true)) {
                $this->warn('   Mode simulation activé pour ce test');
            } else {
                return false;
            }
        }

        $this->info('✅ Configuration OK');

        return true;
    }

    protected function getTestAgent(): Agent
    {
        $agentId = $this->option('agent');

        if ($agentId) {
            $agent = Agent::find($agentId);
            if ($agent) {
                return $agent;
            }
        }

        // Créer un agent fictif pour le test
        return new Agent([
            'id' => 0,
            'name' => 'Agent Test',
            'phone' => '+22507XXXXXXXX',
            'current_debt' => 500000,
            'debt_limit' => 1000000,
        ]);
    }

    protected function testSimpleSms(string $phone): bool
    {
        $this->line('📝 Envoi d\'un SMS simple...');

        $message = '🧪 Test '.config('branding.name')."\n";
        $message .= 'Date: '.now()->format('d/m/Y H:i')."\n";
        $message .= "Ce message confirme que l'intégration SMS fonctionne correctement.";

        $this->line('');
        $this->line('📄 Message :');
        $this->line('   '.str_replace("\n", "\n   ", $message));
        $this->line('');

        return $this->sms->send($phone, $message);
    }

    protected function testDebtAlert(Agent $agent, string $phone): bool
    {
        $this->line('📝 Envoi d\'une alerte de dette...');

        $message = config('branding.name').": Alerte dette!\n";
        $message .= 'Votre dette: '.number_format($agent->current_debt, 0, ',', ' ')." FCFA\n";
        $message .= 'Plafond: '.number_format($agent->debt_limit, 0, ',', ' ')." FCFA\n";
        $message .= 'Taux: '.round(($agent->current_debt / $agent->debt_limit) * 100, 1)."%\n";
        $message .= 'Veuillez effectuer un remboursement.';

        $this->line('');
        $this->line('📄 Message :');
        $this->line('   '.str_replace("\n", "\n   ", $message));
        $this->line('');

        return $this->sms->send($phone, $message);
    }

    protected function testPaymentReminder(Agent $agent, string $phone): bool
    {
        $this->line('📝 Envoi d\'un rappel de paiement...');

        $message = config('branding.name').": Rappel de paiement\n";
        $message .= "Cher(e) {$agent->name},\n";
        $message .= 'Votre solde: '.number_format($agent->current_debt, 0, ',', ' ')." FCFA\n";
        $message .= 'Merci de régulariser votre situation.';

        $this->line('');
        $this->line('📄 Message :');
        $this->line('   '.str_replace("\n", "\n   ", $message));
        $this->line('');

        return $this->sms->send($phone, $message);
    }

    protected function testTransactionConfirmation(Agent $agent, string $phone): bool
    {
        $this->line('📝 Envoi d\'une confirmation de transaction...');

        $message = config('branding.name').": Distribution reçue\n";
        $message .= 'Montant: '.number_format(150000, 0, ',', ' ')." FCFA\n";
        $message .= "Quantité: 100 UV\n";
        $message .= 'Nouveau solde: '.number_format($agent->current_debt + 150000, 0, ',', ' ').' FCFA';

        $this->line('');
        $this->line('📄 Message :');
        $this->line('   '.str_replace("\n", "\n   ", $message));
        $this->line('');

        return $this->sms->send($phone, $message);
    }

    protected function testRemboursementConfirmation(Agent $agent, string $phone): bool
    {
        $this->line('📝 Envoi d\'une confirmation de remboursement...');

        $message = config('branding.name').": Remboursement reçu\n";
        $message .= 'Montant: '.number_format(100000, 0, ',', ' ')." FCFA\n";
        $message .= 'Solde restant: '.number_format(max(0, $agent->current_debt - 100000), 0, ',', ' ')." FCFA\n";
        $message .= 'Merci!';

        $this->line('');
        $this->line('📄 Message :');
        $this->line('   '.str_replace("\n", "\n   ", $message));
        $this->line('');

        return $this->sms->send($phone, $message);
    }
}
