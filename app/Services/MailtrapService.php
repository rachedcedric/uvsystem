<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Mailtrap\Config;
use Mailtrap\MailtrapClient;
use Mailtrap\Mime\MailtrapEmail;
use Symfony\Component\Mime\Address;

/**
 * Service pour gérer l'envoi d'emails via Mailtrap API
 *
 * @see https://api-docs.mailtrap.io/docs/mailtrap-api-docs/5tjdeg9545058-mailtrap-api
 * @see https://github.com/railsware/mailtrap-php
 */
class MailtrapService
{
    protected MailtrapClient $client;

    protected array $config;

    public function __construct()
    {
        $this->config = config('mailtrap');

        // Initialiser le client Mailtrap
        $this->client = new MailtrapClient(
            new Config($this->config['api_token'])
        );
    }

    /**
     * Envoyer un email simple
     *
     * @param  string  $to  Email destinataire
     * @param  string  $subject  Sujet
     * @param  string  $html  Contenu HTML
     * @param  string|null  $text  Contenu texte (optionnel)
     * @param  array  $options  Options supplémentaires (cc, bcc, attachments, etc.)
     * @return array Response de Mailtrap
     */
    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $options = []
    ): array {
        try {
            $email = (new MailtrapEmail)
                ->from(new Address(
                    $options['from_email'] ?? $this->config['sending']['from_email'],
                    $options['from_name'] ?? $this->config['sending']['from_name']
                ))
                ->to(new Address($to, $options['to_name'] ?? ''))
                ->subject($subject)
                ->html($html);

            // Ajouter le contenu texte si fourni
            if ($text) {
                $email->text($text);
            }

            // Ajouter CC si fourni
            if (! empty($options['cc'])) {
                foreach ($options['cc'] as $ccEmail => $ccName) {
                    $email->cc(new Address($ccEmail, $ccName ?? ''));
                }
            }

            // Ajouter BCC si fourni
            if (! empty($options['bcc'])) {
                foreach ($options['bcc'] as $bccEmail => $bccName) {
                    $email->bcc(new Address($bccEmail, $bccName ?? ''));
                }
            }

            // Ajouter Reply-To si fourni
            if (! empty($options['reply_to'])) {
                $email->replyTo(new Address(
                    $options['reply_to']['email'],
                    $options['reply_to']['name'] ?? ''
                ));
            }

            // Ajouter des catégories (pour analytics)
            if (! empty($options['category'])) {
                $email->category($options['category']);
            }

            // Ajouter des variables personnalisées
            if (! empty($options['custom_variables'])) {
                foreach ($options['custom_variables'] as $key => $value) {
                    $email->customVariable($key, $value);
                }
            }

            // Envoyer l'email
            if ($this->config['sending']['sandbox']) {
                // Mode testing/sandbox
                $response = $this->client->testing()->send($email);
            } else {
                // Mode production
                $response = $this->client->sending()->emails()->send($email);
            }

            // Logger le succès
            if ($this->config['logging']['enabled']) {
                Log::channel($this->config['logging']['channel'])->info('Mailtrap email sent', [
                    'to' => $to,
                    'subject' => $subject,
                    'sandbox' => $this->config['sending']['sandbox'],
                    'response' => $response,
                ]);
            }

            return [
                'success' => true,
                'message_id' => $response['message_ids'][0] ?? null,
                'response' => $response,
            ];
        } catch (\Exception $e) {
            // Logger l'erreur
            if ($this->config['logging']['enabled']) {
                Log::channel($this->config['logging']['channel'])->error('Mailtrap email failed', [
                    'to' => $to,
                    'subject' => $subject,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envoyer un email avec template
     *
     * @param  string  $to  Email destinataire
     * @param  string  $templateUuid  UUID du template Mailtrap
     * @param  array  $variables  Variables à injecter dans le template
     * @param  array  $options  Options supplémentaires
     * @return array Response de Mailtrap
     */
    public function sendWithTemplate(
        string $to,
        string $templateUuid,
        array $variables = [],
        array $options = []
    ): array {
        try {
            $email = (new MailtrapEmail)
                ->from(new Address(
                    $options['from_email'] ?? $this->config['sending']['from_email'],
                    $options['from_name'] ?? $this->config['sending']['from_name']
                ))
                ->to(new Address($to, $options['to_name'] ?? ''))
                ->templateUuid($templateUuid)
                ->templateVariables($variables);

            // Ajouter des options supplémentaires si nécessaire
            if (! empty($options['category'])) {
                $email->category($options['category']);
            }

            // Envoyer l'email
            if ($this->config['sending']['sandbox']) {
                $response = $this->client->testing()->send($email);
            } else {
                $response = $this->client->sending()->emails()->send($email);
            }

            // Logger le succès
            if ($this->config['logging']['enabled']) {
                Log::channel($this->config['logging']['channel'])->info('Mailtrap template email sent', [
                    'to' => $to,
                    'template_uuid' => $templateUuid,
                    'sandbox' => $this->config['sending']['sandbox'],
                    'response' => $response,
                ]);
            }

            return [
                'success' => true,
                'message_id' => $response['message_ids'][0] ?? null,
                'response' => $response,
            ];
        } catch (\Exception $e) {
            // Logger l'erreur
            if ($this->config['logging']['enabled']) {
                Log::channel($this->config['logging']['channel'])->error('Mailtrap template email failed', [
                    'to' => $to,
                    'template_uuid' => $templateUuid,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envoyer un email en masse (bulk)
     *
     * @param  array  $recipients  Liste de destinataires avec leurs données
     * @param  string  $subject  Sujet
     * @param  string  $html  Contenu HTML
     * @param  array  $options  Options supplémentaires
     * @return array Response de Mailtrap
     */
    public function sendBulk(
        array $recipients,
        string $subject,
        string $html,
        array $options = []
    ): array {
        if (! $this->config['bulk']['enabled']) {
            return [
                'success' => false,
                'error' => 'Bulk sending is disabled in configuration',
            ];
        }

        try {
            $results = [];
            $chunks = array_chunk($recipients, $this->config['bulk']['max_recipients']);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $recipient) {
                    $result = $this->send(
                        $recipient['email'],
                        $subject,
                        $html,
                        $options['text'] ?? null,
                        array_merge($options, [
                            'to_name' => $recipient['name'] ?? '',
                            'custom_variables' => $recipient['variables'] ?? [],
                        ])
                    );

                    $results[] = [
                        'email' => $recipient['email'],
                        'success' => $result['success'],
                        'message_id' => $result['message_id'] ?? null,
                    ];

                    // Rate limiting
                    usleep(1000000 / $this->config['rate_limit']['requests_per_second']);
                }
            }

            return [
                'success' => true,
                'total' => count($recipients),
                'results' => $results,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envoyer un email de notification système
     *
     * @param  string  $to  Email destinataire
     * @param  string  $subject  Sujet
     * @param  string  $message  Message
     * @param  string  $priority  Priorité (high, normal, low)
     * @return array Response de Mailtrap
     */
    public function sendNotification(
        string $to,
        string $subject,
        string $message,
        string $priority = 'normal'
    ): array {
        $html = view('emails.notification', [
            'subject' => $subject,
            'message' => $message,
            'priority' => $priority,
            'company' => [
                'name' => config('branding.company_name'),
                'email' => config('branding.company_email'),
                'phone' => config('branding.company_phone'),
            ],
        ])->render();

        return $this->send($to, $subject, $html, strip_tags($message), [
            'category' => 'notification',
            'custom_variables' => [
                'priority' => $priority,
                'type' => 'system_notification',
            ],
        ]);
    }

    /**
     * Obtenir les statistiques d'envoi
     *
     * @return array Statistiques
     */
    public function getStats(): array
    {
        try {
            // Note: Cette fonctionnalité nécessite l'API Account Management
            // Pour l'instant, retourner des stats basiques
            return [
                'success' => true,
                'sandbox_mode' => $this->config['sending']['sandbox'],
                'bulk_enabled' => $this->config['bulk']['enabled'],
                'rate_limit' => $this->config['rate_limit'],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifier la configuration Mailtrap
     *
     * @return array Résultat de la vérification
     */
    public function checkConfiguration(): array
    {
        $errors = [];

        if (empty($this->config['api_token'])) {
            $errors[] = 'MAILTRAP_API_TOKEN is not configured';
        }

        if ($this->config['sending']['sandbox'] && empty($this->config['testing']['inbox_id'])) {
            $errors[] = 'MAILTRAP_TESTING_INBOX_ID is required for sandbox mode';
        }

        if (! $this->config['sending']['sandbox'] && empty($this->config['sending']['inbox_id'])) {
            $errors[] = 'MAILTRAP_INBOX_ID is required for production sending';
        }

        return [
            'configured' => empty($errors),
            'errors' => $errors,
            'config' => [
                'api_token_set' => ! empty($this->config['api_token']),
                'sandbox_mode' => $this->config['sending']['sandbox'],
                'inbox_id_set' => ! empty($this->config['sending']['inbox_id']),
                'testing_inbox_set' => ! empty($this->config['testing']['inbox_id']),
            ],
        ];
    }
}
