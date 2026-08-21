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
# Alpine en vez de bookworm (Debian) a proposito: mismo runtime, imagen
# final 907MB -> 293MB (-68%), verificado en vivo el 2026-08-20 (build +
# arranque real de nginx/php-fpm via supervisor + entrypoint.sh completo +
# las 4 extensiones cargando bien, contra este mismo Dockerfile). git y
# unzip NO se instalan aca: eran solo del build (`git pull`/`composer`
# corren en el host y en la etapa `vendor` respectivamente), nada en
# runtime (entrypoint.sh, nginx.conf, supervisord.conf) los usa.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor libzip tzdata \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev libpng-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql zip opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build-deps
# tzdata: sin esto, TZ=America/Bogota (ver nexolu-infra/docker-compose.yml)
# no resuelve a nada - Alpine no trae la base de datos de zonas horarias
# por defecto. Laravel/Carbon ya funcionan bien sin esto (traen su propia
# tabla de timezones vía ext-date), pero `date` de shell, logs de
# supervisor/nginx, y cualquier cosa a nivel de SO seguian en UTC.
# Mismo patron que el purge de Debian que reemplaza (ver git blame): `apk
# add --no-cache libzip` ANTES del build-deps deja la libreria runtime
# instalada por fuera del grupo virtual, asi que `apk del .build-deps`
# (que se lleva libzip-dev) nunca se lleva de arrastre el `.so` que la
# extension `zip.so` necesita para cargar.

# opcache en produccion: APP_ENV=production ya evita el filesystem stat en
# cada request si opcache.validate_timestamps=0, pero eso exige reiniciar
# php-fpm en cada deploy (el entrypoint no lo hace por si solo) - lo dejamos
# en 1 (default) para no sorprender a un deploy futuro que no reinicie.
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache-custom.ini

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
# Comando por defecto: el servicio web (nginx + php-fpm via supervisor).
# docker-compose.yml lo pisa para los servicios de worker/scheduler.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
