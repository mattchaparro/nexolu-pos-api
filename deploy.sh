#!/bin/bash
# Deploy de ESTE servicio, independiente de los otros 3. Corre desde el
# droplet, asumiendo la estructura de hermanos de nexolu-infra (ver su
# README.md): este repo y nexolu-infra clonados uno al lado del otro.
#
# El esquema base (85 tablas) viene de database/legacy-schema/schema.sql,
# cargado a mano una sola vez (ver nexolu-infra/README.md paso 5) - pero
# desde el baseline del 2026-08-20 (ver CLAUDE.md "Database & migrations"),
# todo cambio de esquema NUEVO es una migracion real de Laravel, no un
# patch de database/legacy-schema/patches/ (ese sistema quedo congelado
# ese dia, ver su README). Este script corria schema:apply-patches en vez
# de `migrate` - correcto para el sistema viejo, pero desde entonces
# cualquier migracion nueva (ver database/migrations/, todo lo fechado
# 2026-08-2x en adelante) nunca se aplicaba en SG/produccion pese a que el
# codigo si se desplegaba - bug real reportado 2026-08-24. migrate:baseline
# antes de migrate es defensivo/idempotente (ver SeedMigrationsBaseline):
# no hace nada si el baseline ya esta marcado, asi que es seguro dejarlo en
# cada deploy en vez de asumir que alguien ya lo corrio a mano una vez.
set -e
cd "$(dirname "$0")"

echo "==> git pull"
git pull origin main

echo "==> Reconstruyendo y reiniciando pos-web, pos-queue, pos-scheduler"
cd ../nexolu-infra
docker compose build pos-web
docker compose up -d pos-web pos-queue pos-scheduler

echo "==> Adoptando el baseline de schema.sql si todavia no esta marcado (idempotente, no hace nada si ya corrio)"
docker compose exec -T pos-web php artisan migrate:baseline

echo "==> Corriendo migraciones pendientes"
docker compose exec -T pos-web php artisan migrate --force

echo "==> Sincronizando permisos del catalogo (idempotente, no borra nada)"
docker compose exec -T pos-web php artisan permissions:sync

echo "==> Confirmando que el worker de cola quedo arriba (no queue:listen)"
docker compose logs --tail=5 pos-queue

echo "==> Listo. Verificar: curl -s https://api.nexolu.co/up"
