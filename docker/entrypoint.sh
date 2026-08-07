#!/bin/sh
set -e

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
