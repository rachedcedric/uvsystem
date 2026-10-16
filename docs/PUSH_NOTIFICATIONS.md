# Notifications push des dettes agents

Les alertes de seuil (vérification horaire) et les rappels de dette (toutes les
deux heures) sont envoyés aux comptes `master` et `admin` qui ont enregistré un
appareil Android. Les notifications en base restent disponibles même si un
appareil ou Firebase ne répond pas.

## Configuration Firebase requise

1. Créer un projet Firebase et enregistrer une application Android avec
   l'identifiant `ci.uvmaster.app`.
2. Activer Firebase Cloud Messaging (FCM) et télécharger
   `google-services.json` dans `android/app/`. Ne pas le copier dans le
   répertoire web `public/`.
3. Dans la console Google Cloud associée au même projet, créer une clé de
   compte de service autorisée à envoyer des messages FCM. Copier le JSON sur
   le serveur dans un emplacement privé, non accessible depuis le web, par
   exemple `storage/app/firebase/service-account.json`, avec des permissions
   de lecture limitées au compte PHP.
4. Configurer dans l'environnement du serveur :

   ```dotenv
   FIREBASE_PROJECT_ID=identifiant-du-projet
   FIREBASE_CREDENTIALS=/chemin/prive/service-account.json
   ```

   Ne jamais versionner, publier via FTP dans `public/`, ni envoyer par chat la
   clé privée du compte de service.
5. Exécuter `php artisan migrate --force` sur le serveur après déploiement.
6. Synchroniser et reconstruire l'application Android :
   `npx cap sync android`, puis générer et distribuer un nouvel APK signé.
   L'utilisateur doit accepter l'autorisation Android de notifications ; son
   compte doit avoir le rôle `master` ou `admin`.

Sans `google-services.json`, l'APK ne peut pas obtenir de jeton FCM. Sans le
compte de service configuré sur le serveur, les push ne peuvent pas être
envoyées ; les notifications en base continuent à fonctionner.

## Sons, vérification et fonctionnement sans Firebase

### Sons
- **Dans l'application** : sons synthétisés (aucun fichier à charger) pour les
  nouvelles notifications, les succès et les erreurs, réglables dans
  **Plus → Préférences** (Sons, Sons des touches, Vibrations).
- **Notifications push (appli fermée)** : canal Android `debt-alerts-v2` avec le
  son `uvmaster_alert` (`android/app/src/main/res/raw/uvmaster_alert.wav`) et
  l'icône de barre d'état `ic_stat_uvmaster`. Android ne permet pas de changer le
  son d'un canal existant : pour un autre son, créez un nouveau canal
  (`debt-alerts-v3`) dans `resources/js/mobile.js`,
  `app/Services/FirebaseMessaging.php` et `AndroidManifest.xml`.
- Une surveillance (`NotificationWatcher`, toutes les 30 s, tous les écrans)
  joue le son d'alerte et affiche une bannière touchable à l'arrivée d'une
  notification de la cloche, et pose le badge rouge sur l'onglet « Plus ».

### Sans `google-services.json`
Une APK construite sans `google-services.json` fonctionne : la cloche, les sons
et la surveillance restent actifs, mais les notifications push sont désactivées
(`window.UvmasterNative.hasFirebase()` renvoie `false`). Auparavant, une telle
APK plantait à la connexion d'un master/admin.

### Vérifier la configuration
**Plus → Diagnostic des notifications** (master/admin) contrôle : destinataires,
planificateur (cron), file d'attente, rappels de dette, e-mail, SMS, Firebase et
environnement, et propose une **notification de test** (cloche + push, résultat
par appareil) et un **e-mail de test**.

### Hébergement mutualisé : une seule tâche cron suffit
```
* * * * * php /chemin/du/site/artisan schedule:run >> /dev/null 2>&1
```
Le planificateur enregistre un battement de cœur chaque minute et lance
lui-même le worker de la file d'attente (`queue:work --stop-when-empty`), ce
qui évite d'avoir un worker permanent. Sans cron, les rappels de dette sont
tout de même déclenchés à la première visite d'un master/admin de chaque cycle
de 2 h, mais les e-mails et alertes en file d'attente ne partent pas.
Alternative sans cron pour la file d'attente : `QUEUE_CONNECTION=sync`.
