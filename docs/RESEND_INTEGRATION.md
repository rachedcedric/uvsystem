# 📧 Intégration Resend API

Documentation complète de l'intégration Resend dans le système UV.

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

**Resend** est une plateforme moderne d'envoi d'emails transactionnels conçue pour les développeurs. Elle offre :

-   ✅ **API simple et intuitive** (similaire à Stripe)
-   ✅ **99.9% de délivrabilité** garantie
-   ✅ **Plan gratuit généreux** : 100 emails/jour (3000/mois)
-   ✅ **Analytics en temps réel** (opens, clicks, bounces)
-   ✅ **Webhooks** pour tous les événements
-   ✅ **Support React Email** pour templates modernes
-   ✅ **Dashboard élégant** et facile à utiliser

**Documentation officielle :** https://resend.com/docs

**Pourquoi Resend ?**

-   Interface moderne et épurée
-   Intégration Laravel native
-   Excellent pour la production
-   Tarifs très compétitifs
-   Support communautaire actif (Discord)

---

## 📦 Installation

Le package Resend Laravel est déjà installé dans le projet :

```bash
composer require resend/resend-laravel
```

**Version installée :** `resend/resend-laravel:^1.1`

---

## ⚙️ Configuration

### 1. Créer un compte Resend

1. Inscrivez-vous sur : https://resend.com
2. Vérifiez votre domaine : https://resend.com/domains
    - Ajoutez votre domaine (ex: `votreentreprise.ci`)
    - Configurez les enregistrements DNS (SPF, DKIM)
    - Attendez la vérification (quelques minutes)

### 2. Obtenir l'API Key

1. Allez sur : https://resend.com/api-keys
2. Cliquez sur **Create API Key**
3. Donnez un nom (ex: "Production")
4. Sélectionnez les permissions (généralement "Sending access")
5. Copiez la clé (format: `re_xxxxxxxxxxxxx`)

⚠️ **Important** : La clé n'est affichée qu'une seule fois, sauvegardez-la en sécurité !

### 3. Configurer les variables d'environnement

Éditez votre fichier `.env` :

```bash
# ==============================================
# RESEND API CONFIGURATION
# ==============================================
# API Key (from https://resend.com/api-keys)
RESEND_KEY=re_your_actual_api_key_here

# Mail Configuration
MAIL_MAILER=resend
MAIL_FROM_ADDRESS="noreply@votreentreprise.ci"
MAIL_FROM_NAME="UVMASTER"

# Webhook Configuration (optional)
RESEND_WEBHOOK_SECRET=whsec_your_webhook_secret
```

**Note** : Le domaine dans `MAIL_FROM_ADDRESS` doit être vérifié dans Resend !

### 4. Vérifier la configuration

```bash
php artisan resend:check
```

Cette commande affiche :

-   ✅ Statut de chaque paramètre
-   📊 Informations sur les plans Resend
-   💡 Actions recommandées si configuration incomplète

---

## 🚀 Utilisation

### Méthode 1 : Via le Service ResendService (Recommandé)

Le service `ResendService` est disponible via injection de dépendances :

```php
use App\Services\ResendService;

class YourController extends Controller
{
    public function __construct(
        protected ResendService $resend
    ) {}

    public function sendWelcomeEmail()
    {
        $result = $this->resend->send(
            to: 'client@example.com',
            subject: 'Bienvenue !',
            html: '<h1>Bienvenue sur UVMASTER</h1><p>Merci de vous être inscrit.</p>',
            options: [
                'text' => 'Bienvenue sur UVMASTER. Merci de vous être inscrit.',
                'tags' => [
                    ['name' => 'category', 'value' => 'welcome'],
                    ['name' => 'user_type', 'value' => 'agent'],
                ],
            ]
        );

        if ($result['success']) {
            $emailId = $result['id'];
            // Email envoyé avec succès
        } else {
            $error = $result['error'];
            // Gérer l'erreur
        }
    }
}
```

### Méthode 2 : Via Laravel Mail (Standard Laravel)

Utilisez les Mailables Laravel normalement, Resend est le driver :

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeEmail;

