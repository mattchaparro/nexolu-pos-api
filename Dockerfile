# Imagen unica para los 3 procesos de pos-api en produccion (web, worker de
# cola, scheduler) - docker-compose.yml en nexolu-infra/ arranca los tres
# servicios desde esta MISMA imagen, cambiando solo el comando. Ver
# deploy/README.md para el runbook completo del droplet.

# ---- Etapa 1: build de assets (Vite) ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# ---- Etapa 2: dependencias de Composer (sin dev, con autoload optimizado) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---- Etapa 3: runtime (PHP-FPM + Nginx + Supervisor en un solo contenedor) ----
FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor git unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libzip-dev libpng-dev libonig-dev \
    && apt-get install -y --no-install-recommends libzip4 \
    && rm -rf /var/lib/apt/lists/*
# `apt-get purge --auto-remove libzip-dev` se lleva de arrastre libzip4 (la
# libreria RUNTIME, no solo la de desarrollo) porque en ese punto nada mas
# la referencia a nivel de paquetes - aunque la extension `zip.so` que
# `docker-php-ext-install` acaba de compilar SI la necesita para cargar en
# runtime. Sin este re-install explicito, PHP arranca con
# "Unable to load dynamic library 'zip' ... libzip.so.4: cannot open shared
# object file" (visto en vivo el 2026-08-20) - silencioso en el healthcheck
# basico, rompe cualquier feature que use ZipArchive.

# opcache en produccion: APP_ENV=production ya evita el filesystem stat en
# cada request si opcache.validate_timestamps=0, pero eso exige reiniciar
# php-fpm en cada deploy (el entrypoint no lo hace por si solo) - lo dejamos
# en 1 (default) para no sorprender a un deploy futuro que no reinicie.
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache-custom.ini

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
# Comando por defecto: el servicio web (nginx + php-fpm via supervisor).
# docker-compose.yml lo pisa para los servicios de worker/scheduler.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
