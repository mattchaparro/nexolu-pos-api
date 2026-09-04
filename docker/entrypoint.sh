#!/bin/sh
set -e

# El chown -R www-data:www-data del Dockerfile solo cubre lo que existe AL
# BUILD (directorios vacios) - `storage/` vive en un volumen nombrado
# (pos_storage en nexolu-infra/docker-compose.yml) que persiste entre
# builds, y cualquier archivo NUEVO dentro (ej. storage/logs/laravel.log,
# creado la primera vez que algo escribe ahi) hereda el usuario de quien lo
# creo, no el del directorio padre. Si eso pasa siendo root (ej. `docker
# compose exec` sin `-u www-data`, como corren hoy los artisan de
# deploy.sh), PHP-FPM (que corre como www-data) despues no puede escribir
# su propio log - una excepcion real queda sin loguear y la request explota
# en un 500 sin rastro (visto en vivo el 2026-08-20, costo horas de debug).
# Reafirmar el dueno en cada arranque del contenedor es robusto contra
# cualquier causa futura de este mismo problema, no solo la de hoy.
chown -R www-data:www-data /var/www/html/storage

# El enlace public/storage -> storage/app/public, que es por donde nginx
# sirve las fotos del catalogo y de la tienda (disco `public`).
#
# Se rehace en CADA arranque a proposito: `public/` viene dentro de la imagen
# y `storage/` es un volumen nombrado, asi que el enlace no sobrevive a un
# build nuevo -- y sin el, las fotos se guardan bien y devuelven 404, que es
# peor que fallar al subirlas.
#
# `ln -sfn` y no `php artisan storage:link`: no necesita levantar Laravel, es
# idempotente, y evita que un artisan corriendo como root deje archivos de
# log suyos en storage/ (justo lo que el chown de arriba viene a arreglar).
ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

# NUNCA se corre `php artisan migrate` aca -- el esquema de esta app viene
# completo de database/legacy-schema/schema.sql, cargado UNA sola vez a mano
# contra MySQL (ver deploy/README.md paso "Cargar el esquema"). Un
# `artisan migrate` automatico en cada arranque de contenedor es exactamente
# lo que CLAUDE.md de este repo prohibe: en un MySQL con schema.sql ya
# cargado, las migraciones default de Laravel fallarian ("table already
# exists"); en uno vacio crearian una tabla `users` incompleta.

# Cachear config/rutas/vistas es seguro y deterministico -- se corre en los
# 3 servicios (web, worker, scheduler) cada vez que arrancan, sin tocar la
# base de datos.
su -s /bin/sh www-data -c "php artisan config:cache"
su -s /bin/sh www-data -c "php artisan route:cache"
su -s /bin/sh www-data -c "php artisan view:cache"

exec "$@"
