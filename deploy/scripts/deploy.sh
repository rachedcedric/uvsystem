#!/usr/bin/env bash
# Déploiement d'une nouvelle version. Ne lance JAMAIS de migration seul : voir docs/MIGRATION-UVMASTER.md.
# Usage : deploy/scripts/deploy.sh [--migrate]    (--migrate : exécute `php artisan migrate --force` après une sauvegarde vérifiée)
source "$(dirname "$0")/common.sh"; need_env
MIGRATE=0; [ "${1:-}" = "--migrate" ] && MIGRATE=1
log "Sauvegarde préalable…"; BK="$("$DEPLOY_DIR/scripts/backup.sh" | tail -1)"; "$DEPLOY_DIR/scripts/verify-restore.sh" "$BK"
log "Construction de l'image et démarrage…"; dc build --pull; dc up -d
if [ "$MIGRATE" = 1 ]; then
  log "Contrôle préalable de migration puis migration…"
  dc exec -T -u www-data app php artisan tenancy:preflight || { echo "Préflight bloquant : migration refusée." >&2; exit 1; }
  dc exec -T -u www-data app php artisan migrate --force
fi
dc exec -T -u www-data app php artisan optimize
"$DEPLOY_DIR/scripts/healthcheck.sh"
log "Déploiement terminé. Retour arrière : voir docs/MIGRATION-UVMASTER.md (section « Retour arrière »)."
