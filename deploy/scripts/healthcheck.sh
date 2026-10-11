#!/usr/bin/env bash
# Contrôle de santé de la pile (code de sortie 0 = sain). Utilisable par cron / supervision externe.
source "$(dirname "$0")/common.sh"; need_env
FAIL=0
for s in app queue scheduler db caddy; do
  state="$(dc ps --format '{{.Service}} {{.State}} {{.Health}}' | awk -v s="$s" '$1==s{print $2" "$3}')"
  case "$state" in "running healthy"|"running ") log "OK    $s ($state)";; *) log "KO    $s ($state)"; FAIL=1;; esac
done
DOM="$(env_get APP_DOMAIN)"
# HEALTH_URL / HEALTH_CURL_OPTS : surcharges pour un test local (port ou certificat non standard).
URL="${HEALTH_URL:-https://$DOM/api/health}"
# shellcheck disable=SC2086
code="$(curl -sS ${HEALTH_CURL_OPTS:-} -o /dev/null -w '%{http_code}' --max-time 10 "$URL" || echo 000)"
[ "$code" = "200" ] && log "OK    $URL = 200" || { log "KO    $URL = $code"; FAIL=1; }
exit $FAIL
