# 🔐 Authentification à Deux Facteurs (2FA)

Guide complet de l'authentification à deux facteurs (2FA) dans UVMASTER.

## 📚 Table des Matières

-   [Vue d'ensemble](#vue-densemble)
-   [Configuration](#configuration)
-   [Activation du 2FA](#activation-du-2fa)
-   [Connexion avec 2FA](#connexion-avec-2fa)
-   [Codes de récupération](#codes-de-récupération)
-   [Désactivation du 2FA](#désactivation-du-2fa)
-   [Administration](#administration)
-   [Sécurité](#sécurité)
-   [Troubleshooting](#troubleshooting)

---

## 🎯 Vue d'ensemble

L'authentification à deux facteurs (2FA) ajoute une couche de sécurité supplémentaire en exigeant deux formes d'identification :

1. **Ce que vous savez** : Votre mot de passe
2. **Ce que vous possédez** : Votre téléphone avec une application d'authentification

### Avantages

-   ✅ **Protection renforcée** contre les accès non autorisés
-   ✅ **Conformité** aux normes de sécurité financière
-   ✅ **Traçabilité** complète des connexions
-   ✅ **Codes de récupération** en cas de perte de téléphone

### Rôles concernés

Le 2FA est **obligatoire** uniquement pour le **super admin de plateforme** (`/platform`).

Le 2FA est **facultatif** pour tous les comptes des entreprises (**Master**, **Admin**, **Comptable**, **Commercial**,
**Lecture seule**) : chacun peut l'activer volontairement dans ses paramètres de sécurité (`/master` → Sécurité 2FA,
ou `/m/securite`). Aucun bandeau ni compte à rebours n'impose son activation. Une 2FA déjà activée reste exigée à
chaque connexion tant que l'utilisateur ne la désactive pas lui-même (mot de passe requis).

---

## ⚙️ Configuration

### 1. Package installé

Le système utilise **Google Authenticator (TOTP)** via le package `pragmarx/google2fa-laravel`.

```bash
composer require pragmarx/google2fa-laravel bacon/bacon-qr-code
```

**Version** : `pragmarx/google2fa-laravel:^2.3`

### 2. Migration de base de données

La migration ajoute 4 colonnes à la table `users` :

```php
$table->string('two_factor_secret')->nullable();          // Secret chiffré
$table->boolean('two_factor_enabled')->default(false);    // État activation
$table->timestamp('two_factor_confirmed_at')->nullable(); // Date confirmation
$table->json('two_factor_recovery_codes')->nullable();    // Codes récupération
```

**Exécution** :

```bash
php artisan migrate
```

### 3. Configuration dans `.env`

```bash
# Authentification 2FA
SECURITY_2FA_ENABLED=true
```

### 4. Vérification

```bash
# Vérifier la configuration
php artisan config:show security

# Vérifier les routes 2FA
php artisan route:list --path=2fa
```

---

## 🔑 Activation du 2FA

### Étape 1 : Accéder à la page de sécurité

1. Connectez-vous au panel admin : **https://uvmaster.ci/master**
2. Dans le menu de gauche, section **"Mon Compte"**
3. Cliquez sur **"Sécurité 2FA"** (icône bouclier 🛡️)

### Étape 2 : Télécharger une application d'authentification

Installez une de ces applications gratuites sur votre téléphone :

-   **Google Authenticator** (recommandé)

    -   [iOS App Store](https://apps.apple.com/app/google-authenticator/id388497605)
    -   [Android Play Store](https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2)

-   **Microsoft Authenticator**

    -   [iOS App Store](https://apps.apple.com/app/microsoft-authenticator/id983156458)
    -   [Android Play Store](https://play.google.com/store/apps/details?id=com.azure.authenticator)

-   **Authy**
    -   [iOS App Store](https://apps.apple.com/app/authy/id494168017)
    -   [Android Play Store](https://play.google.com/store/apps/details?id=com.authy.authy)

### Étape 3 : Scanner le QR Code

1. Sur la page **"Sécurité 2FA"**, cliquez sur **"Activer le 2FA"**
2. Un **QR Code** s'affiche à l'écran
3. Ouvrez votre application d'authentification
4. Scannez le QR Code avec la caméra de votre téléphone
5. L'application ajoute automatiquement "UVMASTER - votre-email@example.com"

### Étape 4 : Vérifier le code

1. L'application génère un **code à 6 chiffres** qui change toutes les 30 secondes
2. Entrez ce code dans le champ **"Code de vérification"**
3. Cliquez sur **"Confirmer"**

### Étape 5 : Sauvegarder les codes de récupération

🚨 **IMPORTANT** : Des **codes de récupération** s'affichent à l'écran.

**Sauvegardez-les immédiatement** dans un endroit sûr :

-   Imprimez-les et conservez-les dans un coffre
-   Enregistrez-les dans un gestionnaire de mots de passe (1Password, LastPass, Bitwarden)
-   Stockez-les dans un fichier chiffré

**Vous aurez 8 codes** au format : `XXXX-XXXX-XXXX`

⚠️ Chaque code ne peut être utilisé **qu'une seule fois**.

---

## 🚪 Connexion avec 2FA

### Flux de connexion

1. **Entrez vos identifiants** (email + mot de passe)
2. Si correct, vous êtes redirigé vers la **page de vérification 2FA**
3. **Deux options** :

#### Option A : Code de l'application (recommandé)

1. Ouvrez votre application d'authentification
2. Cherchez "UVMASTER"
3. Entrez le code à 6 chiffres affiché
4. Cliquez sur **"Vérifier"**

#### Option B : Code de récupération (si pas de téléphone)

1. Cliquez sur **"Utiliser un code de récupération"**
2. Entrez un de vos codes sauvegardés (`XXXX-XXXX-XXXX`)
3. Cliquez sur **"Vérifier"**

### Durée de la session 2FA

Une fois vérifié, vous restez connecté pendant :

-   **30 minutes** d'activité (par défaut)
-   **15 minutes** d'inactivité maximale

Après expiration, vous devrez re-vérifier le 2FA.

---

## 🆘 Codes de récupération

### Utilisation

Les codes de récupération permettent de se connecter si :

-   Vous avez perdu votre téléphone
-   L'application d'authentification ne fonctionne pas
-   Vous changez de téléphone

### Vérifier les codes restants

1. Allez sur **"Mon Compte" → "Sécurité 2FA"**
2. Section **"Codes de récupération"**
3. Le nombre de codes restants est affiché

⚠️ Si vous avez **moins de 3 codes restants**, régénérez-les !

### Régénérer les codes

1. Sur la page **"Sécurité 2FA"**
2. Section **"Codes de récupération"**
3. Cliquez sur **"Régénérer les codes"**
4. Entrez votre code OTP actuel pour confirmer
5. **8 nouveaux codes** sont générés
6. ⚠️ **Les anciens codes sont invalidés**
7. Sauvegardez les nouveaux codes immédiatement

---

## ❌ Désactivation du 2FA

### Pour les rôles Master/Admin

⚠️ **Le 2FA est obligatoire** pour Master et Admin. Vous ne pouvez pas le désactiver via l'interface.

Si vous devez absolument le désactiver (ex: problème technique), contactez le support technique.

### Pour les autres rôles (Comptable/Commercial)

1. Allez sur **"Mon Compte" → "Sécurité 2FA"**
2. Cliquez sur **"Désactiver le 2FA"**
3. Entrez votre code OTP ou un code de récupération
4. Confirmez la désactivation

⚠️ Votre compte sera moins sécurisé.

---

## 👨‍💼 Administration

### Forcer le 2FA pour un utilisateur

En tant que Master, vous pouvez forcer l'activation du 2FA :

```bash
php artisan tinker
>>> $user = User::find(1);
>>> $user->two_factor_enabled = false;
>>> $user->two_factor_secret = null;
>>> $user->save();
```

Au prochain login, l'utilisateur devra obligatoirement configurer le 2FA.

### Désactiver le 2FA d'urgence (support uniquement)

En cas de perte de téléphone ET de codes de récupération :

```bash
php artisan tinker
>>> $user = User::where('email', 'admin@example.com')->first();
>>> $user->update([
...   'two_factor_enabled' => false,
...   'two_factor_secret' => null,
...   'two_factor_confirmed_at' => null,
...   'two_factor_recovery_codes' => null,
... ]);
```

⚠️ Action à effectuer uniquement après vérification d'identité stricte.

### Statistiques 2FA

Voir les utilisateurs avec 2FA activé :

```bash
php artisan tinker
>>> User::where('two_factor_enabled', true)->count();
>>> User::where('two_factor_enabled', true)->pluck('email');
```

Voir les connexions 2FA dans l'audit :

```bash
php artisan tinker
>>> Activity::where('description', 'LIKE', '%2FA%')->latest()->take(20)->get();
```

---

## 🔐 Sécurité

### Protocole utilisé

-   **TOTP** (Time-based One-Time Password)
-   **Algorithme** : HMAC-SHA1
-   **Période** : 30 secondes
-   **Longueur** : 6 chiffres

### Chiffrement

-   **Secret 2FA** : Chiffré avec `encrypted` cast Laravel
-   **Codes de récupération** : Stockés en JSON, jamais hashés (pour vérification)
-   **Session 2FA** : Stockée dans la session Laravel (chiffrée côté serveur)

### Protection contre les attaques

| Attaque               | Protection                                 |
| --------------------- | ------------------------------------------ |
| **Brute force**       | Limite de 3 tentatives puis lockout 15 min |
| **Replay attack**     | Codes TOTP valides 30 secondes uniquement  |
| **Phishing**          | QR code unique par utilisateur             |
| **Man-in-the-middle** | HTTPS obligatoire en production            |
| **Session hijacking** | CSRF token + session timeout               |

### Audit trail

Toutes les actions 2FA sont loguées dans `activity_log` :

-   Activation du 2FA
-   Désactivation du 2FA
-   Utilisation d'un code de récupération
-   Régénération des codes
-   Échecs de vérification

---

## 🆘 Troubleshooting

### Problème : "Code invalide"

**Causes possibles** :

1. **Horloge désynchronisée**

    - Solution : Synchronisez l'horloge de votre téléphone avec un serveur NTP
    - iOS : Réglages → Général → Date et heure → Régler automatiquement
    - Android : Paramètres → Système → Date et heure → Régler automatiquement

2. **Code expiré** (30 secondes)

    - Solution : Attendez le prochain code

3. **Mauvais compte scanné**
    - Solution : Vérifiez que vous utilisez le bon compte dans l'app

### Problème : "Je n'ai plus accès à mon téléphone"

**Solutions** :

1. **Utilisez un code de récupération** sauvegardé
2. **Contactez le support** avec preuve d'identité
3. Le support désactivera temporairement votre 2FA

### Problème : "QR Code ne se charge pas"

**Solutions** :

1. Videz le cache : `php artisan cache:clear`
2. Vérifiez les permissions : `storage/` doit être accessible en écriture
3. Vérifiez que `bacon/bacon-qr-code` est installé :
    ```bash
    composer show bacon/bacon-qr-code
    ```

### Problème : "Je suis bloqué en boucle sur la page 2FA"

**Cause** : Session corrompue

**Solution** :

```bash
# Déconnectez-vous complètement
php artisan tinker
>>> session()->flush();

# Ou supprimez les fichiers de session
rm storage/framework/sessions/*
```

### Problème : "Le 2FA ne s'affiche pas dans le menu"

**Vérifications** :

1. Vérifiez que vous êtes connecté
2. Vérifiez votre rôle (doit avoir accès au panel admin)
3. Videz le cache Filament :
    ```bash
    php artisan filament:optimize-clear
    ```

---

## 📊 Statistiques

### Commandes utiles

```bash
# Nombre total d'utilisateurs avec 2FA
php artisan tinker
>>> User::where('two_factor_enabled', true)->count();

# Utilisateurs Master/Admin sans 2FA (à corriger !)
>>> User::role(['master', 'admin'])
...   ->where('two_factor_enabled', false)
...   ->pluck('email');

# Dernières activités 2FA
>>> Activity::where('description', 'LIKE', '%2FA%')
...   ->latest()
...   ->take(10)
...   ->get(['description', 'created_at']);
```

---

## 📖 Ressources

### Documentation officielle

-   **Google Authenticator** : https://support.google.com/accounts/answer/1066447
-   **RFC 6238 (TOTP)** : https://tools.ietf.org/html/rfc6238
-   **pragmarx/google2fa** : https://github.com/antonioribeiro/google2fa

### Applications recommandées

| Application             | iOS | Android | Sauvegarde cloud      |
| ----------------------- | --- | ------- | --------------------- |
| Google Authenticator    | ✅  | ✅      | ✅ (compte Google)    |
| Microsoft Authenticator | ✅  | ✅      | ✅ (compte Microsoft) |
| Authy                   | ✅  | ✅      | ✅ (compte Authy)     |
| 1Password               | ✅  | ✅      | ✅ (1Password vault)  |

### Support

En cas de problème avec le 2FA :

1. Consultez cette documentation
2. Vérifiez les logs : `storage/logs/laravel.log`
3. Contactez le support technique avec :
    - Votre email
    - Votre rôle
    - Description du problème
    - Capture d'écran si possible

---

## ✅ Checklist

Avant de déployer en production :

-   [ ] Tous les comptes Master/Admin ont le 2FA activé
-   [ ] Codes de récupération sauvegardés pour chaque admin
-   [ ] Variables `.env` configurées (`SECURITY_2FA_ENABLED=true`)
-   [ ] HTTPS activé (`FORCE_HTTPS=true`)
-   [ ] Logs d'audit activés (Spatie ActivityLog)
-   [ ] Documentation distribuée aux admins
-   [ ] Procédure de récupération documentée
-   [ ] Support technique formé au dépannage 2FA

---

**Dernière mise à jour** : 31 décembre 2025  
**Version** : 1.0.0  
**Statut** : ✅ Production Ready
