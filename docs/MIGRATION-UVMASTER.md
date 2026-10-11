# Migration UVSYSTEM → UVMASTER (marque, domaine, VPS, Android)

Plan de bascule global unique vers **https://uvmaster.ci**. Les valeurs de ce document sont celles qui ont été **vérifiées** ;
tout ce qui dépend d'un accès externe est marqué **BLOQUÉ** avec l'action exacte. Statuts : VALIDÉ · ÉCHEC · BLOQUÉ · NON TESTÉ.

## 1. Décisions retenues

| Sujet | Décision |
|---|---|
| Marque visible | **UVMASTER**, slogan « La gestion des UV simplifiée » — source unique : `config/branding.php` (`BRAND_*`) |
| Domaine | `https://uvmaster.ci` (apex canonique) ; `www.uvmaster.ci` redirigé en 301 vers l'apex (Caddy) |
| `APP_NAME` | **Inchangé** (`MODELEC CI`) : il fixe le dossier des sauvegardes (`storage/app/MODELEC CI/`), le nom du cookie de session et le préfixe de cache. Le changer rendrait les sauvegardes introuvables et bloquerait la garde de migration (`tenancy:preflight`) |
| Nom d'entreprise en base | **Jamais modifié** (donnée métier, tenant n°1 « MODELEC CI ») ; se renomme depuis « Profil de l'entreprise » |
| Android | `pro.uvsystem.app` conservé, même clé de signature, version 4.1 (code 15), URL `https://uvmaster.ci/m` |
| Ancien domaine | Abandonné : aucun maintien de service prévu. Les APK non mis à jour (pointant sur l'ancien domaine) cesseront de fonctionner |
| Identifiant d'expéditeur SMS | **Inchangé** tant que l'opérateur n'a pas enregistré « UVMASTER » (le texte des SMS, lui, porte la nouvelle marque) |

> Écart de cadrage constaté : les demandes successives nommaient `uvsystem.pro` puis `uvsystem.org` comme ancien domaine.
> Le code et l'APK installé ne contiennent que `uvsystem.pro` ; `uvsystem.org` n'apparaît nulle part et n'a pas été touché.
> **Confirmer** lequel est le domaine de production actuel avant de couper quoi que ce soit.

## 2. Ce qui a été fait dans le dépôt

- Marque centralisée et remplacée dans 21 vues Blade (connexion, 2FA, accueil, mobile, reçu, 12 PDF, e-mails), les 3 panneaux Filament, les SMS, les notifications, l'émetteur 2FA (TOTP), l'export Excel des charges, le CMS (valeurs par défaut) et les commandes de test.
- Adresses par défaut `@uvsystem.pro`, `@uv-system.com` → `@uvmaster.ci` ; CORS par défaut → `uvmaster.ci`.
- Android : libellé, URL, page hors-ligne, version.
- **Corrections découvertes pendant la migration** (existaient avant, indépendantes de la marque) :
  - `config/backup.php` figeait `databases => ['sqlite']` : `backup:run` ne sauvegardait **pas** une base MySQL (corrigé : suit `DB_CONNECTION`).
  - Le client de dump doit pouvoir s'authentifier sur MySQL 8 (greffon `caching_sha2_password`) : ajouté à l'image (`mariadb-connector-c`) ; option `DB_DUMP_SKIP_SSL` pour un conteneur MySQL sur réseau privé.
- Infrastructure : `Dockerfile` (remplace l'ancien, inutilisable), `docker/`, `deploy/` (compose, Caddy, scripts, modèle d'environnement).

## 3. Variables d'environnement à mettre à jour en production

| Variable | Ancienne valeur (typique) | Nouvelle valeur |
|---|---|---|
| `APP_URL` | `https://uvsystem.pro` | `https://uvmaster.ci` |
| `APP_DOMAIN`, `BRAND_DOMAIN` | — | `uvmaster.ci` |
| `BRAND_*` | — | voir `deploy/.env.production.example` |
| **`COMPANY_NAME`, `COMPANY_TAGLINE`, `COMPANY_EMAIL`, `COMPANY_WEBSITE`** | `MODELEC CI`, `Système de Gestion UV`, `…@uvsystem.pro`, `uvsystem.pro` | `UVMASTER`, `La gestion des UV simplifiée`, `contact@uvmaster.ci`, `uvmaster.ci` (**ces variables écrasent les valeurs par défaut du code**) |
| `SANCTUM_STATEFUL_DOMAINS` | `uvsystem.pro,api.uvsystem.pro,app.uvsystem.pro` | `uvmaster.ci` |
| `CORS_ALLOWED_ORIGINS` | (valeur héritée) | `https://uvmaster.ci` |
| `MAIL_FROM_ADDRESS` | `contact@uvsystem.pro` | `contact@uvmaster.ci` (**boîte et domaine à créer/vérifier chez Resend : SPF + DKIM**) |
| `MAIL_FROM_NAME` | `${APP_NAME}` | `UVMASTER` |
| `BACKUP_NOTIFICATION_EMAIL`, `SECURITY_NOTIFICATION_EMAILS` | `…@uvsystem.pro` | `…@uvmaster.ci` |
| `APP_NAME` | `MODELEC CI` | **ne pas changer** |
| `APP_KEY` | clé de production | **reprendre la même** (elle chiffre des données, dont les sessions chiffrées) |

## 4. Prérequis externes (BLOQUÉS tant qu'ils ne sont pas levés)

1. **Accès au VPS** : adresse, utilisateur, clé SSH dédiée à UVMASTER. Aucun hôte n'a été fourni ; les clés présentes sur le poste de travail
   appartiennent à d'autres projets et n'ont **pas** été utilisées.
2. **DNS** : au 2026-10-11, `uvmaster.ci` et `www.uvmaster.ci` **ne résolvent pas** (aucun enregistrement A). Créer chez le bureau d'enregistrement :
   `A uvmaster.ci → <IP du VPS>` et `A www.uvmaster.ci → <IP du VPS>` (ou CNAME vers l'apex). Baisser le TTL à 300 s au moins 24 h avant la bascule.
3. **Resend** : vérifier `uvmaster.ci`, créer l'expéditeur, mettre à jour l'URL du webhook (`https://uvmaster.ci/api/webhooks/resend`) et son secret. Si le compte Resend
   est partagé avec d'autres applications, **ajouter** le domaine sans retirer les existants.
4. **Logos officiels UVMASTER** (`public/images/logo.png`, `logo-dark.png`, `logo-wide.png`, favicons, icônes et écran de démarrage Android) : non fournis, **non inventés**.
   Les images actuelles contiennent encore l'ancien mot-symbole.
5. **SMS** : confirmer auprès de l'opérateur que l'identifiant « UVMASTER » peut être enregistré avant de modifier `TWILIO_FROM`.
6. **Version MySQL de production** (⚪ non déterminée) : la relever (`SELECT VERSION();`) et fixer `MYSQL_IMAGE` à la même version.
7. **Firebase** : aucun changement requis (identifiant d'application Android inchangé). Mettre le fichier de compte de service sur le VPS, en lecture seule, jamais dans Git.

## 5. Plan de bascule (une seule coupure)

**J-7 à J-1 — préparation**
1. Obtenir les points §4. Relever la version MySQL, créer `deploy/.env.production` à partir du modèle (secrets générés, `APP_KEY` repris).
2. `deploy/scripts/bootstrap-vps.sh --inspect` : décrire l'état du serveur **avant toute modification** ; traiter tout service déjà en écoute sur 80/443.
3. Préparer le VPS (`--apply`), copier le dépôt dans `/opt/uvmaster`, `docker compose … up -d --build`.
4. **Répétition générale avec une copie des données** (voir §6) sur le VPS, sous une adresse de test ou via `/etc/hosts` : restauration, `tenancy:preflight`, migration, parcours complet. Mesurer la durée.
5. Baisser le TTL DNS. Distribuer l'APK 4.1 (voir §8) **avant** la coupure pour que les utilisateurs s'y préparent.

**J — bascule**
1. Annoncer la fenêtre de maintenance ; mettre l'ancien site en maintenance (lecture seule ou page d'attente) pour figer les données.
2. Sauvegarde finale de l'ancienne base **et** des fichiers (`storage/app/public`, `storage/app/private`) ; vérifier les empreintes.
3. Restaurer sur le VPS (§6), exécuter `tenancy:preflight` puis `php artisan migrate --force`, contrôler les comptages.
4. Changer les DNS (`A uvmaster.ci`, `A www.uvmaster.ci`). Caddy obtient le certificat dès que le DNS résout.
5. Tests de fumée (§7). Si l'un échoue : retour arrière (§9).
6. Ouvrir l'accès. Surveiller les journaux 24 h.

**J+1 à J+30** : suivre l'adoption de l'APK 4.1 ; ne résilier l'ancien hébergement qu'après vérification de la nouvelle installation et accord explicite.

## 6. Migration des données (hébergement actuel → VPS)

Source : MySQL de l'hébergement mutualisé (base de production) + fichiers téléversés. **La production n'est pas encore migrée au modèle multi-tenant** : la migration
`2026_10_07_000001` rattache toutes les données existantes au tenant n°1 et est protégée par `tenancy:preflight`.

```bash
# 1. Sur l'hébergement actuel (lecture seule sur la base) : dump cohérent + fichiers
mysqldump --single-transaction --routines --triggers --no-tablespaces -u <user> -p <base> | gzip > uvsystem-$(date +%F).sql.gz
tar -czf uvsystem-files-$(date +%F).tar.gz storage/app/public storage/app/private
sha256sum uvsystem-*.gz > SHA256SUMS

# 2. Copier vers le VPS, vérifier : sha256sum -c SHA256SUMS

# 3. Sur le VPS : importer dans la base du conteneur (base vide ou temporaire de répétition)
gunzip -c uvsystem-DATE.sql.gz | docker compose --env-file .env.production -f docker-compose.prod.yml exec -T db mysql -uroot -p"$DB_ROOT_PASSWORD" uvmaster

# 4. Contrôle préalable + sauvegarde reconnue + migration
docker compose … exec -u www-data app php artisan backup:run --only-db
docker compose … exec -u www-data app php artisan tenancy:preflight          # doit répondre « Migration autorisée : OUI »
docker compose … exec -u www-data app php artisan migrate --force
docker compose … exec -u www-data app php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Contrôles de cohérence **avant et après** : nombre de lignes par table cœur (`tenants`, `users`, `agents`, `uv_transactions`, `remboursements`, `approvisionnements`, `charges`,
`commissions`), aucun orphelin (clés étrangères), comptes/rôles identiques, fichiers présents (logos, justificatifs), un parcours de bout en bout par rôle.
La décision sur les tables `clients*` éventuelles reste **humaine** (le préflight s'arrête et l'explique).

## 7. Tests de fumée en production

`https://uvmaster.ci/api/health` = 200 · page d'accueil, `/m/login`, `/master/login` (marque UVMASTER) · connexion master + 2FA · liste des agents · une vente de test
(annulée ensuite) · export PDF (en-tête UVMASTER) · e-mail de test (`Diagnostic des notifications`) · notification push · SMS de test (si l'identifiant le permet) ·
APK 4.1 : connexion, retour matériel, déconnexion · `deploy/scripts/healthcheck.sh` = 0 · certificat valide et renouvellement (`docker compose logs caddy`).

## 8. Android

APK/AAB signés : `android/app/build/outputs/apk/release/app-release.apk` (et `.aab`). Empreinte du certificat identique à l'ancienne version (mise à jour possible par-dessus).
Les installations existantes (4.0) pointent sur l'ancien domaine : **elles ne fonctionneront plus après la coupure** ; distribuer la 4.1 avant (lien de téléchargement ou Google Play).
Le serveur ne dépend pas du libellé de l'application. Le thème d'icônes et l'écran de démarrage attendent les logos officiels (§4.4).

## 9. Retour arrière

- Tant que l'ancien hébergement tourne : remettre les DNS sur l'ancienne adresse (TTL bas), retirer la maintenance. **Aucune donnée de l'ancien système n'est modifiée** par la bascule.
- Si des données ont été saisies sur le nouveau VPS après l'ouverture : exporter ces enregistrements avant de revenir (sauvegarde `deploy/scripts/backup.sh`).
- Retour d'une mauvaise version applicative sur le VPS : `APP_VERSION=<tag précédent> docker compose … up -d` (les images sont étiquetées), puis restauration de la sauvegarde pré-déploiement
  si le schéma a changé : `deploy/scripts/restore.sh <dossier> --yes`.
- Ne **jamais** exécuter `migrate:fresh` ni `migrate:rollback` sur des données réelles.

## 10. Ne jamais publier

`.env`, `deploy/.env.production`, `android/keystore.properties`, `android/*.jks`, `google-services.json`, le compte de service Firebase, tout dump SQL, `deploy/backups/`.

## 11. Tableau de validation (état au 2026-10-11)

Rien de ce qui suit n'a été exécuté sur la production ni sur un serveur réel. « Local » = pile Docker de test sur le poste de travail (MySQL 8.0.46, certificat interne, ports 8080/8443).

| # | Contrôle | Statut | Preuve / reste à faire |
|---|---|---|---|
| 1 | Marque UVMASTER + slogan dans les écrans, e-mails, PDF, exports, 2FA, SMS, notifications | VALIDÉ | `BrandIdentityTest` ; balayage des références restantes (identifiants techniques conservés volontairement : paquet Android, nom du son d'alerte, `APP_NAME`) |
| 2 | Logos officiels, icônes, écran de démarrage | BLOQUÉ | fichiers non fournis (§4.4) |
| 3 | Suite de tests SQLite | VALIDÉ | 1247 réussis, 1 ignoré, 0 échec |
| 4 | Suite de tests MySQL 8.0.46 | VALIDÉ | 1232 réussis, 16 ignorés (tests de DDL propres à SQLite, déjà exercés par la vraie migration sur MySQL), 0 échec |
| 5 | Dépendances (`composer audit`) | VALIDÉ | 0 avis après mise à jour (Laravel 12.69, Filament 3.3.56, Livewire 3.8) ; `npm audit --omit=dev` : 0 vulnérabilité |
| 6 | Style (Pint) | ÉCHEC (préexistant) | `pint --test` signale 182 fichiers dont beaucoup non modifiés ici ; non corrigé pour ne pas mélanger un reformatage global à la migration |
| 7 | Image Docker, pile complète, HTTPS, en-têtes, redirection 80→443, un seul service publié (Caddy), workers et planificateur sains | VALIDÉ (local) | `healthcheck.sh` = 0 |
| 8 | Redirection `www` → apex | VALIDÉ (local, règle Caddy) / NON TESTÉ en réel | dépend du DNS |
| 9 | Sauvegarde → préflight → migration sur base vide | VALIDÉ (local) | |
| 10 | `backup.sh`, `verify-restore.sh`, `restore.sh --target-db`, refus d'une archive altérée | VALIDÉ (local) | un défaut corrigé (expansion de `$DBN` suivie d'un caractère non ASCII) |
| 11 | Sauvegarde hors serveur | BLOQUÉ | destination à choisir (§ DEPLOIEMENT-VPS) |
| 12 | Migration des données réelles | NON TESTÉ | exige l'accès à la production et une autorisation explicite |
| 13 | VPS, DNS, certificat Let's Encrypt réel | BLOQUÉ | aucun hôte ni enregistrement DNS (§4.1–4.2) |
| 14 | APK/AAB 4.1 signés, certificat identique à l'ancienne version | VALIDÉ (apksigner + empreinte) | installation sur appareil réel : NON TESTÉ |
| 15 | Resend (domaine, webhook, boîte), identifiant SMS, Firebase | BLOQUÉ / NON TESTÉ | actions chez les fournisseurs (§4.3, 4.5, 4.7) |
| 16 | Domaine de production d'origine | ⚪ NON DÉTERMINÉ | le dépôt ne connaît que `uvsystem.pro` ; la consigne mentionnait aussi `uvsystem.org` (à ne pas toucher) |
