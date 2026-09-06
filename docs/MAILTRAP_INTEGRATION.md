# 📧 Intégration Mailtrap API

Documentation complète de l'intégration Mailtrap dans le système UV.

## 📚 Table des Matières

-   [Vue d'ensemble](#vue-densemble)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Utilisation](#utilisation)
-   [Commandes Artisan](#commandes-artisan)
-   [API Reference](#api-reference)
-   [Webhooks](#webhooks)
-   [Bonnes Pratiques](#bonnes-pratiques)
-   [Troubleshooting](#troubleshooting)

---

## 🎯 Vue d'ensemble

Mailtrap est une plateforme Email Delivery complète qui permet de :

-   ✅ **Tester les emails** en mode Sandbox (sans envoi réel)
-   ✅ **Envoyer des emails en production** via leur API
-   ✅ **Gérer des templates** d'emails personnalisés
-   ✅ **Suivre les statistiques** (ouvertures, clics, bounces)
-   ✅ **Recevoir des webhooks** pour les événements email

**Documentation officielle :** https://api-docs.mailtrap.io/

---

## 📦 Installation

Le package Mailtrap PHP est déjà installé dans le projet :

```bash
composer require railsware/mailtrap-php symfony/http-client nyholm/psr7
```

**Version installée :** `railsware/mailtrap-php:^3.9`

---

## ⚙️ Configuration

### 1. Créer un compte Mailtrap

1. Inscrivez-vous sur : https://mailtrap.io
2. Créez un **Inbox** :
    - **Testing Inbox** : Pour développement (emails ne sortent pas)
    - **Production Inbox** : Pour production (emails réels)

### 2. Obtenir l'API Token

1. Allez sur : https://mailtrap.io/api-tokens
2. Générez un nouveau token
3. Copiez le token (format: `xxxxxxxxxxxxxxxxxxxxxxxx`)

### 3. Configurer les variables d'environnement

Éditez votre fichier `.env` :

```bash
# ==============================================
# MAILTRAP API CONFIGURATION
# ==============================================
# API Token (from https://mailtrap.io/api-tokens)
MAILTRAP_API_TOKEN=your_actual_api_token_here

# Account ID (from Mailtrap dashboard)
MAILTRAP_ACCOUNT_ID=123456

# Production Inbox ID
MAILTRAP_INBOX_ID=987654

# Sender information
MAILTRAP_FROM_EMAIL="noreply@votreentreprise.ci"
MAILTRAP_FROM_NAME="MODELEC CI"

# Mode Sandbox (true = testing, false = production)
MAILTRAP_SANDBOX=true

# Testing Inbox ID (for development)
MAILTRAP_TESTING_ENABLED=true
MAILTRAP_TESTING_INBOX_ID=654321
```

### 4. Vérifier la configuration

```bash
php artisan mailtrap:check
```

Cette commande affiche :

-   ✅ Statut de chaque paramètre
-   📋 Fonctionnalités activées
-   ⚡ Rate limits configurés
-   💡 Actions recommandées si configuration incomplète

---

## 🚀 Utilisation

### Service MailtrapService

Le service `MailtrapService` est disponible via injection de dépendances :

```php
use App\Services\MailtrapService;

class YourController extends Controller
{
    public function __construct(
        protected MailtrapService $mailtrap
    ) {}
}
```

### 1. Envoyer un email simple

```php
$result = $this->mailtrap->send(
    to: 'client@example.com',
    subject: 'Confirmation de votre commande',
    html: '<h1>Merci pour votre commande !</h1><p>Votre commande #123 a été confirmée.</p>',
    text: 'Merci pour votre commande ! Votre commande #123 a été confirmée.', // Optionnel
    options: [
        'from_email' => 'noreply@votreentreprise.ci',
        'from_name' => 'Votre Entreprise',
        'category' => 'order_confirmation',
        'custom_variables' => [
            'order_id' => '123',
            'customer_id' => '456',
        ],
    ]
);

if ($result['success']) {
    $messageId = $result['message_id'];
    // Email envoyé avec succès
} else {
    $error = $result['error'];
    // Gérer l'erreur
}
```

### 2. Envoyer avec CC et BCC

```php
$result = $this->mailtrap->send(
    to: 'client@example.com',
    subject: 'Facture disponible',
    html: $htmlContent,
    options: [
        'cc' => [
            'manager@votreentreprise.ci' => 'Manager',
            'accounting@votreentreprise.ci' => 'Comptabilité',
        ],
        'bcc' => [
            'archive@votreentreprise.ci' => '',
        ],
        'reply_to' => [
            'email' => 'support@votreentreprise.ci',
            'name' => 'Support Client',
        ],
    ]
);
```

### 3. Envoyer avec template Mailtrap

Créez d'abord un template dans Mailtrap Dashboard, puis :

```php
$result = $this->mailtrap->sendWithTemplate(
    to: 'client@example.com',
    templateUuid: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    variables: [
        'user_name' => 'Jean Dupont',
        'order_number' => '123',
        'total_amount' => '150 000 FCFA',
        'order_date' => '31/12/2025',
    ],
    options: [
        'category' => 'order_confirmation',
    ]
);
```

### 4. Envoyer une notification système

```php
$result = $this->mailtrap->sendNotification(
    to: 'admin@votreentreprise.ci',
    subject: 'Nouvelle commande reçue',
    message: 'Une nouvelle commande #123 a été passée par le client Jean Dupont.\n\nMontant : 150 000 FCFA',
    priority: 'high' // 'high', 'normal', 'low'
);
```

Cette méthode utilise automatiquement le template `emails/notification.blade.php` avec branding de l'entreprise.

### 5. Envoi en masse (bulk)

```php
$recipients = [
    [
        'email' => 'client1@example.com',
        'name' => 'Client 1',
        'variables' => ['order_id' => '123'],
    ],
    [
        'email' => 'client2@example.com',
        'name' => 'Client 2',
        'variables' => ['order_id' => '124'],
    ],
    // ... jusqu'à 500 destinataires par batch
];

$result = $this->mailtrap->sendBulk(
    recipients: $recipients,
    subject: 'Mise à jour importante',
    html: '<h1>Bonjour {name}</h1><p>Votre commande {order_id} est prête.</p>',
    options: [
        'text' => 'Bonjour, votre commande est prête.',
        'category' => 'bulk_notification',
    ]
);

// $result['results'] contient le statut pour chaque email
foreach ($result['results'] as $emailResult) {
    echo "{$emailResult['email']}: " . ($emailResult['success'] ? '✅' : '❌') . "\n";
}
```

**Note :** Le bulk sending doit être activé dans `.env` :

```bash
MAILTRAP_BULK_ENABLED=true
MAILTRAP_BULK_MAX_RECIPIENTS=500
```

---

## 🔧 Commandes Artisan

### mailtrap:check

Vérifier la configuration Mailtrap complète :

```bash
php artisan mailtrap:check
```

**Affiche :**

-   ✅ Statut de chaque paramètre de configuration
-   📦 Fonctionnalités activées/désactivées
-   ⚡ Rate limits configurés
-   💡 Recommandations si configuration incomplète

### mailtrap:test

Tester l'envoi d'email :

```bash
# Email simple
php artisan mailtrap:test your-email@example.com

# Email de notification
php artisan mailtrap:test your-email@example.com --type=notification

# Email avec template
php artisan mailtrap:test your-email@example.com --type=template --template=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

**Options disponibles :**

-   `--type=simple` : Email HTML simple (défaut)
-   `--type=notification` : Email de notification système
-   `--type=template` : Email avec template Mailtrap (requis: `--template=UUID`)

---

## 📖 API Reference

### MailtrapService::send()

```php
public function send(
    string $to,              // Email destinataire
    string $subject,         // Sujet
    string $html,            // Contenu HTML
    ?string $text = null,    // Contenu texte (optionnel)
    array $options = []      // Options supplémentaires
): array
```

**Options disponibles :**

-   `from_email` : Email expéditeur (défaut: config)
-   `from_name` : Nom expéditeur (défaut: config)
-   `to_name` : Nom du destinataire
-   `cc` : Tableau de CC (email => nom)
-   `bcc` : Tableau de BCC (email => nom)
-   `reply_to` : ['email' => '', 'name' => '']
-   `category` : Catégorie pour analytics
-   `custom_variables` : Variables personnalisées (array)

**Retour :**

```php
[
    'success' => true,
    'message_id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'response' => [...],
]
```

### MailtrapService::sendWithTemplate()

```php
public function sendWithTemplate(
    string $to,               // Email destinataire
    string $templateUuid,     // UUID du template
    array $variables = [],    // Variables du template
    array $options = []       // Options supplémentaires
): array
```

### MailtrapService::sendBulk()

```php
public function sendBulk(
    array $recipients,        // Liste de destinataires
    string $subject,          // Sujet
    string $html,             // Contenu HTML
    array $options = []       // Options supplémentaires
): array
```

### MailtrapService::sendNotification()

```php
public function sendNotification(
    string $to,               // Email destinataire
    string $subject,          // Sujet
    string $message,          // Message texte
    string $priority = 'normal'  // 'high', 'normal', 'low'
): array
```

### MailtrapService::checkConfiguration()

```php
public function checkConfiguration(): array
```

**Retour :**

```php
[
    'configured' => true,     // true si configuration complète
    'errors' => [],           // Liste des erreurs
    'config' => [
        'api_token_set' => true,
        'sandbox_mode' => true,
        'inbox_id_set' => true,
        'testing_inbox_set' => true,
    ],
]
```

---

## 🔔 Webhooks

Mailtrap peut envoyer des webhooks pour les événements suivants :

-   `delivery` : Email délivré
-   `soft_bounce` : Bounce temporaire
-   `hard_bounce` : Bounce permanent
-   `spam` : Marqué comme spam
-   `open` : Email ouvert
-   `click` : Lien cliqué
-   `unsubscribe` : Désabonnement

### Activer les webhooks

1. Configurez dans `.env` :

```bash
MAILTRAP_WEBHOOKS_ENABLED=true
MAILTRAP_WEBHOOK_SECRET=your_secret_here
```

2. Créez une route pour recevoir les webhooks :

```php
// routes/api.php
Route::post('/webhooks/mailtrap', [MailtrapWebhookController::class, 'handle']);
```

3. Créez le contrôleur :

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MailtrapWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Vérifier la signature
        $secret = config('mailtrap.webhooks.secret');
        $signature = $request->header('X-Mailtrap-Signature');

        if (!$this->verifySignature($request->getContent(), $signature, $secret)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Traiter l'événement
        $event = $request->input('event');
        $data = $request->all();

        Log::info('Mailtrap webhook received', [
            'event' => $event,
            'data' => $data,
        ]);

        // Dispatch un job pour traiter l'événement
        // ProcessMailtrapWebhook::dispatch($event, $data);

        return response()->json(['status' => 'success']);
    }

    protected function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }
}
```

4. Dans Mailtrap Dashboard :
    - Allez dans Settings → Webhooks
    - Ajoutez l'URL : `https://your-domain.com/api/webhooks/mailtrap`
    - Sélectionnez les événements à recevoir

---

## ✅ Bonnes Pratiques

### 1. Mode Sandbox pour développement

En développement, utilisez toujours le mode Sandbox :

```bash
MAILTRAP_SANDBOX=true
MAILTRAP_TESTING_INBOX_ID=your_testing_inbox
```

Les emails ne sortent pas et vous pouvez les consulter sur https://mailtrap.io/inboxes

### 2. Production : Vérification des domaines

Pour envoyer en production, vérifiez votre domaine dans Mailtrap :

1. Ajoutez votre domaine dans Mailtrap Dashboard
2. Configurez les enregistrements DNS (SPF, DKIM, DMARC)
3. Attendez la vérification (peut prendre 24-48h)

### 3. Rate Limiting

Respectez les limites de Mailtrap :

```bash
# Développement
MAILTRAP_RATE_LIMIT_RPS=10
MAILTRAP_RATE_LIMIT_EPH=1000

# Production (selon votre plan)
MAILTRAP_RATE_LIMIT_RPS=10
MAILTRAP_RATE_LIMIT_EPH=5000
```

### 4. Gestion des erreurs

Toujours vérifier le résultat de l'envoi :

```php
$result = $mailtrap->send(...);

if (!$result['success']) {
    Log::error('Email failed', [
        'to' => $to,
        'error' => $result['error'],
    ]);

    // Retry avec exponential backoff ou queue
    SendEmailJob::dispatch($to, $subject, $html)
        ->delay(now()->addMinutes(5));
}
```

### 5. Logging

Les logs Mailtrap sont dans `storage/logs/laravel.log` :

```bash
tail -f storage/logs/laravel.log | grep Mailtrap
```

Pour désactiver le logging :

```bash
MAILTRAP_LOGGING_ENABLED=false
```

### 6. Templates

Créez des templates réutilisables dans Mailtrap Dashboard :

-   Templates d'emails transactionnels (commandes, factures)
-   Templates de notifications (alertes, rappels)
-   Templates marketing (newsletters)

Avantages :

-   ✅ Design professionnel (éditeur WYSIWYG)
-   ✅ Variables personnalisables
-   ✅ Responsive design automatique
-   ✅ Versioning des templates

### 7. Queues pour emails en masse

Pour les envois en masse, utilisez les queues Laravel :

```php
// Au lieu de :
$mailtrap->sendBulk($recipients, ...);

// Utilisez :
foreach ($recipients as $recipient) {
    SendEmailJob::dispatch($recipient)->delay(now()->addSeconds($i * 0.1));
}
```

---

## 🐛 Troubleshooting

### Erreur : "API Token not configured"

**Solution :**

```bash
# Vérifier que MAILTRAP_API_TOKEN est dans .env
php artisan config:clear
php artisan mailtrap:check
```

### Erreur : "Inbox ID required"

**Solution :**

-   Mode Sandbox : Configurez `MAILTRAP_TESTING_INBOX_ID`
-   Mode Production : Configurez `MAILTRAP_INBOX_ID`

```bash
php artisan mailtrap:check
```

### Erreur 401 : "Unauthorized"

**Causes possibles :**

1. API Token invalide ou expiré
2. Régénérez un nouveau token sur https://mailtrap.io/api-tokens

### Erreur 422 : "Unprocessable Entity"

**Causes possibles :**

1. Email destinataire invalide
2. Champs requis manquants
3. Template UUID invalide

**Vérification :**

```bash
# Tester l'envoi
php artisan mailtrap:test your-email@example.com
```

### Emails non reçus en production

**Vérifications :**

1. `MAILTRAP_SANDBOX=false` dans `.env`
2. Domaine vérifié dans Mailtrap Dashboard
3. Enregistrements DNS configurés (SPF, DKIM, DMARC)
4. Vérifier les logs : `tail -f storage/logs/laravel.log`

### Rate Limit atteint

**Solution :**

1. Augmenter le plan Mailtrap
2. Implémenter des queues avec délai
3. Réduire `MAILTRAP_RATE_LIMIT_RPS`

---

## 📊 Monitoring et Analytics

### Consulter les statistiques

Dans Mailtrap Dashboard :

-   Emails envoyés (jour/semaine/mois)
-   Taux d'ouverture
-   Taux de clic
-   Bounces (soft/hard)
-   Spam reports

### Logs applicatifs

```bash
# Tous les logs Mailtrap
grep "Mailtrap" storage/logs/laravel.log

# Emails envoyés
grep "Mailtrap email sent" storage/logs/laravel.log

# Erreurs uniquement
grep "Mailtrap email failed" storage/logs/laravel.log
```

---

## 🔗 Ressources

-   **Documentation Mailtrap :** https://api-docs.mailtrap.io/
-   **SDK PHP :** https://github.com/railsware/mailtrap-php
-   **Dashboard Mailtrap :** https://mailtrap.io/inboxes
-   **API Tokens :** https://mailtrap.io/api-tokens
-   **Status Page :** https://status.mailtrap.io/

---

## 📞 Support

Pour toute question concernant l'intégration Mailtrap :

1. Consultez cette documentation
2. Exécutez `php artisan mailtrap:check`
3. Vérifiez les logs dans `storage/logs/laravel.log`
4. Contactez le support Mailtrap : https://mailtrap.io/support
