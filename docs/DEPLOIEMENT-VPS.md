# Exploitation du VPS UVMASTER

Architecture validée en local (Docker, MySQL 8.0) ; **non déployée** sur un serveur réel (aucun accès fourni, voir `MIGRATION-UVMASTER.md` §4).

## Architecture

| Service | Rôle | Exposition |
|---|---|---|
| `caddy` | Reverse proxy HTTPS (Let's Encrypt automatique et renouvelé), `www` → apex en 301, en-têtes de sécurité (HSTS, nosniff, referrer, frame) | **80, 443** (seul service public) |
| `app` | nginx + PHP-FPM (supervisord), `/api/health` | réseau privé uniquement (PHP-FPM écoute 127.0.0.1 dans le conteneur) |
| `queue` | `php artisan queue:work` (file en base de données), utilisateur `www-data` | privé |
| `scheduler` | `php artisan schedule:work` (rappels, commissions, **sauvegardes**, vérifications), utilisateur `www-data` | privé |
| `db` | MySQL (`MYSQL_IMAGE`), volume persistant | **aucun port publié** |

Volumes : `db_data` (base), `storage_data` (fichiers téléversés, sessions, journaux, sauvegardes applicatives), `caddy_data` (certificats).
Journaux Docker en rotation (10 Mo × 5) ; journaux Laravel quotidiens (`LOG_CHANNEL=daily`, 14 jours).
Redémarrage automatique (`unless-stopped`), contrôles de santé sur chaque service, limites de mémoire.

> Le planificateur Laravel lance aussi `queue:work --stop-when-empty` chaque minute (héritage de l'hébergement mutualisé) : sans effet néfaste à côté du worker permanent.

## Première installation

```bash
sudo deploy/scripts/bootstrap-vps.sh --inspect     # LECTURE SEULE : état du serveur, services et ports déjà occupés
sudo deploy/scripts/bootstrap-vps.sh --apply       # refuse de continuer si 80/443 sont occupés
cd /opt/uvmaster/deploy && cp .env.production.example .env.production && chmod 600 .env.production   # puis remplir
docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build
docker compose --env-file .env.production -f docker-compose.prod.yml ps
```

Nouvelle base **vide** ou restaurée : la garde de migration impose une sauvegarde reconnue avant `migrate` (voir `MIGRATION-UVMASTER.md` §6) :

```bash
dc() { docker compose --env-file .env.production -f docker-compose.prod.yml "$@"; }
dc exec -u www-data app php artisan backup:run --only-db
dc exec -u www-data app php artisan tenancy:preflight
dc exec -u www-data app php artisan migrate --force
dc exec -u www-data app php artisan db:seed --class=RolesAndPermissionsSeeder --force
dc exec -u www-data app php artisan db:seed --class=SubscriptionPlanSeeder --force
dc exec -u www-data app php artisan platform:super-admin votre@email.com --name="Votre nom"   # mot de passe demandé (12 caractères min.)
```

`db:seed` sans `--class` s'arrête volontairement (« aucun tenant courant »).

## Exploitation courante

| Action | Commande (depuis `deploy/`) |
|---|---|
| État / santé | `scripts/healthcheck.sh` (code 0 = sain) ; `dc ps` |
| Journaux | `dc logs -f --tail=200 app` · `queue` · `scheduler` · `caddy` |
| Redémarrer un service | `dc restart app` (ou `queue`, `scheduler`) |
| Déployer une version | `scripts/deploy.sh` (sauvegarde restaurable, build, démarrage, synchronisation des rôles/permissions, contrôle de plateforme, `optimize`, santé) ; `--migrate` pour exécuter les migrations après préflight |
| Mise en maintenance | `dc exec -u www-data app php artisan down --secret=<jeton>` / `up` |
| Console applicative | `dc exec -u www-data app php artisan tinker` |
| Mémoire / CPU / disque | `docker stats --no-stream` ; `df -h` ; `docker system df` (ne **pas** lancer `docker system prune` sur un serveur partagé avec d'autres projets) |

## Sauvegardes et restauration

Deux niveaux complémentaires :

1. **Applicatives** (planifiées par Laravel, `backup:run` à heures fixes) : archives dans `storage/app/UVMASTER CI/` (dossier fixé par `APP_NAME` : ne plus modifier cette valeur une fois en production), consultables par le super admin.
2. **Système** (`deploy/scripts/backup.sh`) : dump MySQL en transaction unique + archive des fichiers + empreintes `SHA256SUMS` + comptages de contrôle, rétention `BACKUP_KEEP_DAYS` (14 j).

Le fichier `.env.production` et le dossier `deploy/backups/` sont accessibles uniquement à `root`. Installer ces tâches dans le crontab `root` ; le verrou empêche la sauvegarde planifiée et sa vérification de se chevaucher :

```bash
sudo crontab -e
15 2 * * *  flock -n /run/lock/uvmaster-backup.lock /opt/uvmaster/deploy/scripts/backup.sh >> /var/log/uvmaster-backup.log 2>&1
45 2 * * 0  latest="$(find /opt/uvmaster/deploy/backups -mindepth 1 -maxdepth 1 -type d | sort | tail -n 1)"; test -n "$latest" && flock -n /run/lock/uvmaster-backup.lock /opt/uvmaster/deploy/scripts/verify-restore.sh "$latest" >> /var/log/uvmaster-backup.log 2>&1
```

Limiter aussi la croissance du journal avec `/etc/logrotate.d/uvmaster-backup` :

```text
/var/log/uvmaster-backup.log {
    su root adm
    weekly
    rotate 12
    size 10M
    compress
    delaycompress
    missingok
    notifempty
    create 0640 root adm
}
```

- **Vérifier** une sauvegarde sans toucher à la production : `scripts/verify-restore.sh <dossier>` (restaure dans une base temporaire, compare les comptages, la supprime).
- **Restaurer** (destructif pour la base cible) : `scripts/restore.sh <dossier> --yes` (refuse si les empreintes ne correspondent pas). Pour une répétition : `--target-db ma_base_test`.
- **Copier hors du serveur** : les sauvegardes locales ne protègent pas d'une perte du VPS. Envoyer `deploy/backups/` vers un stockage distant chiffré (rsync/rclone) — à configurer (⚪ non déterminé : destination).

## Sécurité

- Pare-feu : 22, 80, 443 uniquement (`bootstrap-vps.sh --apply`) ; `fail2ban` ; mises à jour de sécurité automatiques ; connexion SSH par clé, mot de passe désactivé (à vérifier sur le serveur).
- Secrets : uniquement dans `deploy/.env.production` (`chmod 600`, ignoré par Git). `APP_DEBUG=false`. Sessions chiffrées, cookies `Secure`/`HttpOnly`/`SameSite=Lax`, 2FA exigée pour le super admin.
- La base n'est joignable que par les conteneurs `app`, `queue`, `scheduler` ; PHP-FPM n'écoute que dans son conteneur ; workers sans privilèges root.
- Certificats : renouvelés par Caddy (volume `caddy_data` à conserver). Surveiller `dc logs caddy`.
- Mises à jour des dépendances : `composer audit` et `npm audit` avant chaque déploiement.
