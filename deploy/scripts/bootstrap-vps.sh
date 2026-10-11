#!/usr/bin/env bash
# Préparation d'un VPS Ubuntu/Debian pour UVMASTER.
#   --inspect  : LECTURE SEULE. Décrit l'état du serveur (services existants, ports, ressources). À lancer en premier.
#   --apply    : installe/configure (Docker, pare-feu 22/80/443, fail2ban, mises à jour automatiques, utilisateur « uvmaster »).
# Ne suppose pas que le serveur est vide : --apply refuse de continuer si les ports 80/443 sont déjà occupés.
set -euo pipefail
MODE="${1:---inspect}"
say() { printf '%s\n' "$*"; }
inspect() {
  say "== Système";   . /etc/os-release; say "$PRETTY_NAME | noyau $(uname -r)"
  say "== Ressources"; say "CPU: $(nproc) | RAM: $(free -m | awk '/Mem:/{print $2" Mo"}') | swap: $(free -m | awk '/Swap:/{print $2" Mo"}')"; df -h / | tail -1
  say "== Ports en écoute"; (ss -ltnp 2>/dev/null || netstat -ltnp 2>/dev/null) | awk 'NR==1 || /:(22|80|443|3306|5432|6379|8080) /'
  say "== Services web/base déjà présents"; for s in nginx apache2 httpd caddy mysql mariadb postgresql redis-server docker; do systemctl is-active --quiet "$s" 2>/dev/null && say "ACTIF : $s"; done || true
  say "== Docker"; command -v docker >/dev/null && docker --version && docker ps --format '{{.Names}}\t{{.Image}}\t{{.Ports}}' || say "docker absent"
  say "== Pare-feu"; (command -v ufw >/dev/null && ufw status verbose) || say "ufw absent"
  say "== Mises à jour de sécurité en attente"; (apt list --upgradable 2>/dev/null | grep -ci security) || true
}
apply() {
  [ "$(id -u)" = 0 ] || { say "Exécuter en root." >&2; exit 1; }
  if ss -ltn | awk '{print $4}' | grep -qE ':(80|443)$'; then say "REFUS : un service écoute déjà sur 80/443. Lancer --inspect, identifier le service, ne rien écraser." >&2; exit 1; fi
  apt-get update -y && apt-get install -y ca-certificates curl gnupg ufw fail2ban unattended-upgrades
  command -v docker >/dev/null || { curl -fsSL https://get.docker.com | sh; }
  id uvmaster >/dev/null 2>&1 || adduser --disabled-password --gecos "" uvmaster
  usermod -aG docker uvmaster
  ufw default deny incoming; ufw default allow outgoing; ufw allow 22/tcp; ufw allow 80/tcp; ufw allow 443/tcp; ufw allow 443/udp; ufw --force enable
  systemctl enable --now fail2ban unattended-upgrades
  install -d -o uvmaster -g uvmaster -m 750 /opt/uvmaster /opt/uvmaster/backups
  say "Serveur préparé. Étape suivante : copier le dépôt dans /opt/uvmaster et renseigner deploy/.env.production."
}
case "$MODE" in --inspect) inspect;; --apply) apply;; *) say "usage: $0 --inspect | --apply" >&2; exit 2;; esac