Mail::to('client@example.com')->send(new WelcomeEmail($user));
```

### Méthode 3 : Via Resend Facade (API directe)

```php
use Resend\Laravel\Facades\Resend;

Resend::emails()->send([
    'from' => 'UVMASTER <noreply@votreentreprise.ci>',
    'to' => ['client@example.com'],
    'subject' => 'Bienvenue',
    'html' => '<h1>Bienvenue !</h1>',
]);
```

---

## 📝 Exemples d'Utilisation

### 1. Email Simple avec CC et BCC

```php
$result = $this->resend->send(
    to: 'client@example.com',
    subject: 'Facture disponible',
    html: $htmlContent,
    options: [
        'cc' => ['manager@votreentreprise.ci'],
        'bcc' => ['archive@votreentreprise.ci'],
        'reply_to' => 'support@votreentreprise.ci',
        'text' => $textContent,
    ]
);
```

### 2. Email avec Attachments

```php
$result = $this->resend->send(
    to: 'client@example.com',
    subject: 'Votre facture',
    html: $html,
    options: [
        'attachments' => [
            [
                'filename' => 'facture.pdf',
                'content' => base64_encode(file_get_contents($pdfPath)),
            ],
        ],
    ]
);
```

### 3. Notification Système

```php
$result = $this->resend->sendNotification(
    to: 'admin@votreentreprise.ci',
    subject: 'Nouvelle commande reçue',
    message: 'Une nouvelle commande #123 a été passée par Jean Dupont.\n\nMontant : 150 000 FCFA',
    priority: 'high' // 'high', 'normal', 'low'
);
```

### 4. Envoi en Masse

```php
$emails = [
    [
        'to' => 'client1@example.com',
        'subject' => 'Newsletter Janvier',
        'html' => $html,
        'options' => ['tags' => [['name' => 'campaign', 'value' => 'jan2025']]],
    ],
    [
        'to' => 'client2@example.com',
        'subject' => 'Newsletter Janvier',
        'html' => $html,
        'options' => ['tags' => [['name' => 'campaign', 'value' => 'jan2025']]],
    ],
];

$result = $this->resend->sendBulk($emails);

foreach ($result['results'] as $emailResult) {
    echo "{$emailResult['to']}: " . ($emailResult['success'] ? '✅' : '❌') . "\n";
}
```

### 5. Utiliser un Mailable Laravel

```php
use App\Mail\OrderShipped;

$result = $this->resend->sendMailable(
    to: 'client@example.com',
    mailable: new OrderShipped($order),
    options: [
        'tags' => [
            ['name' => 'category', 'value' => 'order'],
            ['name' => 'order_id', 'value' => $order->id],
        ],
    ]
);
```

---

## 🔧 Commandes Artisan

### resend:check

Vérifier la configuration Resend complète :

```bash
php artisan resend:check
```

**Affiche :**

-   ✅ Statut de chaque paramètre de configuration
-   📊 Informations sur les plans Resend
-   💡 Recommandations si configuration incomplète

### resend:test

Tester l'envoi d'email :

```bash
# Email simple
php artisan resend:test your-email@example.com

