#!/usr/bin/env bash
# Restauration d'une sauvegarde. DESTRUCTIF pour la base cible : exige --yes et une confirmation du nom de base.
# Usage : deploy/scripts/restore.sh <dossier_de_sauvegarde> [--target-db NOM] --yes
source "$(dirname "$0")/common.sh"; need_env
SRC="${1:?dossier de sauvegarde requis}"; shift || true
TARGET="$(env_get DB_DATABASE)"; YES=0
while [ $# -gt 0 ]; do case "$1" in --target-db) TARGET="$2"; shift 2;; --yes) YES=1; shift;; *) echo "option inconnue $1" >&2; exit 2;; esac; done
[ -f "$SRC/SHA256SUMS" ] || { echo "ERREUR : $SRC/SHA256SUMS absent" >&2; exit 1; }
( cd "$SRC" && sha256sum -c SHA256SUMS ) || { echo "ERREUR : empreintes invalides, restauration refusée" >&2; exit 1; }
[ "$YES" = 1 ] || { echo "Ajouter --yes pour confirmer (la base « $TARGET » sera REMPLACÉE)." >&2; exit 1; }
ROOTPW="$(env_get DB_ROOT_PASSWORD)"
log "Recréation de la base « $TARGET » puis import…"
dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot -e "DROP DATABASE IF EXISTS \`$TARGET\`; CREATE DATABASE \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -dc "$SRC/db.sql.gz" | dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot "$TARGET"
if [ "$TARGET" = "$(env_get DB_DATABASE)" ] && [ -f "$SRC/storage.tar.gz" ]; then
  log "Restauration des fichiers téléversés…"
  VOL="$(dc ps -q app | xargs docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{.Name}}{{end}}{{end}}')"
  docker run --rm -i -v "$VOL":/data alpine sh -c 'tar -C /data -xzf - && chown -R 82:82 /data' < "$SRC/storage.tar.gz"
fi
log "Restauration terminée."
