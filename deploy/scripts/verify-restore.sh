#!/usr/bin/env bash
# Preuve qu'une sauvegarde est exploitable : restaure dans une base TEMPORAIRE, compare les comptages, supprime la base.
# N'altère jamais la base de production. Usage : deploy/scripts/verify-restore.sh <dossier_de_sauvegarde>
source "$(dirname "$0")/common.sh"; need_env
SRC="${1:?dossier de sauvegarde requis}"; ROOTPW="$(env_get DB_ROOT_PASSWORD)"
TMP="restore_check_$(date +%s)"
trap 'dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot -e "DROP DATABASE IF EXISTS \`$TMP\`" >/dev/null 2>&1 || true' EXIT
( cd "$SRC" && sha256sum -c SHA256SUMS >/dev/null ) || { echo "ÉCHEC : empreintes invalides" >&2; exit 1; }
dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot -e "CREATE DATABASE \`$TMP\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gzip -dc "$SRC/db.sql.gz" | dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot "$TMP"
BAD=0
while IFS=$'\t' read -r t expected; do
  [ "$expected" = "NA" ] && continue
  got=$(dc exec -T -e MYSQL_PWD="$ROOTPW" db mysql -uroot -N -B "$TMP" -e "SELECT COUNT(*) FROM \`$t\`" 2>/dev/null || echo "MANQUANTE")
  if [ "$got" != "$expected" ]; then echo "ÉCART $t : sauvegarde=$expected restauré=$got"; BAD=1; else echo "OK    $t = $got"; fi
done < "$SRC/counts.tsv"
tar -tzf "$SRC/storage.tar.gz" >/dev/null && echo "OK    archive des fichiers lisible ($(tar -tzf "$SRC/storage.tar.gz" | wc -l | tr -d ' ') entrées)"
[ "$BAD" = 0 ] && log "VÉRIFICATION RÉUSSIE : la sauvegarde est restaurable." || { log "VÉRIFICATION ÉCHOUÉE"; exit 1; }
