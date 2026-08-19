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

- `php artisan migrate` — el esquema no viene de migraciones, ver
  `CLAUDE.md` y `database/migrations/` (vacío a propósito).
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
# mysqldump --no-create-info, mismo patron que scripts/import-sg-data.sh
# (ver ese script para el detalle completo: tablas excluidas, etc.)
mysqldump -h<host> -u<user> -p<db> --no-create-info > dump-$(date +%F).sql

# En el droplet nuevo, contra una base ya con la estructura de schema.sql
# cargada (nunca --no-create-info sobre una base vacia sin estructura):
docker compose exec -T mysql mysql -unexolu -p<MYSQL_APP_PASSWORD> pos_saas \
  < dump-$(date +%F).sql
```

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
`docs/LOCAL_DATA_IMPORT.md`. **Corre solo con `APP_ENV=local`** — se niega
a ejecutarse contra cualquier otro ambiente, así que **no sirve tal cual
para el droplet de producción/staging** (que corren con `APP_ENV=production`
o similar). Si se necesita para el droplet, hay que decidir primero si ese
guard se relaja (y bajo qué condición) o si se construye una variante
aparte con su propio guard — no relajar el actual sin discutirlo, es la
única barrera hoy entre este comando y correr contra datos reales por
accidente.

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
- `docs/CUTOVER_TODO.md` — deuda técnica que bloquea partes de la sección 4.
- `docs/LOCAL_DATA_IMPORT.md` + `scripts/import-sg-data.sh` — el ensayo de
  4.1-4.4 contra SG (staging), ya construido y usable hoy en local.
- `App\Console\Commands\LegacyNormalizePaymentMethods` — normaliza el
  vocabulario de `payment_method` (ítem 1 de CUTOVER_TODO), solo `local`.
- `database/legacy-schema/patches/README.md` — convención de patches.
- `deploy.sh` (este repo) — automatización del deploy de este servicio.
