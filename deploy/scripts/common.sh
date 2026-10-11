#!/usr/bin/env bash
# Fonctions communes. Se place dans deploy/ pour trouver le compose et .env.production.
set -euo pipefail
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$DEPLOY_DIR"
ENV_FILE="${ENV_FILE:-.env.production}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
dc() { docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"; }
need_env() { [ -f "$ENV_FILE" ] || { echo "ERREUR : $ENV_FILE introuvable (copier .env.production.example)." >&2; exit 1; }; }
env_get() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
