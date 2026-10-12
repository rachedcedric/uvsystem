#!/bin/sh
# Préparation commune. Rôle « app » (root, supervisord) : droits sur les volumes + caches Laravel.
# Rôles « queue » / « scheduler » : lancés directement en utilisateur www-data (voir le compose), sans préparation.
set -e
cd /var/www/html

if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
    chmod -R ug+rwX storage bootstrap/cache
    # Lien public/storage (logos d'entreprise) : idempotent.
    su-exec www-data php artisan storage:link --force >/dev/null 2>&1 || true
    # Caches de configuration, routes, vues, événements : calculés avec l'environnement RÉEL du conteneur.
    if [ "${APP_ENV:-production}" = "production" ]; then
        su-exec www-data php artisan optimize:clear >/dev/null 2>&1
        su-exec www-data php artisan optimize >/dev/null 2>&1 || echo "[entrypoint] optimize ignoré (configuration incomplète ?)" >&2
    fi
fi

exec "$@"
