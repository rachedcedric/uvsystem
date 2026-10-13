<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Resend\Laravel\Facades\Resend as ResendFacade;

/**
 * Service pour gérer l'envoi d'emails via Resend API
 *
 * @see https://resend.com/docs/send-with-laravel
 * @see https://github.com/resendlabs/resend-laravel
 */
class ResendService
{
    /**
     * Envoyer un email simple
     *
     * @param  string|array  $to  Email(s) destinataire(s)
     * @param  string  $subject  Sujet
     * @param  string  $html  Contenu HTML
     * @param  array  $options  Options supplémentaires
     * @return array Response de Resend
     */
    public function send(
        string|array $to,
        string $subject,
        string $html,
        array $options = []
    ): array {
        try {
            $from = $options['from'] ?? config('mail.from.address');
            $fromName = $options['from_name'] ?? config('mail.from.name');

            $payload = [
                'from' => "{$fromName} <{$from}>",
                'to' => is_array($to) ? $to : [$to],
                'subject' => $subject,
                'html' => $html,
            ];

            // Ajouter le contenu texte si fourni
            if (! empty($options['text'])) {
                $payload['text'] = $options['text'];
            }

            // Ajouter CC si fourni
            if (! empty($options['cc'])) {
                $payload['cc'] = is_array($options['cc']) ? $options['cc'] : [$options['cc']];
            }

            // Ajouter BCC si fourni
            if (! empty($options['bcc'])) {
                $payload['bcc'] = is_array($options['bcc']) ? $options['bcc'] : [$options['bcc']];
            }

            // Ajouter Reply-To si fourni
            if (! empty($options['reply_to'])) {
                $payload['reply_to'] = $options['reply_to'];
            }

            // Ajouter des attachments si fournis
            if (! empty($options['attachments'])) {
                $payload['attachments'] = $options['attachments'];
            }

            // Ajouter des tags si fournis (pour analytics)
            if (! empty($options['tags'])) {
                $payload['tags'] = $options['tags'];
            }

            // Envoyer l'email
            $response = ResendFacade::emails()->send($payload);

            // Logger le succès
            Log::info('Resend email sent', [
                'to' => $to,
                'subject' => $subject,
                'response' => $response,
            ]);

            return [
                'success' => true,
                'id' => $response->id ?? null,
                'response' => $response,
            ];
        } catch (\Exception $e) {
            // Logger l'erreur
            Log::error('Resend email failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envoyer un email à partir d'un Mailable Laravel
     *
     * @param  string|array  $to  Email(s) destinataire(s)
     * @param  Mailable  $mailable  Instance de Mailable
     * @param  array  $options  Options supplémentaires
     * @return array Response de Resend
     */
    public function sendMailable(
        string|array $to,
        Mailable $mailable,
        array $options = []
    ): array {
        try {
            $html = $mailable->render();
            $subject = $mailable->subject ?? 'Notification';

            return $this->send($to, $subject, $html, $options);
        } catch (\Exception $e) {
            Log::error('Resend mailable failed', [
                'to' => $to,
                'mailable' => get_class($mailable),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envoyer un email de notification système
     *
     * @param  string|array  $to  Email(s) destinataire(s)
     * @param  string  $subject  Sujet
     * @param  string  $message  Message
     * @param  string  $priority  Priorité (high, normal, low)
     * @return array Response de Resend
     */
    public function sendNotification(
        string|array $to,
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

        return $this->send($to, $subject, $html, [
            'text' => strip_tags($message),
            'tags' => [
                [
                    'name' => 'category',
                    'value' => 'notification',
                ],
                [
                    'name' => 'priority',
                    'value' => $priority,
                ],
            ],
        ]);
    }

    /**
     * Envoyer des emails en masse
     *
     * @param  array  $emails  Liste d'emails avec leur contenu
     * @return array Résultats de l'envoi
     */
    public function sendBulk(array $emails): array
    {
        try {
            $results = [];

            foreach ($emails as $email) {
                $result = $this->send(
                    $email['to'],
                    $email['subject'],
                    $email['html'],
                    $email['options'] ?? []
                );

                $results[] = [
                    'to' => $email['to'],
                    'success' => $result['success'],
                    'id' => $result['id'] ?? null,
                ];

                // Rate limiting simple (10 emails/seconde max)
                usleep(100000); // 0.1 seconde
            }

            return [
                'success' => true,
                'total' => count($emails),
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
     * Vérifier la configuration Resend
     *
     * @return array Résultat de la vérification
     */
    public function checkConfiguration(): array
    {
        $errors = [];

        if (empty(config('services.resend.key'))) {
            $errors[] = 'RESEND_API_KEY is not configured';
        }

        if (empty(config('mail.from.address'))) {
            $errors[] = 'MAIL_FROM_ADDRESS is not configured';
        }

        if (empty(config('mail.from.name'))) {
            $errors[] = 'MAIL_FROM_NAME is not configured';
        }

        return [
            'configured' => empty($errors),
            'errors' => $errors,
            'config' => [
                'api_key_set' => ! empty(config('services.resend.key')),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
                'mailer' => config('mail.default'),
            ],
        ];
    }

    /**
     * Récupérer un email par son ID
     *
     * @param  string  $emailId  ID de l'email
     * @return array|null Données de l'email
     */
    public function getEmail(string $emailId): ?array
    {
        try {
            $response = ResendFacade::emails()->get($emailId);

            return (array) $response;
        } catch (\Exception $e) {
            Log::error('Resend get email failed', [
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
