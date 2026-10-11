# syntax=docker/dockerfile:1.7
# ==========================================================================================
# UVMASTER — image de production (PHP-FPM + nginx dans le même conteneur, via supervisord).
# Le même conteneur sert l'application (rôle « app »), le worker de files et le planificateur
# (rôles « queue » et « scheduler », voir deploy/docker-compose.prod.yml).
# ==========================================================================================
ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---- 1. Ressources front (Vite + Tailwind) ------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
# Tailwind 4 détecte ses classes dans les sources du projet (vues Blade, composants PHP).
COPY app ./app
COPY public ./public
RUN npm run build

# ---- 2. Image PHP de base --------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base
RUN apk add --no-cache nginx supervisor curl tzdata icu-libs libpng libjpeg-turbo freetype libzip oniguruma su-exec mysql-client mariadb-connector-c zip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint && rm -f /usr/local/etc/php-fpm.d/zz-docker.conf
WORKDIR /var/www/html

# ---- 3. Dépendances Composer (sans outils de développement) ------------------------------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction

# ---- 4. Image finale -------------------------------------------------------------------
FROM base AS production
ENV APP_ENV=production APP_DEBUG=false
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD curl -fsS http://127.0.0.1/api/health || exit 1
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
