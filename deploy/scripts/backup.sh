#!/usr/bin/env bash
# Sauvegarde cohérente : dump MySQL (transaction unique) + fichiers téléversés (volume storage) + empreintes + manifeste.
# Usage : deploy/scripts/backup.sh [dossier_de_sortie]   (défaut : deploy/backups). Rétention : BACKUP_KEEP_DAYS (défaut 14).
source "$(dirname "$0")/common.sh"; need_env
OUT="${1:-$DEPLOY_DIR/backups}"; KEEP="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"; DIR="$OUT/$STAMP"; mkdir -p "$DIR"; chmod 700 "$OUT" "$DIR"
DBN="$(env_get DB_DATABASE)"; ROOTPW="$(env_get DB_ROOT_PASSWORD)"

log "Dump de la base ${DBN}…"
dc exec -T -e MYSQL_PWD="$ROOTPW" db mysqldump -uroot --single-transaction --quick --routines --triggers --events --no-tablespaces \
    --default-character-set=utf8mb4 "$DBN" | gzip -9 > "$DIR/db.sql.gz"
[ "$(gzip -dc "$DIR/db.sql.gz" | tail -c 400 | grep -c 'Dump completed')" -ge 1 ] || { echo "ERREUR : dump incomplet" >&2; exit 1; }

log "Archive des fichiers téléversés (volume storage)…"
VOL="$(dc ps -q app | xargs docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{.Name}}{{end}}{{end}}')"
docker run --rm -v "$VOL":/data:ro alpine tar -C /data -czf - . > "$DIR/storage.tar.gz"

log "Comptages de contrôle…"
dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot -N -B "$DBN" -e \
 "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema='$DBN' ORDER BY table_name" > "$DIR/tables.tsv"
for t in tenants users agents uv_transactions remboursements approvisionnements charges commissions caisse_mouvements; do
  c=$(dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot -N -B "$DBN" -e "SELECT COUNT(*) FROM \`$t\`" 2>/dev/null || echo "NA")
  echo -e "$t\t$c"
done > "$DIR/counts.tsv"

( cd "$DIR" && sha256sum db.sql.gz storage.tar.gz tables.tsv counts.tsv > SHA256SUMS )
echo "{\"created_utc\":\"$STAMP\",\"database\":\"$DBN\",\"app_version\":\"$(env_get APP_VERSION)\"}" > "$DIR/manifest.json"
find "$OUT" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP" -exec rm -rf {} +
log "Sauvegarde terminée : $DIR ($(du -sh "$DIR" | cut -f1))"
echo "$DIR"