# Email de notification
php artisan resend:test your-email@example.com --type=notification
```

**Options disponibles :**

-   `--type=simple` : Email HTML simple (défaut)
-   `--type=notification` : Email de notification système

---

## 📖 API Reference

### ResendService::send()

```php
public function send(
    string|array $to,        // Email(s) destinataire(s)
    string $subject,         // Sujet
    string $html,            // Contenu HTML
    array $options = []      // Options supplémentaires
): array
```

**Options disponibles :**

-   `from` : Email expéditeur (défaut: config)
-   `from_name` : Nom expéditeur (défaut: config)
-   `text` : Contenu texte brut
-   `cc` : Array de CC
-   `bcc` : Array de BCC
-   `reply_to` : Email de réponse
-   `attachments` : Array d'attachments ([`filename`, `content`])
-   `tags` : Array de tags ([`name`, `value`]) pour analytics

**Retour :**

```php
[
    'success' => true,
    'id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'response' => [...],
]
```

### ResendService::sendMailable()

```php
public function sendMailable(
    string|array $to,                     // Email(s) destinataire(s)
    \Illuminate\Mail\Mailable $mailable,  // Instance de Mailable
    array $options = []                   // Options supplémentaires
): array
```

### ResendService::sendNotification()

```php
public function sendNotification(
    string|array $to,        // Email(s) destinataire(s)
    string $subject,         // Sujet
    string $message,         // Message texte
    string $priority = 'normal'  // 'high', 'normal', 'low'
): array
```

### ResendService::sendBulk()

```php
public function sendBulk(
    array $emails            // Liste d'emails avec leur contenu
): array
```

**Format des emails :**

```php
[
    [
        'to' => 'email@example.com',
        'subject' => 'Sujet',
        'html' => '<h1>Contenu</h1>',
        'options' => [...],
    ],
    // ...
]
```

### ResendService::checkConfiguration()

```php
public function checkConfiguration(): array
```

**Retour :**

```php
[
    'configured' => true,
    'errors' => [],
    'config' => [
        'api_key_set' => true,
        'from_address' => 'noreply@example.com',
        'from_name' => 'App Name',
        'mailer' => 'resend',
    ],
]
```

### ResendService::getEmail()

```php
public function getEmail(string $emailId): ?array
```

Récupère les détails d'un email envoyé via son ID.

---

## 🔔 Webhooks

Resend peut envoyer des webhooks pour tous les événements d'envoi d'emails, permettant un tracking en temps réel.

### Événements disponibles

-   `email.sent` : Email envoyé avec succès
-   `email.delivered` : Email délivré au destinataire
-   `email.delivery_delayed` : Délai de livraison détecté
-   `email.bounced` : Bounce (permanent ou temporaire)
-   `email.complained` : Marqué comme spam
-   `email.opened` : Email ouvert (tracking activé)
-   `email.clicked` : Lien cliqué dans l'email

### Configuration complète

#### 1. Configurer l'URL dans Resend Dashboard

1. Allez sur https://resend.com/webhooks
2. Cliquez sur **Create Webhook**
3. Renseignez l'URL : **`https://uvmaster.ci/api/webhooks/resend`**
4. Sélectionnez les événements à tracker (tous recommandés)
5. Copiez le **Signing Secret** (format: `whsec_xxxxx`)

#### 2. Ajouter le secret dans `.env`

Le secret webhook est **déjà configuré** dans le projet :

```bash
# Webhook Configuration (from https://resend.com/webhooks)
# URL: https://uvmaster.ci/api/webhooks/resend
RESEND_WEBHOOK_SECRET=whsec_votre_secret_webhook
```

#### 3. Route webhook (✅ déjà créée)

La route est automatiquement enregistrée dans `routes/api.php` :

```php
// Webhook Resend (aucune authentification requise)
Route::post('/webhooks/resend', [WebhookController::class, 'resend'])
    ->name('webhooks.resend');
```

#### 4. Contrôleur webhook (✅ déjà créé)

Le contrôleur `WebhookController` gère automatiquement :

-   ✅ **Vérification de signature Svix** (sécurité maximale)
-   ✅ **Traitement de tous les événements** (sent, delivered, bounced, etc.)
-   ✅ **Logging automatique** dans `storage/logs/laravel.log`
-   ✅ **ActivityLog** pour audit complet
-   ✅ **Cache des statistiques** (90 jours de rétention)

**Fichier** : `app/Http/Controllers/Api/WebhookController.php`

#### 5. Exclusion CSRF (✅ déjà configuré)

Les webhooks sont automatiquement exclus de la vérification CSRF dans `bootstrap/app.php` :

```php
// Exclure les webhooks de la vérification CSRF
$middleware->validateCsrfTokens(except: [
    'api/webhooks/*',
]);
```

### Vérification de signature Svix

La sécurité du webhook utilise le protocole **Svix** (standard industrie) :

1. Resend envoie 3 headers :

    - `svix-signature` : Signature HMAC SHA-256
    - `svix-timestamp` : Timestamp de l'événement
    - `svix-id` : ID unique de l'événement

2. Le contrôleur vérifie automatiquement :

    ```php
    $signedContent = "{$id}.{$timestamp}.{$payload}";
    $expectedSignature = base64_encode(
        hash_hmac('sha256', $signedContent, $secret, true)
    );
    ```

