# Runbook de despliegue a producción (SG)

Documento vivo — se va completando a medida que se resuelven las
"Decisiones abiertas" de la sección 4. No es una guía de un solo repo: cruza
`nexolu-infra` (la infraestructura del droplet), este repo (`nexolu-pos-api`)
y `docs/CUTOVER_TODO.md` (la deuda técnica que bloquea algunos pasos).

## 0. Vocabulario

- **Droplet nuevo**: el DigitalOcean droplet que corre `api.nexolu.co` (ver
  `nexolu-infra/README.md`) — infraestructura **separada** de donde vive hoy
  el monolito legacy (`pos.nexolu.co`). No comparten servidor ni, hoy, base
  de datos.
- **SG**: la base de datos de producción real del monolito legacy — los
  negocios que hoy usan `pos.nexolu.co`, con datos reales (ventas, clientes,
  medios de pago configurados, etc).
- **Cutover**: el momento en que uno o más negocios reales pasan de ser
  servidos por el monolito legacy a ser servidos por `api.nexolu.co` /
  `nexolu-pos-front`.

Hay dos escenarios completamente distintos que este documento cubre por
separado porque tienen riesgo muy diferente:

1. **Desplegar el droplet nuevo** (secciones 1-2) — infraestructura vacía,
   sin datos reales de negocios todavía. Bajo riesgo, ya bastante
   automatizado.
2. **Traer los negocios reales de SG** (sección 4) — datos de producción
   reales, clientes reales. Alto riesgo, requiere pruebas primero, varias
   decisiones sin tomar todavía (ver 4.5).

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

## 4. Cutover de negocios reales (SG → droplet nuevo)

Esto es lo que falta decidir y construir antes de que un negocio real de
SG pueda operar desde `api.nexolu.co`. Orden propuesto:

### 4.1. Importar los datos de SG

```bash
# En el servidor de SG (o desde donde se pueda alcanzar su MySQL):
mysqldump -h<host-sg> -u<user> -p<db-sg> > sg-dump-$(date +%F).sql

# En el droplet nuevo, contra una base VACIA (nunca contra pos_saas con
# datos ya cargados de schema.sql - restaurar el dump real ES la carga):
docker compose exec -T mysql mysql -unexolu -p<MYSQL_APP_PASSWORD> pos_saas \
  < sg-dump-$(date +%F).sql
```

El dump real de SG **reemplaza** la carga de `schema.sql` (no se hacen las
dos) — es exactamente lo mismo que `database/legacy-schema/schema.sql`
representa (un dump de esa misma base, capturado 2026-08-03), solo que más
reciente y con los datos reales completos.

### 4.2. Poner al día el esquema

El dump de SG **no tiene** las tablas agregadas después de cualquier
captura anterior (`whatsapp_logs`, `pos_payment_methods`,
`business_pos_payment_methods`, y las que se sumen). Por eso existe
`schema:apply-patches` — correrlo aquí es obligatorio, no opcional:

```bash
docker compose exec -T pos-web php artisan schema:apply-patches
docker compose exec -T pos-web php artisan permissions:sync
```

### 4.3. Catálogo de medios de pago

```bash
docker compose exec -T pos-web php artisan db:seed --class=PosPaymentMethodSeeder --force
```

Esto solo crea el catálogo global (`pos_payment_methods`) — los negocios
importados de SG **no quedan migrados automáticamente** a él, siguen
leyendo su JSON libre (`businesses.payment_methods`) hasta el paso 4.4.

### 4.4. Normalizar medios de pago por negocio

**No construido todavía** — es el ítem 5 de `docs/CUTOVER_TODO.md`. Antes
de construirlo, correrlo en modo `--dry-run` contra una copia real del
dump de SG (no contra datos sintéticos) y revisar el reporte de negocios
sin match automático, tal como se acordó explícitamente. Diseño propuesto
en `CUTOVER_TODO.md` § 5 — no repetido aquí, esa es la fuente de verdad
para ese paso.

### 4.5. Decisiones abiertas (bloquean terminar esta sección)

- **¿Todos los negocios de una vez, o gradual?** Un "big bang" (todos los
  negocios de SG migran el mismo día) es más simple de razonar pero para
  el downtime/riesgo a la vez. Gradual (negocio por negocio, DNS o
  credenciales cambiadas uno a uno) es más seguro pero exige que el
  legacy y esta API convivan por un tiempo — lo cual reabre el ítem 1 de
  `CUTOVER_TODO.md` (vocabulario de `payment_method` divergente) si ambas
  apps siguen escribiendo las mismas tablas compartidas en paralelo.
- **¿Corte de tráfico o solo de escritura?** Si es gradual, ¿el legacy
  queda en modo solo-lectura para un negocio ya migrado, o se apaga su
  acceso por completo?
- **Ventana de mantenimiento**: ¿se avisa a los negocios, se corre de
  noche, cuánto downtime es aceptable durante el import del dump?
- **Rollback**: si algo sale mal después de migrar un negocio (o todos),
  ¿cómo se revierte? El dump de SG sigue intacto en el droplet legacy
  mientras no se apague, así que en teoría "volver a apuntar a
  `pos.nexolu.co`" es el rollback — pero solo si el negocio no generó
  transacciones nuevas en la API nueva durante la ventana migrada, que
  quedarían huérfanas.

No se avanza en construir el comando de normalización (4.4) hasta que al
menos la primera de estas preguntas tenga respuesta, porque el diseño del
comando (¿corre una vez para todos, o se puede correr negocio-por-negocio
bajo demanda?) depende directamente de la respuesta.

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
- `database/legacy-schema/patches/README.md` — convención de patches.
- `deploy.sh` (este repo) — automatización del deploy de este servicio.
