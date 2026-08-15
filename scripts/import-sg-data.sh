#!/usr/bin/env bash
#
# Espejo de datos reales de staging (sg, pos-sg.nexolu.co) hacia el MySQL
# local de Sail, para desarrollar/probar contra datos parecidos a produccion
# en vez de una base vacia con un solo usuario demo.
#
#   bash scripts/import-sg-data.sh
#
# QUE HACE
# --------
# 1. Por SSH, vuelca SOLO DATOS (--no-create-info) de pos_saas_sg - nunca la
#    estructura: la estructura local ya viene de database/legacy-schema/schema.sql,
#    que a la fecha de este script esta AL DIA (incluso un poco adelante -
#    ver product_categories.parent_id / reminders.notify_* - de sg, asi que
#    cargar solo datos sobre ese schema es seguro).
# 2. Vacia (TRUNCATE) las tablas de negocio locales y carga el volcado encima.
#    Esto REEMPLAZA por completo los datos locales de esas tablas - cualquier
#    cosa que hayas creado a mano localmente en esas tablas se pierde.
# 3. Recrea el usuario demo (demo@nexolu.test / password123) con un id nuevo,
#    sin chocar con los ids reales importados de sg.
#
# QUE NO HACE (a proposito)
# --------------------------
# - No corrige el bug de CUTOVER_TODO.md #3 (8 filas de stock_movements con
#   type='' en sg): las importa tal cual estan, para que sigan siendo
#   reproducibles localmente. Solo relaja sql_mode lo necesario para que el
#   INSERT no falle bajo STRICT_TRANS_TABLES (igual que corre en produccion).
# - No toca sg/produccion en ningun punto: todo el trafico hacia el servidor
#   remoto es un mysqldump de lectura.
# - El vocabulario de payment_method queda con la misma inconsistencia que
#   tiene sg (ver CUTOVER_TODO.md #1) - correr aparte
#   `php artisan legacy:normalize-payment-methods` si haces falta esa
#   normalizacion (ver docblock de ese comando: tambien queda solo local).
# - No crea ni actualiza las tablas nuevas propias de esta API
#   (pos_payment_methods, business_pos_payment_methods, whatsapp_logs - ver
#   database/legacy-schema/patches/): sg no las tiene, asi que no aparecen
#   en el volcado y quedan intactas. Corre `php artisan schema:apply-patches`
#   aparte si tu local todavia no las tiene.
#
# Re-ejecutable: cada corrida reemplaza los datos locales por un snapshot
# fresco de sg. Nunca se auto-ejecuta ni se agenda - es una accion manual.

set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

SG_SERVER="${SG_SERVER:-root@104.248.230.120}"
SG_ENV_PATH="${SG_ENV_PATH:-/var/www/pos-sg.nexolu.co/.env}"

# Tablas transitorias / de seguridad que nunca tiene sentido copiar a un
# laptop de desarrollo: sesiones y tokens reales de usuarios de sg, colas,
# y la tabla `migrations` (este repo no usa migraciones de Laravel).
TABLAS_EXCLUIDAS=(sessions cache cache_locks personal_access_tokens password_resets failed_jobs migrations)

echo "[import-sg] Origen: $SG_SERVER ($SG_ENV_PATH)"
echo "[import-sg] Esto REEMPLAZA los datos locales de pos_saas por un snapshot de sg."
read -r -p "[import-sg] Continuar? [y/N] " CONFIRMA
if [[ "$CONFIRMA" != "y" && "$CONFIRMA" != "Y" ]]; then
    echo "[import-sg] Cancelado."
    exit 1
fi

if ! docker compose ps mysql --status running >/dev/null 2>&1 || [ -z "$(docker compose ps -q mysql)" ]; then
    echo "[import-sg] ERROR: el contenedor mysql de Sail no esta corriendo (docker compose up -d)." >&2
    exit 1
fi

DB_PASSWORD_LOCAL="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)"
DB_DATABASE_LOCAL="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)"

DUMP_FILE="$(mktemp)"
trap 'rm -f "$DUMP_FILE"' EXIT

IGNORE_FLAGS=()
for t in "${TABLAS_EXCLUIDAS[@]}"; do
    IGNORE_FLAGS+=(--ignore-table="pos_saas_sg.$t")
done

echo "[import-sg] 1/3 Volcando datos de sg por SSH (solo datos, sin estructura)..."
ssh "$SG_SERVER" '
    set -a; . <(grep -E "^DB_" '"$SG_ENV_PATH"'); set +a
    mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD" \
        --no-create-info --single-transaction --skip-lock-tables --skip-comments \
        --skip-add-locks --complete-insert \
        '"$(printf '%q ' "${IGNORE_FLAGS[@]}")"' \
        "$DB_DATABASE" 2>/dev/null
' > "$DUMP_FILE"

TABLAS_A_VACIAR=$(grep -oP "(?<=^INSERT INTO \`)[^\`]+" "$DUMP_FILE" | sort -u)

if [ -z "$TABLAS_A_VACIAR" ]; then
    echo "[import-sg] ERROR: el volcado vino vacio - revisa credenciales/conexion a $SG_SERVER." >&2
    exit 1
fi

echo "[import-sg] 2/3 Vaciando y cargando $(echo "$TABLAS_A_VACIAR" | wc -l) tablas en local ($DB_DATABASE_LOCAL)..."

{
    echo "SET FOREIGN_KEY_CHECKS=0;"
    echo "SET SESSION sql_mode='';"
    for t in $TABLAS_A_VACIAR; do
        echo "TRUNCATE TABLE \`$t\`;"
    done
    cat "$DUMP_FILE"
    echo "SET FOREIGN_KEY_CHECKS=1;"
} | docker compose exec -T mysql mysql -uroot -p"$DB_PASSWORD_LOCAL" "$DB_DATABASE_LOCAL"

echo "[import-sg] 3/3 Apuntando demo@nexolu.test al negocio 5 (Restaurante de prueba)..."
# A proposito NO se crea un negocio nuevo vacio: el punto de importar sg es
# tener datos reales para ver/probar. El negocio 5 ("Restaurante de prueba")
# es el elegido a proposito por Mateo: tiene TODOS los feature_flags en
# true, asi que es el unico que deja probar cualquier modulo sin toparse con
# un feature apagado. Si el id de este negocio cambia alguna vez en sg,
# actualizar el 5 de abajo.
docker compose exec -T laravel.test php artisan tinker --execute='
    $b = \App\Models\Business::withCount("sales")->findOrFail(5);
    $u = \App\Models\User::updateOrCreate(
        ["email" => "demo@nexolu.test"],
        ["name" => "Demo Nexolu", "password" => bcrypt("password123"), "business_id" => $b->id, "is_active" => true, "is_business_owner" => true]
    );
    $u->syncRoles(["admin"]);
    echo $u->email . " -> " . $b->name . " (business_id=" . $b->id . ", " . $b->sales_count . " ventas)";
'

echo
echo "[import-sg] Listo. Datos de sg cargados. El bug de stock_movements.type=\"\" (CUTOVER_TODO.md #3)"
echo "[import-sg] y la inconsistencia de payment_method (#1) siguen presentes tal cual estan en sg."
echo "[import-sg] Para lo segundo: php artisan legacy:normalize-payment-methods --dry-run"