3. Si la signature est invalide → **401 Unauthorized**

**En développement local** : La vérification est désactivée si `RESEND_WEBHOOK_SECRET` est vide.

### Tester les webhooks

#### Option 1 : Svix Play (Recommandé)

1. Installez Svix CLI :

    ```bash
    npm install -g svix-cli
    ```

2. Créez un tunnel vers votre serveur local :

    ```bash
    svix listen http://localhost:8000/api/webhooks/resend \
        --secret whsec_votre_secret_webhook
    ```

3. Envoyez un email test depuis Resend Dashboard

#### Option 2 : ngrok + Test manuel

1. Lancez ngrok :

    ```bash
    ngrok http 8000
    ```

2. Configurez l'URL ngrok dans Resend Dashboard (temporairement)

3. Envoyez un email test :

    ```bash
    php artisan resend:test admin@votreentreprise.ci
    ```

4. Vérifiez les logs :
    ```bash
    tail -f storage/logs/laravel.log | grep "Resend webhook"
    ```

### Exemples de payloads webhook

#### `email.sent`

```json
{
    "type": "email.sent",
    "created_at": "2025-12-31T12:00:00.000Z",
    "data": {
        "email_id": "re_abc123",
        "from": "noreply@votreentreprise.ci",
        "to": ["agent@example.com"],
        "subject": "Nouvelle transaction UV",
        "created_at": "2025-12-31T12:00:00.000Z"
    }
}
```

#### `email.delivered`

```json
{
    "type": "email.delivered",
    "created_at": "2025-12-31T12:00:05.000Z",
    "data": {
        "email_id": "re_abc123",
        "to": "agent@example.com"
    }
}
```

#### `email.bounced`

```json
{
    "type": "email.bounced",
    "created_at": "2025-12-31T12:00:10.000Z",
    "data": {
        "email_id": "re_abc123",
        "to": "invalid@example.com",
        "bounce_type": "hard",
        "reason": "Mailbox does not exist"
    }
}
```

### Actions automatiques du webhook

Le contrôleur exécute automatiquement :

| Événement          | Action                                                                                                        |
| ------------------ | ------------------------------------------------------------------------------------------------------------- |
| `email.sent`       | ✅ Incrémenter compteur "sent"                                                                                |
| `email.delivered`  | ✅ Incrémenter compteur "delivered"                                                                           |
| `email.bounced`    | ⚠️ Logger erreur + incrémenter "bounced" <br> 🔜 TODO: Marquer email invalide en DB                           |
| `email.complained` | 🚨 Logger spam complaint + incrémenter "complained" <br> 🔜 TODO: Désinscrire automatiquement + alerter admin |
| `email.opened`     | 📊 Incrémenter compteur "opened"                                                                              |
| `email.clicked`    | 📊 Incrémenter compteur "clicked"                                                                             |

### Statistiques d'emails

Les statistiques sont automatiquement stockées dans **Redis Cache** avec clés :

```
email_stats:sent:2025-12-31
email_stats:delivered:2025-12-31
email_stats:bounced:2025-12-31
email_stats:opened:2025-12-31
email_stats:clicked:2025-12-31
```

**Rétention** : 90 jours

**Récupération** :

```php
$sentToday = Cache::get('email_stats:sent:' . now()->format('Y-m-d'), 0);
$deliveredToday = Cache::get('email_stats:delivered:' . now()->format('Y-m-d'), 0);
```

### Monitoring des webhooks

#### Vérifier les logs

```bash
# Tous les webhooks reçus
tail -f storage/logs/laravel.log | grep "webhook"

# Uniquement Resend
tail -f storage/logs/laravel.log | grep "Resend webhook"

# Erreurs de signature
tail -f storage/logs/laravel.log | grep "signature verification failed"
```

#### Vérifier ActivityLog

```php
// Derniers 50 webhooks reçus
Activity::where('description', 'LIKE', 'Webhook Resend:%')
    ->latest()
    ->take(50)
    ->get();
```

#### Dashboard Resend

Allez sur https://resend.com/webhooks pour voir :

