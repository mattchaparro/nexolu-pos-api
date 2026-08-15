#!/bin/bash
# Deploy de ESTE servicio, independiente de los otros 3. Corre desde el
# droplet, asumiendo la estructura de hermanos de nexolu-infra (ver su
# README.md): este repo y nexolu-infra clonados uno al lado del otro.
#
# NUNCA corre `php artisan migrate` - el esquema (85 tablas) viene de
# database/legacy-schema/schema.sql, cargado a mano una sola vez (ver
# nexolu-infra/README.md paso 5). Un `migrate` automatico aca es
# exactamente lo que prohibe CLAUDE.md de este repo.
set -e
cd "$(dirname "$0")"

echo "==> git pull"
git pull origin main

echo "==> Reconstruyendo y reiniciando pos-web, pos-queue, pos-scheduler"
cd ../nexolu-infra
docker compose build pos-web
docker compose up -d pos-web pos-queue pos-scheduler

echo "==> Aplicando schema patches pendientes (tablas nuevas desde el ultimo deploy, ver database/legacy-schema/patches/README.md)"
docker compose exec -T pos-web php artisan schema:apply-patches

echo "==> Sincronizando permisos del catalogo (idempotente, no borra nada)"
docker compose exec -T pos-web php artisan permissions:sync

echo "==> Confirmando que el worker de cola quedo arriba (no queue:listen)"
docker compose logs --tail=5 pos-queue

echo "==> Listo. Verificar: curl -s https://api.nexolu.co/up"
