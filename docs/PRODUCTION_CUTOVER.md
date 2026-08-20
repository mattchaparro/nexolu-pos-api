# Runbook de despliegue a producción

Documento vivo — se va completando a medida que se resuelven las
"Decisiones abiertas" de la sección 4. No es una guía de un solo repo: cruza
`nexolu-infra` (la infraestructura del droplet), este repo (`nexolu-pos-api`)
y `docs/CUTOVER_TODO.md` (la deuda técnica que bloquea algunos pasos).

## 0. Vocabulario

**Corrección (2026-08-15):** la primera versión de este documento definía
"SG" como la base de producción real del monolito legacy. Es incorrecto —
`docs/LOCAL_DATA_IMPORT.md` (y `scripts/import-sg-data.sh`) dejan claro que
**SG es staging** (`pos-sg.nexolu.co`), no producción. Corregido abajo, pero
queda una pregunta abierta real sobre dónde vive la producción de verdad
(ver § 4.5).

- **Droplet nuevo**: el DigitalOcean droplet que corre `api.nexolu.co` (ver
  `nexolu-infra/README.md`) — infraestructura **separada** de donde vive hoy
  el monolito legacy (`pos.nexolu.co`). No comparten servidor ni, hoy, base
  de datos.
- **SG (staging)**: `pos-sg.nexolu.co` — un ambiente de staging del monolito
  legacy con datos "reales" (no sintéticos, pero tampoco necesariamente el
  tráfico productivo en vivo). Es lo que `scripts/import-sg-data.sh` trae a
  local para desarrollar/probar, y lo que el usuario pidió normalizar
  primero como ensayo ("antes de pasar los clientes a esta nueva api vamos
  a probar normalizando la base de datos de SG") antes de tocar producción
  real.
- **Producción real**: `pos.nexolu.co`, la que sirve a los negocios que
  hoy pagan y usan el sistema en vivo. Su relación exacta con SG (¿SG es
  una copia periódica de esta, o es independiente?) y con el droplet nuevo
  no está confirmada todavía — ver § 4.5.
- **Cutover**: el momento en que uno o más negocios reales pasan de ser
  servidos por el monolito legacy a ser servidos por `api.nexolu.co` /
  `nexolu-pos-front`.

Hay dos escenarios completamente distintos que este documento cubre por
separado porque tienen riesgo muy diferente:

1. **Desplegar el droplet nuevo** (secciones 1-2) — infraestructura vacía,
   sin datos reales de negocios todavía. Bajo riesgo, ya bastante
   automatizado.
2. **Traer negocios reales** (sección 4) — datos de negocios reales,
   clientes reales, ensayado primero contra SG (staging). Alto riesgo,
   requiere pruebas primero, varias decisiones sin tomar todavía (ver 4.5).

## 1. Deploy nuevo (droplet fresco, primera vez)

Ya documentado en detalle en `nexolu-infra/README.md` — no se repite aquí,
solo el resumen con los pasos de **este** repo intercalados:

1. Provisionar el droplet, DNS de los 4 dominios (`nexolu-infra` § 1).
2. `bootstrap.sh` + configurar los 4 `.env` (`nexolu-infra` § 2-3).
3. certbot para TLS (`nexolu-infra` § 4).
4. Levantar `mysql`/`redis`, cargar `schema.sql` **una sola vez**
   (`nexolu-infra` § 5):
   ```bash
   docker compose exec -T mysql mysql -unexolu -p<MYSQL_APP_PASSWORD> pos_saas \
     < ../nexolu-pos-api/database/legacy-schema/schema.sql
   ```
5. Desplegar los 4 servicios. Para `pos-api`, correr `./deploy.sh` — desde
   este commit ya aplica solo los patches pendientes de
   `database/legacy-schema/patches/` y sincroniza permisos
   (`schema:apply-patches` + `permissions:sync`), así que un droplet
   recién creado queda al día sin pasos manuales extra.
6. **Seeders de catálogo** (idempotentes, seguros en cualquier momento —
   nunca la `DatabaseSeeder` completa, esa trae un negocio y un superadmin
   de prueba con credenciales conocidas, solo sirve en local):
   ```bash
   docker compose exec -T pos-web php artisan db:seed --class=RoleSeeder --force
   docker compose exec -T pos-web php artisan db:seed --class=StockMovementReasonSeeder --force
   docker compose exec -T pos-web php artisan db:seed --class=PosPaymentMethodSeeder --force
   ```
7. **Superadmin real** (no hay comando dedicado todavía — manual, una vez):
   ```bash
   docker compose exec -T pos-web php artisan tinker --execute '
     $u = App\Models\User::create([
       "name" => "Nombre Real",
       "email" => "correo-real@nexolu.co",
       "password" => bcrypt("una-clave-fuerte-real"),
       "business_id" => null,
       "is_active" => true,
     ]);
     $u->assignRole("superadmin");
   '
   ```
8. Verificar (sección 5).

En este punto el droplet está sano, con el catálogo base cargado, pero
**sin ningún negocio real** — un negocio que se registre desde
`nexolu-pos-front` a partir de aquí funciona normal (flujo ya construido:
wizard de registro, WhatsApp obligatorio, etc). Los negocios que hoy están
en SG siguen sin tocar.

### 1.1. Medios de pago (Wompi / Nexolu Payments Core)

Sin esto, la app queda arriba pero sin poder cobrar: el "negocio que se
registra funciona normal" de arriba no cubre `PAYMENTS_CORE_API_KEY` — eso
no se genera solo, es un paso de provisioning aparte. Runbook completo
(Merchant, Integration en `environment=production`, credenciales reales de
Wompi, webhook, verificación) en
`nexolu-payments-core/docs/PRODUCTION_SETUP.md` — no se repite acá.

## 2. Deploys posteriores (código nuevo, sin negocios nuevos que migrar)

Ya automatizado:

```bash
cd /opt/nexolu/nexolu-pos-api && ./deploy.sh
```

`deploy.sh` hace `git pull` + rebuild + restart + `schema:apply-patches` +
`permissions:sync`. No requiere pasos manuales para lo que hoy sabemos que
puede cambiar (tablas nuevas, permisos nuevos). Si un cambio futuro agrega
un seeder nuevo con datos de catálogo (como `PosPaymentMethodSeeder`), hay
que acordarse de correrlo a mano una vez — no está automatizado en
`deploy.sh` a propósito, porque un seeder corriendo en cada deploy sin
supervisión es más riesgo del que vale la pena para algo que cambia rara
vez.

## 3. Qué NO hacer nunca

- `php artisan migrate` **antes de** correr `php artisan migrate:baseline` en
  este ambiente — ver "Database & migrations" en `CLAUDE.md`. Una vez el
  baseline está sembrado (fila en `migrations` marcando `schema.sql` como ya
  aplicado), `migrate` es seguro y es el camino normal para cualquier cambio
  de esquema nuevo.
- Correr la `DatabaseSeeder` completa (`db:seed` sin `--class`) contra
  producción — crea un negocio y un superadmin de prueba con credenciales
  hardcodeadas en el repo.
- Un `ALTER TABLE`/`DROP`/cambio de estructura a mano contra una tabla que
  el legacy todavía usa, sin coordinar — ver `docs/CUTOVER_TODO.md`.
- Apuntar `pos.nexolu.co` (legacy) a este droplet, o el droplet legacy a la
  base de datos nueva — son bases separadas hasta que la sección 4 se
  ejecute deliberadamente.

## 4. Cutover de negocios reales (legacy → droplet nuevo)

Esto es lo que falta decidir y construir antes de que un negocio real
pueda operar desde `api.nexolu.co`. La rehearsal completa (pasos 4.1-4.4)
ya se puede ensayar hoy contra **SG (staging)**, sin tocar producción —
eso es justo lo que `scripts/import-sg-data.sh` +
`legacy:normalize-payment-methods` ya permiten hacer en local (ver
`docs/LOCAL_DATA_IMPORT.md`). Lo que falta es repetir ese mismo ensayo
contra el droplet nuevo (no solo en un laptop) y, después, definir cómo se
hace la vez que sí sea con la producción real (§ 4.5).

### 4.1. Importar los datos (SG para ensayo; producción real cuando se decida 4.5)

```bash
# mysqldump --no-create-info --complete-insert, mismo patron que
# scripts/import-sg-data.sh (ver ese script para el detalle completo: tablas
# excluidas, etc.)
#
# --complete-insert es OBLIGATORIO, no opcional (verificado en vivo el
# 2026-08-20 contra el droplet SG real): mysqldump sin esa flag genera
# INSERTs posicionales (`INSERT INTO tabla VALUES (...)`, sin nombrar
# columnas). El origen (legacy o un dump viejo) casi seguro tiene MENOS
# columnas que schema.sql actual (client_id, tablas nuevas, etc. - ver §
# 4.2) porque este repo lo sigue extendiendo. Un INSERT posicional con
# distinto conteo de columnas que la tabla destino falla directo con
# "Column count doesn't match value count". Con --complete-insert cada
# INSERT nombra sus columnas explicitamente, asi que las columnas nuevas
# que el origen no tiene simplemente quedan en su DEFAULT (normalmente NULL)
# sin romper nada.
mysqldump -h<host> -u<user> -p<pass> --no-create-info --complete-insert \
  --single-transaction --quick --skip-triggers \
  --ignore-table=<db>.sessions --ignore-table=<db>.cache --ignore-table=<db>.cache_locks \
  --ignore-table=<db>.personal_access_tokens --ignore-table=<db>.password_resets \
  --ignore-table=<db>.failed_jobs --ignore-table=<db>.migrations --ignore-table=<db>.jobs \
  <db> | gzip > dump-$(date +%F).sql.gz

# En el droplet nuevo, contra una base YA VACIADA y con la estructura de
# schema.sql recien cargada (nunca --no-create-info sobre una base vacia
# sin estructura, y nunca sobre una base con datos viejos encima - dropear
# y recrear la base antes de este paso si no es la primera carga):
gunzip -c dump-$(date +%F).sql.gz | docker compose exec -T mysql mysql -unexolu -p<MYSQL_APP_PASSWORD> pos_saas
```

**Nunca sacar este dump del lado del cliente con la contraseña en texto
plano en el historial de shell/logs** - correr el `mysqldump` completo
DENTRO de una sesion ssh al servidor origen, leyendo la password desde su
propio `.env` en el mismo comando, para que el secreto nunca viaje ni quede
impreso en ningun lado fuera de ese servidor.

### 4.2. Poner al día el esquema

El dump (de SG o de producción real) **no tiene** las tablas agregadas
después de cualquier captura anterior (`whatsapp_logs`,
`pos_payment_methods`, `business_pos_payment_methods`, y las que se
sumen) — ninguna de las dos las tiene, porque son 100% nuevas de esta API,
el monolito legacy nunca las crea. Por eso existe `schema:apply-patches` —
correrlo aquí es obligatorio, no opcional:

```bash
docker compose exec -T pos-web php artisan schema:apply-patches
docker compose exec -T pos-web php artisan permissions:sync
```

### 4.3. Catálogo de medios de pago

```bash
docker compose exec -T pos-web php artisan db:seed --class=PosPaymentMethodSeeder --force
```

Esto solo crea el catálogo global (`pos_payment_methods`) — los negocios
importados **no quedan migrados automáticamente** a él todavía en este
paso; eso es 4.6.

### 4.4. Normalizar vocabulario de payment_method (ítem 1 de CUTOVER_TODO)

Ya construido: `php artisan legacy:normalize-payment-methods` (con
`--dry-run`) — ver el docblock de
`App\Console\Commands\LegacyNormalizePaymentMethods` y
`docs/LOCAL_DATA_IMPORT.md`.

**Guard actualizado (2026-08-20):** corre con `APP_ENV=local` **o**
`APP_ENV=staging` (antes solo `local`). Se decidió ampliarlo porque este
mismo comando se va a correr también contra el droplet de producción nueva
el día del cutover real (§ 4.7) — pero **siempre contra una base de
respaldo separada** (`new-pos-saas`, nunca la `pos_saas` que sirve tráfico
real), con ese droplet puesto en `APP_ENV=staging` para ese ensayo puntual.
**El guard protege el ambiente, no el nombre de la base** — sigue siendo
responsabilidad de quien lo corre verificar a mano que `DB_DATABASE` apunta
a la copia y no a la base viva antes de correr sin `--dry-run`. Sigue
rechazando `APP_ENV=production` sin excepción.

**Corrido de verdad contra el droplet `nexolu-pos-sg` (2026-08-20), con un
dump completo de producción real bajado ese mismo día** (no un dump viejo -
ver § 4.5bis): resultado idéntico al `--dry-run` previo, y **el mismo total
de `sales`** que la verificación de 2026-08-15 documentada abajo (8,988) -
buena señal de reproducibilidad, el problema sigue intacto en producción:

```
sales: 8988 filas cambiaron.
sale_payment_splits: 874 filas cambiaron.
receivables: 35 filas cambiaron.
service_payments: 0 filas cambiaron.
expenses: 135 filas cambiaron.
Total: 10032 filas normalizadas.
```

Verificación post-normalización: la app siguió respondiendo 200 en `/up`
después de correrlo, y es **normal y esperado** seguir viendo valores como
`efectivo`/`cash` conviviendo en la misma tabla `sales` tras la
normalización — cada negocio tiene su propio vocabulario configurado
(`Business::normalizePaymentMethodId()` resuelve por negocio, no hay un
único valor canónico global). No confundir eso con que la normalización no
corrió.

Esto es **distinto** de "migrar el catálogo" (§ 4.6/ítem 5) - este comando
unifica el vocabulario dentro de las tablas ya compartidas
(`sales`/`receivables`/`expenses`/`service_payments`), no crea filas en
`business_pos_payment_methods`.

**Verificado contra un dump real de producción (2026-08-15):** 49% de las
ventas (8,988 de 18,363) necesitan normalizarse — el problema es real y
grande, no cosmético. Correr esto antes de 4.6 (migrar al catálogo), para
que el catálogo se construya sobre datos históricos ya consistentes.

### 4.5. Contexto sobre SG vs. producción real

**Verificado empíricamente (2026-08-15):** SG **no tiene la misma
profundidad histórica** que producción real. Comparando negocio por negocio
entre un dump de "producción" y un snapshot local derivado de SG, varios
negocios tenían miles de ventas menos en SG (ej. negocio 6: 6,802 en SG vs.
9,507 en producción — faltan 2,705). La config y los negocios coinciden
(mismo `id`, mismo `created_at`), pero SG parece no conservar el historial
completo. **Conclusión: un ensayo que da "0 filas a normalizar" contra SG
no es evidencia de que producción esté limpia** — hay que verificar contra
un dump de producción real antes de confiar en un resultado de SG.

### 4.5bis. Bitácora del primer ensayo real: droplet `nexolu-pos-sg` (2026-08-20)

Primer ensayo end-to-end de esta sección, ya no en un laptop sino en un
droplet real de DigitalOcean (`nexolu-pos-sg`, `159.223.133.156`, región
`nyc1`). Hallazgos operativos que van a repetirse el día del cutover real si
no se tienen en cuenta:

**Tamaño del droplet — 512MB no alcanza, 1GB tampoco, 2GB recién queda
estable.** Se probaron los tres en el mismo día:
- `s-1vcpu-512mb-10gb` ($4/mes): MySQL murió por OOM literalmente al
  inicializar, antes de poder arrancar.
- `s-1vcpu-1gb` ($6/mes): alcanza para levantar todo, pero con los 6-8
  contenedores corriendo a la vez (mysql + redis + pos-web + pos-queue +
  pos-scheduler + frontend) el load average llegó a **52** en 1 vCPU y
  MySQL volvió a morir por OOM dos veces más bajo carga normal de uso (no
  picos, solo operación normal: un `docker compose build` + recarga de
  schema). Tres saturaciones completas (SSH sin responder varios minutos)
  en la misma sesión de trabajo.
- `s-1vcpu-2gb` ($12/mes): con esto el load volvió a ~1 después de levantar
  el stack completo (`pos-queue`/`pos-scheduler` incluidos, sin necesidad de
  pararlos) y quedó **1.3GB libres** de margen. Este es el tamaño mínimo
  recomendado para el droplet de SG a partir de ahora.

**Para el droplet de producción real, esto confirma
empíricamente — no es solo una recomendación teórica — el "2vCPU/4GB piso
razonable" que ya dice `nexolu-infra/README.md`.** No repetir el error de
subestimar el tamaño para ahorrar unos dólares/mes.

**Bug real encontrado: `storage/logs/laravel.log` queda `root:root` en vez
de `www-data:www-data`.** Causa: `docker compose exec` sin `-u www-data`
(el default es root) tocó el archivo antes que PHP-FPM (que corre como
`www-data`) — el volumen (`pos_storage`) no hereda el `chown` que sí hace
el `Dockerfile` en la imagen, porque un volumen nombrado nuevo pisa el
contenido de la imagen en el primer mount. Consecuencia: cualquier
excepción real durante una request queda **sin loguear**, y la respuesta es
un 500 genérico sin rastro — perdimos tiempo real pensando que un 500 no
tenía causa. Fix aplicado: `docker exec <container> chown -R www-data:www-data
storage`. **Pendiente para no repetirlo:** o bien `deploy.sh` corre ese
`chown` como parte del arranque (después de `docker compose up`, antes de
dar el deploy por terminado), o el `Dockerfile`/entrypoint lo hace en cada
arranque del contenedor (más robusto, sobrevive a cualquier `exec` futuro
corrido por error como root).

**Gotcha de Docker Compose: `restart` no relee `env_file`.** Cambiar una
variable en el `.env` de un servicio y correr `docker compose restart
<servicio>` reinicia el proceso con el entorno **viejo** con el que se creó
el contenedor — hace falta `docker compose up -d --force-recreate
<servicio>` (o `up -d` a secas, si Compose detecta un cambio real de
config) para que tome el `.env` actualizado. Costó un ciclo entero de
debugging (`VITE_API_BASE_URL` viejo sobreviviendo varios "restarts").

**`--complete-insert` en el dump de datos** — ver el fix ya aplicado en §
4.1 arriba. Encontrado al cargar un dump real de producción contra el
`schema.sql` actual: sin esa flag, cualquier tabla que este repo haya
extendido desde que se capturó el dump rompe la carga entera.

**nginx/certbot con subdominios `-sg` para el ensayo:** para no inventar
dominios de prueba (`.test`) ni exponer puertos sueltos por IP, se usó el
mismo dominio real (`nexolu.co`) con subdominios `-sg`:
`api-sg.nexolu.co`, `ia-sg.nexolu.co`, `comms-sg.nexolu.co`,
`payments-sg.nexolu.co`, `new-pos-sg.nexolu.co` — un solo `nginx` en el
droplet enrutando por `server_name`, un `certbot --nginx -d <dominio>` por
cada uno (certificados independientes, no wildcard). Mismo patrón que usan
los 4 `nginx/*.conf` de `nexolu-infra` para producción, solo con el sufijo
`-sg`. Ajustes que NO se subieron al repo compartido (son específicos de
este droplet, viven solo ahí): `docker-compose.override.yml` con
`innodb-buffer-pool-size` bajo (96M) para el tamaño de RAM, y el servicio
`frontend` corriendo el dev server de Vite (`nexolu-pos-front` todavía no
tiene `Dockerfile`/`deploy.sh` propio — pendiente para cuando ese repo esté
listo para producción real, ver `nexolu-infra/README.md`).

**El dump de producción se sacó del droplet legacy (`pos.nexolu.co`,
`134.122.116.201`) por SSH, de solo lectura, sin tocar la base viva** — el
`mysqldump` corrió íntegro dentro de una sesión ssh a ese servidor, la
contraseña se leyó de su propio `.env` en el mismo comando y nunca viajó ni
quedó impresa fuera de ahí (ver advertencia en § 4.1).

**Deploy keys de GitHub: una por repo, nunca la misma en dos repos.**
Para que `deploy.sh`/`deploy-menu.sh` puedan hacer `git pull` sin depender
de una sesión SSH humana con agent forwarding, el droplet necesita su
propia identidad de GitHub — pero **GitHub rechaza agregar la misma clave
pública como Deploy Key en más de un repo** ("Key is already in use").
Hace falta una clave ed25519 distinta por repo (`nexolu-infra`,
`nexolu-pos-api`, `nexolu-ia-core`, `nexolu-comms-api`,
`nexolu-payments-core`), cada una agregada solo de lectura (sin "Allow
write access") en `Settings → Deploy keys` de su propio repo. Para que
cada `git pull` use la clave correcta sin tocar la URL del remote ni
`~/.ssh/config` global, se configura por repo:
```bash
cd /opt/nexolu/<repo>
git config core.sshCommand "ssh -i ~/.ssh/deploy_keys/<repo> -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
```
`StrictHostKeyChecking=accept-new` (no `=no`) porque a diferencia de la
key de admin→SG (`app/infra/ssh_runner.py` en `nexolu-admin`, que sí usa
`=no` porque SG se recrea seguido y cambia de host key), acá el destino es
siempre `github.com`, que no cambia — no hace falta bajar la guardia ahí.

### 4.6. Migrar negocios al catálogo (ítem 5 de CUTOVER_TODO)

Ya construido: `php artisan payment-methods:migrate-catalog` (con
`--dry-run`, y `--business=ID` para uno solo) — ver el docblock de
`App\Console\Commands\MigratePaymentMethodsCatalog`. Matchea cada negocio
sin catálogo todavía contra `pos_payment_methods` por alias fijo
(cash↔efectivo, transfer↔transferencia, credit↔fiado/credito, y las demás
keys directas). Un negocio con **algún** método sin match migra los que sí
matchean y reporta el resto; un negocio **sin ningún** match se deja
intacto por completo. Nunca toca un negocio que ya tenga alguna fila de
pivote (no pisa una migración manual hecha desde Ajustes).

**No está bloqueado a `local`** (a diferencia de 4.4): las tablas que
escribe son 100% nuevas, el legacy nunca las lee, así que no hay riesgo de
esquema compartido. El riesgo es de negocio, no de esquema — por eso sigue
recomendándose `--dry-run` primero.

**Verificado contra un dump real de producción (2026-08-15):** 18 negocios,
9 activos (los otros 9 estaban soft-deleted, correctamente excluidos sin
necesidad de código especial). Los 9 activos migran limpio, 0 sin match —
el único hueco real (`datafono`, en 2 de los 9) se cerró agregándolo al
catálogo base (`PosPaymentMethodSeeder`) en vez de ampliar el matching.

**Re-verificado end-to-end en el droplet `nexolu-pos-sg` (2026-08-20),**
después de 4.4 y con `PosPaymentMethodSeeder` recién corrido — mismo
resultado exacto: 9 negocios migrados (3, 5, 6, 7, 8, 11, 15, 17, 18), 0 sin
match, 30 filas totales en `business_pos_payment_methods` (suma de medios
por negocio: 3+4+3+4+3+3+5+2+3). Nota: el comando **no tiene** una opción
`--force` — correr sin flags (no `--dry-run`) alcanza, `payment-methods:migrate-catalog --force` falla con "The --force option does not exist".

### 4.6bis. Vincular sales/layaways/receivables a Client (ítem 4 de CUTOVER_TODO)

`client_id` ya existe en `sales`/`layaways`/`receivables` (columna nullable,
`ALTER TABLE` aplicado directamente, reflejado en `schema.sql`) — a
diferencia del resto del ítem 4 (unificar `clients`/`customers`), esta parte
no necesitaba esperar al retiro del legacy: se auditó `pos-saas-legacy`
completo y se confirmó que ningún `INSERT` a esas tres tablas es posicional,
así que la columna aditiva no corre riesgo de corromper filas existentes.

Backfill: `php artisan clients:backfill-links {--dry-run} {--business=ID}`
vincula filas con `client_id` NULL a un `Client` por teléfono normalizado,
dentro del mismo negocio. Un teléfono compartido por 2+ clients se reporta
como ambiguo y se deja intacto. Mismo patrón que 4.6: no bloqueado a
`local` (columna 100% nueva), correr `--dry-run` primero.

Al aplicar el `ALTER TABLE` en un droplet nuevo: ya viene en `schema.sql`,
así que solo hace falta correr `clients:backfill-links` después de importar
los datos reales (4.1) — no hay `ALTER` manual pendiente ahí.

**Verificado en `nexolu-pos-sg` (2026-08-20)** contra el dump real de
producción bajado ese día: 3 filas vinculadas (negocio 5/layaways: 1;
negocio 7/sales: 2), 1 ambigua dejada intacta correctamente (teléfono
compartido por 2+ clients en negocio 7/sales). Número bajo, y es
esperado: producción (legacy) no tiene la columna `client_id` en absoluto
(ver § 4.1/4.5bis, es por lo que hizo falta `--complete-insert`), así que
**19,870 de 19,872 ventas** (prácticamente todas) llegaron con `client_id`
NULL. El backfill solo pudo vincular 3 porque la tabla `clients` de este
dump tiene apenas **95 registros** — la enorme mayoría de ventas son de
clientes sin cuenta/registro de `Client` asociado (venta de mostrador sin
captura de datos), no porque el backfill haya fallado.

### 4.7. Decisiones abiertas (bloquean terminar esta sección)

- **¿Todos los negocios de una vez, o gradual?** Un "big bang" (todos los
  negocios migran el mismo día) es más simple de razonar pero concentra el
  downtime/riesgo. Gradual (negocio por negocio, DNS o credenciales
  cambiadas uno a uno) es más seguro pero exige que el legacy y esta API
  convivan por un tiempo — lo cual reabre el ítem 1 de `CUTOVER_TODO.md`
  si ambas apps siguen escribiendo las mismas tablas compartidas en
  paralelo.
- **¿Corte de tráfico o solo de escritura?** Si es gradual, ¿el legacy
  queda en modo solo-lectura para un negocio ya migrado, o se apaga su
  acceso por completo?
- **Ventana de mantenimiento**: ¿se avisa a los negocios, se corre de
  noche, cuánto downtime es aceptable durante el import del dump?
- **Rollback**: si algo sale mal después de migrar un negocio (o todos),
  ¿cómo se revierte? Mientras el droplet legacy no se apague, en teoría
  "volver a apuntar a `pos.nexolu.co`" es el rollback — pero solo si el
  negocio no generó transacciones nuevas en la API nueva durante la
  ventana migrada, que quedarían huérfanas.
- **¿Cuándo se relaja el guard `APP_ENV=local` de 4.4** (o se construye una
  variante aparte) para poder correr la normalización de vocabulario contra
  el droplet real?

Los comandos de 4.4, 4.6 y 4.6bis ya están construidos y probados — lo que
falta para cerrar el cutover real es resolver estas decisiones operativas,
no más código.

## 5. Verificación post-deploy (cualquier escenario)

```bash
docker compose ps
curl -s https://api.nexolu.co/up
docker compose logs -f pos-queue   # confirmar que el worker esta corriendo, no queue:listen
docker compose exec -T pos-web php artisan schema:apply-patches --dry-run   # debe decir "No hay patches pendientes"
```

Y funcionalmente: login de un usuario real (o el superadmin recien
creado), `GET /api/v1/business` responde, `GET /api/v1/superadmin/pos-payment-methods`
lista el catálogo sembrado.

## Referencias

- `nexolu-infra/README.md` — infraestructura del droplet completa.
- `nexolu-payments-core/docs/PRODUCTION_SETUP.md` — runbook de medios de
  pago (Wompi/Payments Core) en producción, ver § 1.1.
- `docs/CUTOVER_TODO.md` — deuda técnica que bloquea partes de la sección 4.
- `docs/LOCAL_DATA_IMPORT.md` + `scripts/import-sg-data.sh` — el ensayo de
  4.1-4.4 contra SG (staging), ya construido y usable hoy en local.
- `App\Console\Commands\LegacyNormalizePaymentMethods` — normaliza el
  vocabulario de `payment_method` (ítem 1 de CUTOVER_TODO), solo `local`.
- `database/legacy-schema/patches/README.md` — convención de patches.
- `deploy.sh` (este repo) — automatización del deploy de este servicio.