-   📊 Taux de succès des webhooks
-   ⏱️ Latence moyenne
-   ❌ Erreurs récentes (4xx, 5xx)
-   🔄 Retry automatique (en cas d'échec)

---

## ✅ Bonnes Pratiques

### 1. Vérification du domaine

**Toujours vérifier votre domaine dans Resend avant d'envoyer en production :**

-   Allez sur https://resend.com/domains
-   Ajoutez votre domaine
-   Configurez les enregistrements DNS (SPF, DKIM, DMARC)
-   Attendez la vérification

Sans vérification, vous ne pouvez utiliser que `onboarding@resend.dev` (limité à 100 emails/jour).

### 2. Utilisation des Tags

Utilisez des tags pour organiser vos emails et analyser les performances :

```php
'tags' => [
    ['name' => 'category', 'value' => 'transactional'],
    ['name' => 'user_type', 'value' => 'agent'],
    ['name' => 'campaign', 'value' => 'welcome_series'],
]
```

### 3. Gestion des Erreurs

Toujours vérifier le résultat de l'envoi :

```php
$result = $resend->send(...);

if (!$result['success']) {
    Log::error('Email failed', [
        'to' => $to,
        'error' => $result['error'],
    ]);

    // Retry avec queue
    SendEmailJob::dispatch($to, $subject, $html)
        ->delay(now()->addMinutes(5));
}
```

### 4. Rate Limiting

Resend applique automatiquement les rate limits selon votre plan :

-   **Gratuit** : 100 emails/jour
-   **Pro** : 50 000 emails/mois
-   **Business** : 100 000 emails/mois

Pour éviter les limites, utilisez des queues Laravel :

```php
// Au lieu de :
foreach ($users as $user) {
    $resend->send(...);
}

// Utilisez :
foreach ($users as $user) {
    SendEmailJob::dispatch($user)->delay(now()->addSeconds($i * 0.5));
}
```

### 5. Monitoring

Consultez régulièrement le dashboard Resend :

-   https://resend.com/emails (liste des emails)
-   https://resend.com/analytics (statistiques)
-   Vérifiez les bounces et spam reports

---

## 🐛 Troubleshooting

### Erreur : "API key not configured"

**Solution :**

```bash
# Vérifier que RESEND_KEY est dans .env
php artisan config:clear
php artisan resend:check
```

### Erreur 403 : "Forbidden"

**Causes possibles :**

1. API Key invalide ou révoquée
2. Régénérez une nouvelle clé sur https://resend.com/api-keys

### Erreur 400 : "Email not verified"

**Cause** : Le domaine de `MAIL_FROM_ADDRESS` n'est pas vérifié.

**Solution :**

1. Allez sur https://resend.com/domains
2. Vérifiez votre domaine
3. Ou utilisez `onboarding@resend.dev` pour tester (limité)

### Emails non reçus

**Vérifications :**

1. Domaine vérifié dans Resend Dashboard
2. Enregistrements DNS configurés (SPF, DKIM)
3. Vérifier les logs : `tail -f storage/logs/laravel.log | grep Resend`
4. Consulter le dashboard Resend pour voir le statut

### Rate Limit atteint

**Solution :**

1. Upgradez votre plan sur https://resend.com/settings/billing
2. Implémentez des queues avec délai
3. Réduisez la fréquence d'envoi

---

## 🔗 Ressources

-   **Documentation Resend :** https://resend.com/docs
-   **Laravel Integration :** https://resend.com/docs/send-with-laravel
-   **Dashboard Resend :** https://resend.com/emails
-   **API Keys :** https://resend.com/api-keys
-   **Domains :** https://resend.com/domains
-   **Webhooks :** https://resend.com/webhooks
-   **GitHub :** https://github.com/resendlabs/resend-laravel
-   **Discord Community :** https://resend.com/discord
-   **Status Page :** https://resend.statuspage.io/

---

## 📞 Support

Pour toute question concernant l'intégration Resend :

1. Consultez cette documentation
2. Exécutez `php artisan resend:check`
3. Vérifiez les logs dans `storage/logs/laravel.log`
4. Rejoignez le Discord Resend : https://resend.com/discord
5. Contactez le support : https://resend.com/support
