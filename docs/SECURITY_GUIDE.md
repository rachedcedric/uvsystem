# 🔒 GUIDE DE SÉCURITÉ - SYSTÈME UV

## Table des Matières

1. [Vue d'ensemble](#vue-densemble)
2. [Architecture de Sécurité](#architecture-de-sécurité)
3. [Configuration](#configuration)
4. [Authentification & Autorisation](#authentification--autorisation)
5. [Sécurité des Transactions](#sécurité-des-transactions)
6. [Protection contre la Fraude](#protection-contre-la-fraude)
7. [Audit & Logging](#audit--logging)
8. [Chiffrement](#chiffrement)
9. [Sauvegarde & Récupération](#sauvegarde--récupération)
10. [Commandes de Sécurité](#commandes-de-sécurité)
11. [Bonnes Pratiques](#bonnes-pratiques)
12. [Conformité RGPD](#conformité-rgpd)

---

## Vue d'ensemble

Le système de gestion UV implémente une **sécurité multi-couches** adaptée aux exigences d'une application de gestion financière :

### Principes de Sécurité

✅ **Intégrité des Données** : Les transactions financières sont IMMUABLES (soft delete uniquement)  
✅ **Traçabilité Complète** : Chaque action critique est auditée avec ActivityLog  
✅ **Signature Numérique** : Toutes les transactions sont signées (HMAC SHA-256)  
✅ **Détection de Fraude** : Analyse en temps réel des patterns suspects  
✅ **Chiffrement** : Données sensibles chiffrées en base (AES-256-CBC)  
✅ **Authentification Renforcée** : 2FA obligatoire pour Master/Admin  
✅ **Rate Limiting** : Protection contre les attaques par force brute  
✅ **Vérification d'Intégrité** : Contrôles automatiques et manuels

---

## Architecture de Sécurité

### Couches de Protection

```
┌─────────────────────────────────────────────────────────────┐
│                    COUCHE APPLICATION                        │
│  • Middleware CheckTransactionSecurity                      │
│  • SecurityService (détection fraude)                       │
│  • Observers (UvTransactionSecurityObserver)                │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    COUCHE DONNÉES                            │
│  • Signatures de transactions (HMAC SHA-256)                │
│  • Chiffrement des champs sensibles (AES-256-CBC)           │
│  • Soft Deletes uniquement                                  │
│  • Vérification d'intégrité périodique                      │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    COUCHE AUDIT                              │
│  • ActivityLog (Spatie) - Immutable                         │
│  • Security Reports (journaliers/hebdomadaires)             │
│  • Alertes temps réel (email/SMS/Slack)                     │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    COUCHE INFRASTRUCTURE                     │
│  • Rate Limiting (API & Financial Operations)               │
│  • IP Whitelisting (optionnel)                              │
│  • HTTPS forcé (production)                                 │
│  • Sauvegardes chiffrées (quotidiennes)                     │
└─────────────────────────────────────────────────────────────┘
```

### Composants Principaux

| Composant                         | Rôle                                   | Fichier                                            |
| --------------------------------- | -------------------------------------- | -------------------------------------------------- |
| **SecurityService**               | Service central de sécurité            | `app/Services/SecurityService.php`                 |
| **CheckTransactionSecurity**      | Middleware protection transactions     | `app/Http/Middleware/CheckTransactionSecurity.php` |
| **UvTransactionSecurityObserver** | Observation sécurisée des transactions | `app/Observers/UvTransactionSecurityObserver.php`  |
| **Config Security**               | Configuration centralisée              | `config/security.php`                              |

---

## Configuration

### Fichier `config/security.php`

Le fichier de configuration centralise tous les paramètres de sécurité.

#### 1. Authentification & Sessions

```php
'authentication' => [
    'max_login_attempts' => 3,           // Tentatives avant blocage
    'lockout_duration' => 15,            // Minutes de blocage
    'session_lifetime' => 30,            // Durée session (minutes)
    'session_idle_timeout' => 15,        // Timeout inactivité (minutes)
    '2fa_enabled' => true,               // 2FA activé
    '2fa_required_for_roles' => ['master', 'admin'],
],
```

**Variables d'environnement** :

```bash
SECURITY_MAX_LOGIN_ATTEMPTS=3
SECURITY_LOCKOUT_DURATION=15
SECURITY_SESSION_LIFETIME=30
SECURITY_SESSION_IDLE_TIMEOUT=15
SECURITY_2FA_ENABLED=true
```

#### 2. Transactions Financières

```php
'transactions' => [
    // Signature numérique
    'signature_enabled' => true,
    'signature_algorithm' => 'sha256',

    // Double validation pour montants élevés
    'dual_validation' => [
        'enabled' => true,
        'threshold' => 1000000,          // 1M FCFA
        'required_roles' => ['master', 'admin'],
    ],

    // Vérification d'intégrité automatique
    'integrity_check' => [
        'enabled' => true,
        'check_interval' => 3600,        // 1 heure
    ],

    // Limites journalières
    'daily_limits' => [
        'agent' => 10000000,             // 10M FCFA
        'master' => null,                // Pas de limite
    ],
],
```

**Variables d'environnement** :

```bash
SECURITY_TRANSACTION_SIGNATURE=true
SECURITY_DUAL_VALIDATION=true
SECURITY_DUAL_VALIDATION_THRESHOLD=1000000
SECURITY_AGENT_DAILY_LIMIT=10000000
```

#### 3. Audit & Logging

```php
'audit' => [
    'level' => 'strict',                 // strict, normal, minimal

    'critical_actions' => [
        'uv_distribution',
        'remboursement',
        'agent_creation',
        'agent_deletion',
        'solde_modification',
        'role_assignment',
        'settings_change',
    ],

    'log_retention' => 365,              // Jours
    'suspicious_activity_notification' => true,
    'notification_emails' => ['admin@votreentreprise.ci'],
],
```

**Variables d'environnement** :

```bash
SECURITY_AUDIT_LEVEL=strict
SECURITY_LOG_RETENTION=365
SECURITY_NOTIFICATION_EMAILS=admin@votreentreprise.ci,security@votreentreprise.ci
```

#### 4. Détection de Fraude

```php
'fraud_detection' => [
    'enabled' => true,

    'suspicious_patterns' => [
        // Transactions multiples en peu de temps
        'multiple_transactions_short_time' => [
            'enabled' => true,
            'threshold' => 5,            // Transactions
            'timeframe' => 300,          // Secondes (5 minutes)
        ],

        // Montant inhabituel
        'unusual_amount' => [
            'enabled' => true,
            'deviation_factor' => 3,     // 3x la moyenne
        ],

        // Transactions hors horaires
        'off_hours_transactions' => [
            'enabled' => true,
            'allowed_hours' => [6, 22],  // 6h-22h
        ],
    ],

    // Actions automatiques
    'auto_block' => [
        'enabled' => true,
        'block_duration' => 60,          // Minutes
        'notify_admin' => true,
    ],
],
```

**Variables d'environnement** :

```bash
SECURITY_FRAUD_DETECTION=true
```

#### 5. Chiffrement

```php
'encryption' => [
    'enabled' => true,

    'encrypted_fields' => [
        'agents' => ['telephone', 'adresse'],
        'fournisseurs' => ['telephone', 'email'],
        'users' => ['email', 'telephone'],
    ],

    'algorithm' => 'AES-256-CBC',
],
```

**Variables d'environnement** :

```bash
SECURITY_ENCRYPTION_ENABLED=true
```

#### 6. Protection API

```php
'api' => [
    'rate_limit' => [
        'enabled' => true,
        'default' => 60,                 // Requêtes/minute
        'authenticated' => 120,
        'financial_operations' => 30,    // Plus strict
    ],

    'ip_whitelist' => [
        'enabled' => false,
        'ips' => [],
    ],

    'api_key_rotation' => [
        'enabled' => true,
        'rotation_period' => 30,         // Jours
    ],
],
```

**Variables d'environnement** :

```bash
SECURITY_API_RATE_LIMIT=60
SECURITY_API_RATE_LIMIT_AUTH=120
SECURITY_IP_WHITELIST_ENABLED=false
SECURITY_WHITELISTED_IPS=192.168.1.100,10.0.0.5
```

---

## Authentification & Autorisation

### 1. Authentification à Deux Facteurs (2FA)

Le 2FA est **obligatoire** pour les rôles Master et Admin.

**Configuration** :

```php
// config/security.php
'authentication' => [
    '2fa_enabled' => env('SECURITY_2FA_ENABLED', true),
    '2fa_required_for_roles' => ['master', 'admin'],
],
```

**Implémentation avec Laravel Google2FA** :

```bash
composer require pragmarx/google2fa-laravel
```

### 2. Gestion des Tentatives de Connexion

**Méthodes SecurityService** :

```php
use App\Services\SecurityService;

$security = app(SecurityService::class);

// Vérifier les tentatives
$status = $security->checkLoginAttempts('user@example.com');
// Returns: ['locked' => false, 'attempts' => 1, 'remaining_attempts' => 2]

// Enregistrer échec
$security->recordFailedLogin('user@example.com', request()->ip());

// Réinitialiser après succès
$security->clearLoginAttempts('user@example.com');
```

**Exemple d'intégration dans LoginController** :

```php
public function login(Request $request)
{
    $identifier = $request->email;
    $security = app(SecurityService::class);

    // Vérifier si le compte est verrouillé
    $status = $security->checkLoginAttempts($identifier);
    if ($status['locked']) {
        return back()->withErrors([
            'email' => sprintf(
                'Compte verrouillé pendant %d minutes après trop de tentatives.',
                $status['remaining_time']
            ),
        ]);
    }

    // Tentative de connexion
    if (Auth::attempt($request->only('email', 'password'))) {
        $security->clearLoginAttempts($identifier);
        return redirect()->intended('dashboard');
    }

    // Échec
    $security->recordFailedLogin($identifier, $request->ip());
    return back()->withErrors(['email' => 'Identifiants incorrects.']);
}
```

### 3. Politique de Mot de Passe

**Configuration** :

```php
'password' => [
    'min_length' => 12,
    'require_uppercase' => true,
    'require_lowercase' => true,
    'require_numbers' => true,
    'require_special_chars' => true,
    'expires_in_days' => 90,
    'history_count' => 5,                // Ne pas réutiliser les 5 derniers
    'prevent_common_passwords' => true,
],
```

**Variables d'environnement** :

```bash
SECURITY_PASSWORD_MIN_LENGTH=12
SECURITY_PASSWORD_EXPIRY=90
```

**Validation du mot de passe** :

```php
$security = app(SecurityService::class);
$validation = $security->validatePasswordStrength('MyP@ssw0rd123');

if (!$validation['valid']) {
    // $validation['errors'] contient les erreurs
    foreach ($validation['errors'] as $error) {
        echo $error . "\n";
    }
}

echo "Force: " . $validation['strength']; // "Fort", "Moyen", etc.
```

---

## Sécurité des Transactions

### 1. Signature Numérique

Chaque transaction reçoit une signature **HMAC SHA-256** calculée à partir de ses données.

**Génération automatique via Observer** :

```php
// app/Observers/UvTransactionSecurityObserver.php

public function creating(UvTransaction $transaction): void
{
    if (config('security.transactions.signature_enabled')) {
        $data = [
            'agent_id' => $transaction->agent_id,
            'quantity_uv' => $transaction->quantity_uv,
            'prix_unitaire' => $transaction->prix_unitaire,
            'montant_total' => $transaction->montant_total,
            'created_at' => now()->toIso8601String(),
        ];

        $transaction->signature = $this->securityService->generateTransactionSignature($data);
    }
}
```

**Vérification manuelle** :

```php
$security = app(SecurityService::class);

$data = [
    'agent_id' => 1,
    'quantity_uv' => 100,
    'prix_unitaire' => 500,
    'montant_total' => 50000,
    'created_at' => '2025-12-31T10:00:00Z',
];

$signature = $security->generateTransactionSignature($data);
$isValid = $security->verifyTransactionSignature($data, $signature);
```

### 2. Immuabilité des Transactions

**Interdiction de modification** :

```php
public function updating(UvTransaction $transaction): void
{
    if ($transaction->isDirty(['montant_total', 'quantity_uv', 'prix_unitaire'])) {
        throw new \Exception(
            'Les transactions financières ne peuvent pas être modifiées. ' .
            'Utilisez une annulation et une nouvelle transaction.'
        );
    }
}
```

**Soft Delete uniquement** :

```php
public function deleting(UvTransaction $transaction): void
{
    if ($transaction->isForceDeleting()) {
        throw new \Exception(
            'Les transactions financières ne peuvent jamais être supprimées physiquement.'
        );
    }
}
```

### 3. Double Validation

Pour les montants élevés (>= 1M FCFA par défaut), une **double validation** est requise.

```php
$security = app(SecurityService::class);

if ($security->requiresDualValidation(1500000)) {
    // Nécessite validation d'un Master ou Admin
    if (!auth()->user()->hasRole(['master', 'admin'])) {
        throw new \Exception(
            'Ce montant nécessite une validation par un administrateur.'
        );
    }
}
```

### 4. Limites Journalières

```php
// Vérifier la limite journalière dans le service de distribution
$dailyLimit = config('security.transactions.daily_limits.agent', 10000000);
$dailyTotal = UvTransaction::where('agent_id', $agentId)
    ->whereDate('created_at', today())
    ->sum('montant_total');

if (($dailyTotal + $montant) > $dailyLimit) {
    throw new \Exception(
        sprintf(
            'Limite journalière dépassée. Limite: %s FCFA, Déjà utilisé: %s FCFA',
            number_format($dailyLimit, 0, ',', ' '),
            number_format($dailyTotal, 0, ',', ' ')
        )
    );
}
```

---

## Protection contre la Fraude

### Détection de Patterns Suspects

Le **SecurityService** analyse chaque transaction pour détecter :

1. **Transactions multiples en peu de temps**
2. **Montants inhabituels** (déviation > 3x la moyenne)
3. **Transactions hors horaires** (avant 6h ou après 22h)
4. **Dépassement de limite journalière**

**Utilisation** :

```php
$security = app(SecurityService::class);
$detection = $security->detectSuspiciousTransaction($transaction, auth()->user());

if ($detection['suspicious']) {
    // $detection contient:
    // - 'risk_score' (0-100)
    // - 'flags' (array de patterns détectés)
    // - 'recommended_action' (allow, flag_for_review, require_2fa_and_alert, block_and_alert)

    switch ($detection['recommended_action']) {
        case 'block_and_alert':
            throw new \Exception('Transaction bloquée pour raisons de sécurité.');

        case 'require_2fa_and_alert':
            // Demander 2FA
            break;

        case 'flag_for_review':
            // Logger pour revue manuelle
            break;
    }
}
```

**Actions automatiques** :

Le système peut **bloquer automatiquement** une transaction si :

-   Score de risque >= 80
-   `config('security.fraud_detection.auto_block.enabled')` est `true`

---

## Audit & Logging

### ActivityLog (Spatie)

Toutes les actions critiques sont journalisées de manière **IMMUABLE**.

**Actions auditées** :

-   Distribution UV
-   Remboursement
-   Création/suppression d'agent
-   Modification de solde
-   Attribution de rôle
-   Changement de paramètres
-   Tentatives de connexion échouées
-   Activités suspectes

**Exemple d'utilisation** :

```php
activity()
    ->performedOn($transaction)
    ->causedBy(auth()->user())
    ->withProperties([
        'transaction_id' => $transaction->id,
        'montant' => $transaction->montant_total,
        'signature' => $transaction->signature,
    ])
    ->log('uv_transaction_created');
```

**Requêter les logs** :

```php
use Spatie\Activitylog\Models\Activity;

// Logs d'un utilisateur
$logs = Activity::causedBy(auth()->user())->get();

// Logs sur un modèle
$logs = Activity::forSubject($transaction)->get();

// Logs par description
$suspiciousLogs = Activity::where('description', 'suspicious_activity')->get();
```

---

## Chiffrement

### Champs Chiffrés

Configuration des champs sensibles à chiffrer :

```php
'encryption' => [
    'encrypted_fields' => [
        'agents' => ['telephone', 'adresse'],
        'fournisseurs' => ['telephone', 'email'],
        'users' => ['email', 'telephone'],
    ],
],
```

### Cast de Chiffrement dans les Models

```php
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Agent extends Model
{
    // Chiffrer automatiquement avec le cast 'encrypted'
    protected $casts = [
        'telephone' => 'encrypted',
        'adresse' => 'encrypted',
    ];
}
```

### Chiffrement Manuel

```php
$security = app(SecurityService::class);

// Chiffrer
$encrypted = $security->encryptSensitiveData('Données sensibles');

// Déchiffrer
$decrypted = $security->decryptSensitiveData($encrypted);
```

---

## Sauvegarde & Récupération

### Configuration

```php
'backup' => [
    'enabled' => true,
    'frequency' => 'daily',
    'retention' => 30,                   // Jours
    'encrypt_backups' => true,
    'backup_password' => env('BACKUP_ARCHIVE_PASSWORD'),
    'verify_integrity' => true,
],
```

### Package Laravel Backup (Spatie)

```bash
composer require spatie/laravel-backup
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
```

**Configuration dans `.env`** :

```bash
SECURITY_BACKUP_ENABLED=true
BACKUP_ARCHIVE_PASSWORD=your_secure_password_here
```

**Lancer une sauvegarde** :

```bash
php artisan backup:run
```

**Programmer les sauvegardes automatiques** (`app/Console/Kernel.php`) :

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('backup:run --only-db')->daily()->at('02:00');
    $schedule->command('backup:clean')->daily()->at('03:00');
}
```

---

## Commandes de Sécurité

### 1. Rapport de Sécurité

Génère un rapport complet sur la période spécifiée.

```bash
# Rapport des 30 derniers jours (défaut)
php artisan security:report

# Période personnalisée
php artisan security:report --start=2025-01-01 --end=2025-12-31

# Format JSON
php artisan security:report --output=json
```

**Contenu du rapport** :

-   Tentatives de connexion échouées
-   Activités suspectes
-   Transactions bloquées
-   Problèmes d'intégrité
-   Recommandations

**Automatisation** (dans `Kernel.php`) :

```php
protected function schedule(Schedule $schedule)
{
    // Rapport quotidien envoyé par email
    $schedule->command('security:report --output=json')
        ->daily()
        ->at('07:00')
        ->emailOutputTo('admin@votreentreprise.ci');
}
```

### 2. Vérification d'Intégrité

Vérifie la cohérence des transactions et soldes.

```bash
# Vérification seule
php artisan security:verify-integrity

# Vérification + tentative de correction automatique
php artisan security:verify-integrity --fix
```

**Ce qui est vérifié** :

-   Signatures de transactions
-   Cohérence des soldes agents
-   Transactions manquantes
-   Doublons de transactions

**Exemple de sortie** :

```
🔍 Vérification de l'intégrité des données financières...

⚠️  2 problème(s) d'intégrité détecté(s)

Type de problème: Écart de solde agent
┌──────────┬─────────────┬────────────┬────────────┬───────────┐
│ ID Agent │ Nom         │ Attendu    │ Actuel     │ Écart     │
├──────────┼─────────────┼────────────┼────────────┼───────────┤
│ 5        │ Agent Test  │ 150,000    │ 145,000    │ -5,000    │
└──────────┴─────────────┴────────────┴────────────┴───────────┘

💡 Pour tenter une correction automatique, exécutez:
   php artisan security:verify-integrity --fix
```

**Automatisation** :

```php
// Vérification quotidienne à 4h du matin
$schedule->command('security:verify-integrity')
    ->daily()
    ->at('04:00');
```

---

## Bonnes Pratiques

### 1. Environnement de Production

✅ **HTTPS obligatoire** :

```bash
FORCE_HTTPS=true
```

✅ **Désactiver le debug** :

```bash
APP_DEBUG=false
```

✅ **Mode production** :

```bash
APP_ENV=production
```

✅ **Clé d'application robuste** :

```bash
php artisan key:generate --force
```

✅ **Cache des configurations** :

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 2. Gestion des Logs

✅ **Rotation automatique** : Configurer Logrotate

```bash
# /etc/logrotate.d/laravel
/path/to/project/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
}
```

✅ **Monitoring des logs** : Utiliser un service comme Sentry, Papertrail, ou Logtail

```bash
SENTRY_LARAVEL_DSN=https://xxxxx@sentry.io/xxxxx
```

### 3. Base de Données

✅ **Sauvegardes automatiques quotidiennes**

✅ **Utilisateur DB avec privilèges minimaux** :

```sql
GRANT SELECT, INSERT, UPDATE ON database.* TO 'app_user'@'localhost';
-- PAS de DELETE ou DROP
```

✅ **Connexion SSL** :

```bash
DB_SSLMODE=require
```

### 4. Serveur Web

✅ **Cacher le répertoire .env** :

```apache
<Files .env>
    Require all denied
</Files>
```

✅ **Désactiver l'indexation de répertoire**

✅ **Headers de sécurité** :

```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Strict-Transport-Security: max-age=31536000
```

### 5. Code

✅ **Toujours utiliser DB::transaction() pour les opérations financières**

```php
DB::transaction(function () use ($agent, $amount) {
    // Créer transaction
    $transaction = UvTransaction::create([...]);

    // Mettre à jour solde
    $agent->solde()->update([...]);

    // Logger
    activity()->log('transaction_created');
});
```

✅ **Jamais de calculs manuels de solde** : Toujours via Observers

✅ **Validation stricte des inputs** :

```php
$validated = $request->validate([
    'montant' => 'required|numeric|min:0|max:999999999',
    'quantity_uv' => 'required|integer|min:1|max:1000000',
]);
```

✅ **Échapper toutes les sorties** : Laravel Blade le fait automatiquement

---

## Conformité RGPD

### 1. Consentement

```php
'compliance' => [
    'require_consent' => true,
],
```

Implémenter un formulaire de consentement lors de l'inscription :

```php
$request->validate([
    'consent' => 'required|accepted',
]);
```

### 2. Droit à l'Oubli

```php
'compliance' => [
    'right_to_be_forgotten' => true,
    'anonymization_delay' => 30,         // Jours
],
```

**Processus d'anonymisation** :

```php
public function anonymizeUser(User $user)
{
    // Anonymiser les données personnelles
    $user->update([
        'name' => 'Utilisateur Anonyme ' . $user->id,
        'email' => 'deleted_' . $user->id . '@anonymized.local',
        'telephone' => null,
        'adresse' => null,
    ]);

    // Logger
    activity()
        ->performedOn($user)
        ->log('user_anonymized');
}
```

### 3. Export des Données

Permettre à l'utilisateur d'exporter ses données :

```php
public function exportUserData(User $user)
{
    return [
        'profile' => $user->toArray(),
        'transactions' => $user->agent->transactions->toArray(),
        'remboursements' => $user->agent->remboursements->toArray(),
    ];
}
```

---

## Checklist de Sécurité

### Configuration Initiale

-   [ ] Copier `.env.example` vers `.env`
-   [ ] Générer clé application : `php artisan key:generate`
-   [ ] Configurer toutes les variables `SECURITY_*` dans `.env`
-   [ ] Activer HTTPS en production (`FORCE_HTTPS=true`)
-   [ ] Configurer les emails d'alerte (`SECURITY_NOTIFICATION_EMAILS`)

### Base de Données

-   [ ] Créer utilisateur DB avec privilèges minimaux
-   [ ] Activer SSL pour connexion DB (si disponible)
-   [ ] Configurer sauvegarde automatique quotidienne
-   [ ] Tester restauration de sauvegarde

### Authentification

-   [ ] Activer 2FA pour Master/Admin
-   [ ] Configurer politique de mot de passe stricte
-   [ ] Tester verrouillage après échecs de connexion
-   [ ] Configurer expiration de session

### Transactions

-   [ ] Vérifier signature_enabled = true
-   [ ] Configurer dual_validation pour montants élevés
-   [ ] Définir daily_limits pour agents
-   [ ] Tester l'immuabilité des transactions

### Audit

-   [ ] Vérifier que ActivityLog est activé
-   [ ] Configurer rétention des logs (365 jours minimum)
-   [ ] Tester les alertes de sécurité
-   [ ] Programmer rapports de sécurité automatiques

### Fraude

-   [ ] Activer fraud_detection
-   [ ] Configurer patterns de détection
-   [ ] Tester auto_block
-   [ ] Définir horaires autorisés

### Monitoring

-   [ ] Programmer `security:report` quotidien
-   [ ] Programmer `security:verify-integrity` quotidien
-   [ ] Configurer monitoring externe (Sentry, etc.)
-   [ ] Tester notifications d'alertes

### Production

-   [ ] Désactiver APP_DEBUG
-   [ ] Mettre APP_ENV=production
-   [ ] Cacher configs : `php artisan config:cache`
-   [ ] Configurer rate limiting strict
-   [ ] Activer IP whitelisting (si nécessaire)
-   [ ] Headers de sécurité HTTP configurés
-   [ ] Certificat SSL valide

---

## Support & Ressources

### Documentation Interne

-   **Architecture Générale** : `ARCHITECTURE.md`
-   **PRD Complet** : `prd_systeme_de_gestion_et_de_suivi_de_la_distribution_des_uv.md`
-   **Conventions** : `CONVENTIONS.md`

### Packages Utilisés

-   **Spatie ActivityLog** : https://spatie.be/docs/laravel-activitylog/v4/introduction
-   **Spatie Laravel Permission** : https://spatie.be/docs/laravel-permission/v6/introduction
-   **Spatie Laravel Backup** : https://spatie.be/docs/laravel-backup/v8/introduction
-   **Laravel Sanctum** : https://laravel.com/docs/11.x/sanctum

### Commandes Rapides

```bash
# Vérifier la configuration de sécurité
php artisan config:show security

# Générer rapport de sécurité
php artisan security:report

# Vérifier intégrité
php artisan security:verify-integrity

# Voir les logs d'activité
php artisan tinker
>>> Activity::latest()->take(10)->get()
```

---

**✅ Système de Sécurité Complet Implémenté**

Ce guide couvre tous les aspects de la sécurité du système UV. Pour toute question ou amélioration, consultez la documentation Laravel ou contactez l'équipe de sécurité.

**Dernière mise à jour** : 31 décembre 2025
