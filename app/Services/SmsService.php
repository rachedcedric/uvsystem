<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SmsService
{
    protected ?Client $client = null;

    protected bool $enabled;

    protected string $defaultCountryCode;

    public function __construct()
    {
        $this->enabled = config('services.sms.enabled', false);
        $this->defaultCountryCode = config('services.sms.default_country_code', '+225');

        if ($this->enabled && config('services.twilio.account_sid')) {
            $this->client = new Client(
                config('services.twilio.account_sid'),
                config('services.twilio.auth_token')
            );
        }
    }

    /**
     * Check if SMS service is enabled and configured.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->client !== null;
    }

    /**
     * Send an SMS to a phone number.
     */
    public function send(string $to, string $message): bool
    {
        if (! $this->isEnabled()) {
            Log::info('SMS not sent (disabled)', ['to' => $to, 'message' => $message]);

            return false;
        }

        try {
            $phone = $this->formatPhoneNumber($to);

            $this->client->messages->create($phone, [
                'from' => config('services.twilio.from'),
                'body' => $message,
            ]);

            Log::info('SMS sent successfully', ['to' => $phone]);

            activity()
                ->withProperties(['to' => $phone, 'message_length' => strlen($message)])
                ->log('SMS envoyé');

            return true;
        } catch (\Exception $e) {
            Log::error('SMS sending failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send SMS to an agent.
     */
    public function sendToAgent(Agent $agent, string $message): bool
    {
        if (! $agent->phone) {
            Log::warning('Cannot send SMS to agent without phone', ['agent_id' => $agent->id]);

            return false;
        }

        return $this->send($agent->phone, $message);
    }

    /**
     * Send debt alert SMS to agent.
     */
    public function sendDebtAlert(Agent $agent, float $currentDebt, float $limit): bool
    {
        $ratio = $limit > 0 ? round(($currentDebt / $limit) * 100, 1) : 0;

        $message = config('branding.name').": Alerte dette!\n";
        $message .= 'Votre dette: '.number_format($currentDebt, 0, ',', ' ')." FCFA\n";
        $message .= 'Plafond: '.number_format($limit, 0, ',', ' ')." FCFA\n";
        $message .= "Taux: {$ratio}%\n";
        $message .= 'Veuillez effectuer un remboursement.';

        return $this->sendToAgent($agent, $message);
    }

    /**
     * Send payment reminder SMS.
     */
    public function sendPaymentReminder(Agent $agent, float $debt): bool
    {
        $message = config('branding.name').": Rappel de paiement\n";
        $message .= "Cher(e) {$agent->name},\n";
        $message .= 'Votre solde: '.number_format($debt, 0, ',', ' ')." FCFA\n";
        $message .= 'Merci de régulariser votre situation.';

        return $this->sendToAgent($agent, $message);
    }

    /**
     * Send transaction confirmation SMS.
     */
    public function sendTransactionConfirmation(Agent $agent, float $amount, int $quantity, float $newDebt): bool
    {
        $message = config('branding.name').": Distribution reçue\n";
        $message .= 'Montant: '.number_format($amount, 0, ',', ' ')." FCFA\n";
        $message .= "Quantité: {$quantity} UV\n";
        $message .= 'Nouveau solde: '.number_format($newDebt, 0, ',', ' ').' FCFA';

        return $this->sendToAgent($agent, $message);
    }

    /**
     * Send remboursement confirmation SMS.
     */
    public function sendRemboursementConfirmation(Agent $agent, float $amount, float $remainingDebt): bool
    {
        $message = config('branding.name').": Remboursement reçu\n";
        $message .= 'Montant: '.number_format($amount, 0, ',', ' ')." FCFA\n";
        $message .= 'Solde restant: '.number_format($remainingDebt, 0, ',', ' ')." FCFA\n";
        $message .= 'Merci!';

        return $this->sendToAgent($agent, $message);
    }

    /**
     * Format phone number with country code.
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove spaces, dashes, and dots
        $phone = preg_replace('/[\s\-\.]/', '', $phone);

        // If already has + prefix, return as is
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        // If starts with 00, replace with +
        if (str_starts_with($phone, '00')) {
            return '+'.substr($phone, 2);
        }

        // Add default country code
        return $this->defaultCountryCode.$phone;
    }

    /**
     * Send bulk SMS to multiple agents.
     */
    public function sendBulk(array $agents, string $message): array
    {
        $results = ['success' => 0, 'failed' => 0];

        foreach ($agents as $agent) {
            if ($this->sendToAgent($agent, $message)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }
}
