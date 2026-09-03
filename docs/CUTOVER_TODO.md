# Cutover TODO — deuda técnica que solo se puede pagar al retirar el monolito

Este documento acumula problemas encontrados al migrar módulos que **no se pueden
arreglar módulo por módulo** porque tocan tablas que el monolito legacy sigue
leyendo/escribiendo en producción. Arreglar el dato sin coordinar con el legacy
solo mueve la inconsistencia, no la elimina.

Regla general antes de tachar un ítem de esta lista:

1. Confirma que el monolito ya no escribe (o ya no existe) para la(s) tabla(s)
   involucradas, o que su código fue parcheado al mismo tiempo que este fix.
2. Corre el fix manualmente contra producción (no como migración automática de
   este repo — este repo nunca corre migraciones contra el schema compartido,
   ver `database/legacy-schema/`).
3. Verifica con una query antes/después, no asumas.
4. Tacha el ítem con la fecha y el commit/deploy que lo resolvió.

---

## 1. Vocabulario de `payment_method` inconsistente entre tablas

**Origen:** BUG CRÍTICO 1 del `CONTEXT.md` del legacy — ya documentado ahí, confirmado
al migrar el módulo de Gastos.

**El problema:**

| Tabla | Formato | Ejemplo |
|---|---|---|
| `sales.payment_method` | id minúscula, configurado por negocio | `efectivo`, `nequi`, `bold`, `mixed`, `credit` |
| `sale_payment_splits.payment_method` | igual que `sales` | `efectivo` |
| `receivables.payment_method` | igual que `sales` | `efectivo` |
| `service_payments.payment_method` | **label capitalizado**, lista fija | `Efectivo`, `Transferencia`, `Tarjeta` |
| `expenses.payment_method` | label capitalizado, lista fija (`Expense::PAYMENT_METHODS`) | `Efectivo`, `Nequi`, `Daviplata`, `Transferencia`, `Tarjeta` |
| `businesses.payment_methods` (config JSON) | `[{"id":"efectivo","label":"Efectivo"}, ...]` | — |

`expenses.payment_method` en esta API nueva se validó a propósito contra el mismo
enum capitalizado que ya usa `service_payments` en el legacy — no se inventó un
formato nuevo, para no sumar un tercer vocabulario a los dos que ya existen.

**Por qué no se arregla ahora:** normalizar solo los datos de `expenses`/
`service_payments` no sirve de nada si el monolito sigue escribiendo labels
capitalizados en `service_payments` la semana siguiente. Hace falta:

- (a) que el monolito deje de escribir en `sales`/`service_payments`/`receivables`
  (retirado o con ese módulo ya migrado a esta API), **y**
- (b) una migración de datos que unifique todo a un solo vocabulario.

**Fix cuando sea seguro (elegir un vocabulario único, recomendado: id minúscula,
ya que es el que usan las 3 tablas de mayor volumen):**

```sql
-- 1. Backfill: label capitalizado -> id, usando el alias de negocio si existe
UPDATE service_payments sp
JOIN businesses b ON b.id = sp.business_id
SET sp.payment_method = LOWER(sp.payment_method) -- ajustar según normalizePaymentMethodId() de cada negocio
WHERE sp.payment_method REGEXP '^[A-Z]';

UPDATE expenses e
SET e.payment_method = LOWER(e.payment_method)
WHERE e.payment_method REGEXP '^[A-Z]';
```

Ese `LOWER()` es una simplificación — usar `Business::normalizePaymentMethodId()`
(ya existe en `app/Models/Business.php`) fila por fila vía un comando Artisan,
no un `UPDATE` masivo, porque resuelve alias (`efectivo`↔`cash`) correctamente
por negocio.

**Confirmado contra un dump real de producción (2026-08-04, `pos_saas`):** la
divergencia no es teórica — miles de filas repartidas entre ambos vocabularios:

| Tabla | `cash`/`efectivo` | `transfer`/`transferencia` | `credit`/`fiado` | otros | NULL |
|---|---|---|---|---|---|
| `sales` | 6848 / 6629 | 2091 / 1323 | 72 / 25 | nequi 439, mixed 407, daviplata 94, card 6, bold 18, datafono 1 | 410 |
| `sale_payment_splits` | 537 / 30 | 337 / 26 | — | nequi 1 | — |
| `receivables` | 30 / 6 | 6 / — | — / 1 | — | 32 |
| `service_payments` | — / 98 | — | — | nequi 33, daviplata 3 | 11 |
| `expenses` | — / Efectivo 116 | — / Transferencia 1 | — | Nequi 8 | 296 |

`expenses.payment_method` en esta API nueva se validó a propósito contra el mismo
enum capitalizado que ya usa `service_payments` en el legacy — no se inventó un
formato nuevo, para no sumar un tercer vocabulario a los dos que ya existen.
Confirmado con el dump: los valores reales de `expenses` en producción SÍ son
`Efectivo`/`Nequi`/`Transferencia` (capitalizados), igual que asumimos.

**Por qué no se arregla ahora:** normalizar solo los datos de `expenses`/
`service_payments` no sirve de nada si el monolito sigue escribiendo labels
capitalizados en `service_payments` la semana siguiente. Hace falta:

- (a) que el monolito deje de escribir en `sales`/`service_payments`/`receivables`
  (retirado o con ese módulo ya migrado a esta API), **y**
- (b) una migración de datos que unifique todo a un solo vocabulario.

**Fix cuando sea seguro (elegir un vocabulario único, recomendado: id minúscula,
ya que es el que usan las 3 tablas de mayor volumen):**

```sql
-- 1. Backfill: label capitalizado -> id, usando el alias de negocio si existe
UPDATE service_payments sp
JOIN businesses b ON b.id = sp.business_id
SET sp.payment_method = LOWER(sp.payment_method) -- ajustar según normalizePaymentMethodId() de cada negocio
WHERE sp.payment_method REGEXP '^[A-Z]';

UPDATE expenses e
SET e.payment_method = LOWER(e.payment_method)
WHERE e.payment_method REGEXP '^[A-Z]';
```

Ese `LOWER()` es una simplificación — usar `Business::normalizePaymentMethodId()`
(ya existe en `app/Models/Business.php`) fila por fila vía un comando Artisan,
no un `UPDATE` masivo, porque resuelve alias (`efectivo`↔`cash`) correctamente
por negocio.

**El comando ya existe** (`legacy:normalize-payment-methods`, ver más abajo en
este documento y `docs/LOCAL_DATA_IMPORT.md`) y se verificó contra un dump
real de producción completo (2026-08-15, 18 negocios, 18,363 ventas): **49%
de las ventas** (8,988 de 18,363) tienen `payment_method` en un vocabulario
distinto al que el negocio tiene configurado hoy — la mayoría `cash`/`transfer`
(inglés) en negocios ya configurados en español. Confirma que el problema es
real y grande, no cosmético — una muestra más chica revisada antes había dado
0 filas a cambiar, mostrando que hay que verificar contra el dump completo,
no una muestra parcial.

- [ ] Pendiente correr contra producción real. El comando solo corre con
  `APP_ENV=local` (se niega en cualquier otro ambiente) — no tocar este guard
  sin decidir explícitamente cómo se corre para el cutover real.

---

## 2. `linkable_type` guarda el FQCN de la clase, no un alias de morph map

**El problema:** `expenses.linkable_type` (y el `linkable` polimórfico del legacy en
general) guarda literalmente `App\Models\Product` / `App\Models\Ingredient` — el
nombre completo de la clase PHP — en vez de un alias estable vía
`Relation::morphMap()`. Confirmado contra el dump real de producción: el único
valor presente hoy es `App\Models\Purchase`.

**Por qué hoy no revienta:** esta API nueva mantuvo el mismo namespace
`App\Models\*` que el legacy a propósito, así que el string persistido es
idéntico sea cual sea la app que lo escribió. No hay divergencia activa todavía.

**El riesgo real:** si en el futuro esta API (o el legacy, mientras viva) mueve el
modelo `Product` a otro namespace (p. ej. al reorganizar en `Domain\Catalog\Product`
como parte de la arquitectura API-first), **todos los registros polimórficos
existentes con `linkable_type = 'App\Models\Product'` quedan huérfanos** — el
`morphTo()` ya no encuentra la clase. Es el problema clásico que `morphMap()`
existe para resolver, y no lo estamos usando.

**Por qué no se arregla ahora:** agregar un `Relation::morphMap()` en esta API
haría que *nuevos* registros que ella escriba usen el alias corto (`'product'`)
en vez del FQCN. El legacy, que no tiene morph map configurado, no sabría
interpretar ese alias al leer esos registros (los trataría como un nombre de
clase literal `'product'`, que no existe). Mientras ambas apps compartan esta
tabla, solo se puede activar el morph map si **también se parchea el legacy**
para registrar el mismo mapa, al mismo tiempo.

**Fix cuando sea seguro:**

```php
// AppServiceProvider::boot() en ambas apps (legacy + esta), en el mismo deploy
Relation::morphMap([
    'product' => \App\Models\Product::class,
    'ingredient' => \App\Models\Ingredient::class,
]);
```

```sql
UPDATE expenses SET linkable_type = 'product' WHERE linkable_type = 'App\\Models\\Product';
UPDATE expenses SET linkable_type = 'ingredient' WHERE linkable_type = 'App\\Models\\Ingredient';
-- repetir para cualquier otra tabla polimórfica compartida (reminders.remindable_type, etc.)
```

- [ ] Pendiente. Requiere parchear el legacy en simultáneo o esperar a que se retire.

---

## 3. 26 filas de `stock_movements` en producción con `type = ''` (vacío)

**Origen:** confirmado empíricamente esta sesión, dos veces. Primero se probó que
un `INSERT ... type='layaway'` revienta con "Data truncated for column 'type'"
bajo `STRICT_TRANS_TABLES` (el sql_mode de este repo). Después, al cargar el
dump real de producción (2026-08-04), aparecieron 26 filas con `type = ''`:
la prueba de que el mismo `INSERT` en producción **no revienta** (el MySQL de
producción corre sin `STRICT_TRANS_TABLES`, así que un valor de enum inválido se
trunca en silencio a `''` en vez de lanzar error) — exactamente el bug que tenía
`LayawayService::create()`/`cancel()`/`updateItems()` usando `type='layaway'` /
`'layaway_cancel'`, valores que nunca estuvieron en el enum
(`entry`,`exit`,`adjustment`,`sale`).

**Impacto:** esas 26 filas sí movieron el stock correctamente (el hook del modelo
solo lee `quantity`, no `type`), pero son invisibles para cualquier reporte o
filtro que use `WHERE type = 'sale'` (o cualquier otro valor válido) - se pierden
del historial de auditoría.

**Por qué no se arregla ahora:** son filas ya escritas por el legacy, que sigue
en producción; no hay urgencia de negocio (el stock ya quedó correcto) y no vale
la pena tocar la tabla compartida por 26 filas mientras el legacy siga vivo.

**Fix cuando sea seguro:** backfill de esas 26 filas a `type='exit'`/`'entry'`
según el signo de `quantity`, y `stock_movement_reason_id` a los nuevos códigos
`layaway`/`layaway_cancel` (ver `StockMovementReasonSeeder`) según el `reference`
(`"Apartado #..."` vs `"Cancelacion apartado #..."`).

```sql
UPDATE stock_movements
SET type = IF(quantity < 0, 'exit', 'entry'),
    stock_movement_reason_id = (
        SELECT id FROM stock_movement_reasons
        WHERE business_id IS NULL
          AND code = IF(reference LIKE 'Cancelacion apartado%', 'layaway_cancel', 'layaway')
    )
WHERE type = '';
```

- [ ] Pendiente. Bajo riesgo (no bloquea nada), pero no ejecutar sin confirmar cada fila manualmente primero.

---

## 4. `sales`/`layaways`/`receivables` no tienen `client_id` — `clients` y `customers` sin unificar

**Origen:** 2026-08-13, a pedido del usuario ("`clients` y `customers` deberían
ser la misma tabla — un cliente es un cliente, sin importar si le vendo algo,
le agendo un servicio o le abro un apartado").

**El problema:** `appointments.client_id` y `service_orders.client_id` ya son FK
reales a `clients` (`schema.sql:320-340,1717-1740`), pero `sales`, `layaways` y
`receivables` **no tienen ninguna columna `client_id`** — solo texto libre
(`customer_name`/`customer_phone`/`customer_identification`), sin vínculo
persistido a `clients` en absoluto. `customers` (`schema.sql:629-646`) es una
tabla aparte, autogenerada históricamente por el legacy desde esos mismos
campos de texto libre de `sales` (`SaleService::syncCustomerProfile()` en el
legacy), sin FK hacia/desde `clients` tampoco — confirmado que es deuda técnica
del legacy, no una separación deliberada que valga la pena preservar (ver el
análisis completo, ya superado por esta decisión, en `MIGRATION_BACKLOG.md`
bajo "Clientes frecuentes (`customers`)").

**Lo que se pudo hacer ya, sin tocar el esquema compartido:** `ClientQuickAssociate.vue`
(Vender, Cuentas abiertas, Apartados) deja buscar un `Client` existente por
nombre/teléfono/email y copiar sus datos al formulario, o crear un `Client`
nuevo desde el nombre/teléfono recién tipeado — sin depender de una columna
`client_id` en la venta/cuenta/apartado, porque el objetivo real (que exista un
`Client` real y actualizado) no necesita un vínculo persistido en esa tabla
específica. Ver `App\Http\Controllers\Api\V1\ClientController::search()`/
`store()`, ahora gateados solo por `feature:clients` (sin `permission:clients.manage`)
para que cualquiera que pueda vender/agendar/apartar los use.

**Por qué SÍ se agregó la columna (2026-08-19, a pedido explícito del usuario):**
la regla general del proyecto (`CLAUDE.md`: "nunca migración que toque una
tabla que el legacy ya usa") existe para el caso de `payment_method`/
`linkable_type` — datos que el legacy SIGUE reescribiendo, donde arreglarlos
desde acá no sirve de nada porque el legacy los vuelve a ensuciar. `client_id`
es una categoría de riesgo distinta: una columna nueva, nullable, que el
legacy nunca va a tocar ni leer - no hay dato que se pise. El único riesgo real
era que el legacy hiciera algún `INSERT` posicional (`INSERT INTO sales VALUES
(...)` sin listar columnas) en `sales`/`layaways`/`receivables`, lo que
correría el orden de columnas siguientes y corrompería datos en silencio -
se auditó el código completo de `pos-saas-legacy` (`SaleService`,
`LayawayService`, todos los `DB::table('sales'|'layaways'|'receivables')`) y
se confirmó que **todos** los `INSERT`/`create()` usan arrays con columnas
nombradas, ninguno posicional. Con eso descartado, agregar una columna
aditiva es seguro sin esperar al retiro del legacy.

**Hecho:**

```sql
ALTER TABLE sales ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER customer_identification,
  ADD CONSTRAINT sales_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
ALTER TABLE layaways ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER customer_phone,
  ADD CONSTRAINT layaways_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
ALTER TABLE receivables ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER customer_key,
  ADD CONSTRAINT receivables_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
```

Aplicado a mano (no como migración de Laravel, sigue la regla de
`database/legacy-schema/`) contra `pos_saas` y `testing` locales, y reflejado
en `schema.sql` en el mismo commit para que cualquier entorno nuevo lo
incluya desde el arranque. `Sale`/`Layaway`/`Receivable` ganaron `client_id`
en fillable + `belongsTo(Client::class)`; `SaleService::createSale()`,
`OpenTabService::openTab()/close()`, `LayawayService::create()` y
`SaleService::syncReceivable()` (para que un fiado herede el `client_id` de
la venta que lo originó) ya lo persisten. `StoreSaleRequest`/
`StoreOpenTabRequest`/`CloseOpenTabRequest`/`StoreLayawayRequest` validan
`client_id` con `BusinessScopedExists` (mismo patrón que `cart_discount_id`),
así que un `client_id` de otro negocio se rechaza en 422, nunca llega a
guardarse.

`ClientQuickAssociate.vue` (Vender, Cuentas abiertas, Apartados) ya pasó de
"prefill de texto" a "guardar el vínculo real": emite el `Client` completo
(antes solo copiaba nombre/teléfono), y editar nombre/teléfono a mano
después de aplicar un cliente limpia el `client_id` (un vínculo que ya no
corresponde al texto es peor que ninguno). De paso se corrigió un bug real:
"Guardar como cliente nuevo" creaba el `Client` pero nunca lo aplicaba al
formulario - ahora sí.

**Backfill:** `php artisan clients:backfill-links {--dry-run} {--business=}`
vincula filas existentes con `client_id` NULL a un `Client` por teléfono
normalizado (solo dígitos), dentro del mismo negocio. Un teléfono que
matchea 2+ `Client` (familia que comparte número - caso real, no
hipotético) se reporta como ambiguo y se deja sin tocar, nunca elige uno al
azar. No está bloqueado a `local` por la misma razón que
`payment-methods:migrate-catalog`: solo lee/escribe una columna 100% nueva.

**`customers`:** superada por este enfoque - no vale la pena portar el modelo
`Customer` ni el hook `syncCustomerProfile()`; `clients` ya cumple ese rol.
La tabla `customers` queda huérfana en el schema compartido (no se puede
borrar sola sin coordinar con el legacy), sin datos nuevos escritos desde
esta API.

- [x] Columna agregada, modelos/servicios retrofitados, backfill construido,
  frontend persiste el vínculo real - todo verificado con la suite completa
  (990 tests) en verde. Pendiente solo la ejecución operativa: correr el
  mismo `ALTER TABLE` contra producción real (no una copia aislada) y luego
  `clients:backfill-links` ahí - mismo tipo de decisión de timing que el
  ítem 5 (`payment-methods:migrate-catalog`), ver `docs/PRODUCTION_CUTOVER.md`.

---

## 5. Migrar negocios existentes del JSON `payment_methods` al catálogo normalizado

**Origen:** 2026-08-14, a pedido del usuario — "esto va de la mano con la
normalizacion del cutover que tenemos para medios de pago... me pareciera ser
una bola de nieve que necesitamos cerrar para negocios existentes."

**El problema:** este refactor agregó el catálogo global (`pos_payment_methods`,
gestionado por SuperAdmin) y el pivote por negocio (`business_pos_payment_methods`,
`Business::posPaymentMethods()`), pero **ningún negocio existente se migró en
bloque** — es deliberado (ver la nota en `Business::paymentMethods()`). Cada
negocio sigue leyendo del JSON libre (`businesses.payment_methods`) hasta que
un admin abre Ajustes > Medios de pago y guarda al menos una vez
(`PosPaymentMethodController::update()`), momento en el que se crean sus filas
de pivote por primera vez y deja de leer el JSON.

**Por qué no se fuerza ahora:** el JSON de cada negocio es texto libre sin
normalizar (`Business::normalizePaymentMethodsInput()` ya le pone un `id`
slugificado, pero el label puede ser cualquier cosa que el dueño haya escrito
— typos, sinónimos, "Efectivo " con espacio, "Transferencia bancaria" vs
"Transferencia", etc.). Migrar esto en bloque con un `UPDATE`/comando masivo
sin revisión arriesga:

- Crear entradas de catálogo duplicadas o mal etiquetadas si el matching por
  label falla (ej. "Nequi " con espacio no matchea `key='nequi'`).
- Dejar negocios con medios de pago históricos que usaron un id que ya no
  queda "seleccionado" tras la migración, rompiendo `resolveCashPaymentMethodId()`/
  `resolveTransferPaymentMethodId()` para ventas viejas si el matching es
  incorrecto.

El usuario fue explícito en que este paso es posterior y requiere pruebas
extensas contra un dump real de producción (SG) antes de tocar negocios
reales — no es parte de este refactor, que solo construye la infraestructura
y el camino de migración perezoso (uno por uno, cuando el admin guarda).

**Fix cuando sea seguro:** comando Artisan (no migración) que, para cada
negocio sin filas en `business_pos_payment_methods`:

1. Lea `Business::paymentMethods()` (ya resuelve el fallback JSON).
2. Para cada método, intente matchear contra `pos_payment_methods` por alias
   conocido (mismo criterio que `Business::normalizePaymentMethodId()`:
   cash↔efectivo, transfer↔transferencia, credit↔fiado↔credito) y por label
   normalizado (minúsculas, sin tildes, sin espacios extra).
3. Si matchea sin ambigüedad: crear la fila de pivote (`is_enabled = true`).
4. Si NO matchea (label desconocido, ej. un medio que el negocio escribió a
   mano y no existe en el catálogo): **no migrar ese negocio automáticamente**
   — listarlo en un reporte para revisión manual (o usar el mismo flujo de
   "Contacta a soporte" para agregarlo al catálogo primero).
5. Correrlo primero en modo `--dry-run` contra un dump de producción, revisar
   el reporte de no-matches, y solo entonces correrlo real — nunca directo a
   producción sin ese paso, tal como pidió el usuario.

Este ítem también depende de resolver el ítem 1 (vocabulario de
`payment_method` inconsistente): una vez los negocios migren al catálogo, los
`id` que `sales.payment_method`/`receivables.payment_method`/etc. usan pasan a
ser el `key` de `pos_payment_methods`, así que conviene resolver ambos cutovers
en el mismo esfuerzo coordinado, no por separado.

**Construido y verificado (2026-08-15):** `php artisan payment-methods:migrate-catalog`
(`--dry-run` disponible, `--business=ID` para uno solo) — matchea por alias
fijo (cash↔efectivo, transfer↔transferencia, credit↔fiado/credito,
nequi/bold/daviplata/datafono directos), nunca toca un negocio que ya tenga
alguna fila de pivote (no sobreescribe una migración manual desde Ajustes), y
un negocio sin ningún método con match se deja intacto y se reporta para
revisión manual — nunca se migra parcial en silencio.

A diferencia de `legacy:normalize-payment-methods` (ítem 1), este comando
**no está bloqueado a `local`**: `business_pos_payment_methods`/
`pos_payment_methods` son tablas 100% nuevas que el legacy nunca lee, así que
no hay riesgo de esquema compartido. El riesgo es de negocio (una vez migra,
`Business::paymentMethods()` deja de leer el JSON), por eso sigue
recomendándose `--dry-run` primero.

Verificado contra un dump real de producción (2026-08-15, 18 negocios, 9
activos tras excluir soft-deleted): **los 9 negocios activos migran limpio,
0 sin match** — el único método sin match inicial (`datafono`, en 2 de los 9)
se resolvió agregándolo al catálogo base (`PosPaymentMethodSeeder`), no
ampliando el matching — confirma que el diseño "reportar y no forzar" es
suficiente en la práctica, no solo en teoría.

- [x] Comando construido y probado. Queda pendiente solo la decisión de
  **cuándo/cómo correrlo contra producción real** (no una copia aislada) —
  ver `docs/PRODUCTION_CUTOVER.md` § 4.5, todavía sin resolver.

---

## 6. `clients.identification` (cédula) - columna nueva agregada

**Origen:** 2026-08-20, a pedido del usuario probando SG - reportó que el
buscador de cliente (ventas/apartados/fiados/servicios) no dejaba encontrar
un cliente ya existente por cédula, solo por nombre/teléfono/correo, y que
`clients` no tenía dónde guardarla (la cédula solo vivía como texto suelto en
`sales.customer_identification`/`receivables.customer_identification`, nunca
en el directorio de clientes real).

**Por qué se agregó igual que `client_id` (ítem 4):** mismo tipo de riesgo -
columna nueva, nullable, que el legacy nunca va a tocar ni leer. Se auditó
`pos-saas-legacy` completo (`ClientsController`, `ServiceOrdersController`,
`AppointmentsController` en Admin/Employee) y **todos** los `Client::create()`
usan arrays con columnas nombradas (fillable: `business_id, name, phone,
email, notes`), ninguno posicional ni `SELECT *`/`INSERT ... VALUES` crudo -
agregar una columna aditiva no rompe nada del lado legacy.

**Hecho:**

```sql
ALTER TABLE clients ADD COLUMN identification VARCHAR(20) NULL AFTER phone;
```

Aplicado a mano contra `pos_saas` y `testing` locales, y reflejado en
`schema.sql` en el mismo commit. `Client` (fillable), `StoreClientRequest`/
`UpdateClientRequest` (validación `nullable|string|max:20`), `ClientResource`,
`ClientController::search()`/`index()` (ahora matchean `identification` igual
que `name`/`phone`/`email`) y `ClientFactory` ya lo soportan. Frontend:
`ClientFormModal.vue`/`ClientsView.vue` (CRUD completo) y
`ClientQuickAssociate.vue` (alta rápida desde ventas/apartados/fiados) tienen
el campo; `useSaleCheckout.applyClient()` prellena la cédula al asociar un
cliente existente. Suite completa verde (1069/1070 - el único fallo es
preexistente, ajeno a este cambio, ver `BusinessTest::test_owner_can_update_email_branding...`).

- [ ] Pendiente la ejecución operativa: correr el mismo `ALTER TABLE` contra
  SG (`api-sg.nexolu.co`) y, cuando corresponda, contra producción real -
  mismo tipo de decisión de timing que el ítem 4.

---

## 7. `config('app.timezone')` en UTC, distinto del legacy (`America/Bogota`) - desfase de 5h en columnas compartidas

**Origen:** 2026-08-21, a pedido del usuario ("la app debe usar la fecha y
hora de Colombia... tanto para los calendarios, como para guardar en base de
datos, absolutamente todo").

**El problema real (no solo cosmético):** `pos-saas-legacy` corre con
`config('app.timezone') = 'America/Bogota'` desde siempre; esta API corría en
`UTC`. Ambos escriben la **misma base de datos de producción**. Hay 7
columnas `datetime` compartidas (`appointments.starts_at`/`ends_at`,
`cash_shifts.opened_at`/`closed_at`, `receivables.paid_at`,
`reminders.completed_at`/`last_completed_at`) que MySQL guarda **literales,
sin convertir**. El legacy siempre las llenó con hora de Bogotá; esta API
las convertía explícitamente a UTC antes de guardar
(`AppointmentService::parseUtc()`, ahora `parseLocal()`). Resultado real: la
misma columna, en la misma tabla, con 5 horas de diferencia según qué
backend escribió la fila - un legacy leyendo una cita creada desde acá (o
viceversa) mostraba la hora corrida.

Aparte, con `app.timezone=UTC`, cualquier `Carbon::today()`/`whereDate()`
(Resumen del día, cierres de caja, reportes) cortaba "el día" a las 7pm hora
Colombia en vez de medianoche - una venta cerrada a las 6pm podía
desaparecer del resumen si alguien lo miraba después de esa hora, sin que
el dueño hubiera cambiado de día. Confirmado con un test que falla en la
config vieja y pasa en la nueva (`DashboardTest::test_today_summary_uses_the_bogota_calendar_day_not_utc`).

**Por qué es seguro arreglarlo ahora (no requiere retirar el monolito
primero):** a diferencia de los ítems 1/2 (vocabulario `payment_method`,
`linkable_type`), acá no hay dos formatos nuevos peleando - el fix hace que
esta API escriba **exactamente lo que el legacy ya escribe** (hora literal
de Bogotá en esas 7 columnas), eliminando la divergencia en vez de crear una
nueva. No hay coordinación pendiente con el legacy: su comportamiento no
cambia, el nuestro se alinea al de él.

**Hecho:**

- `config/app.php`: `'timezone' => env('APP_TIMEZONE', 'America/Bogota')`
  (antes `'UTC'` fijo).
- `config/database.php` (conexiones `mysql` y `mariadb`): `'timezone' =>
  env('DB_TIMEZONE', '-05:00')` - fuerza `SET time_zone` en la sesión PDO
  (offset fijo, no nombre de zona, porque `mysql.time_zone_name` no está
  poblada acá). Sin esto, las 209 columnas `timestamp` del esquema
  (`created_at`/`updated_at` de casi todo) quedarían con el mismo tipo de
  desfase que las `datetime`, por la vía contraria (MySQL las convierte a
  la timezone de sesión al leer, que sin este cambio seguía en `SYSTEM` =
  UTC del contenedor).
- `AppointmentService::parseUtc()` → `parseLocal()`: normaliza a
  `America/Bogota` en vez de a UTC antes de persistir `starts_at`/`ends_at`.
  El resto de las columnas `datetime` compartidas (`opened_at`, `closed_at`,
  `paid_at`, `completed_at`, `last_completed_at`) se llenan con `now()`, que
  ya respeta `app.timezone` - se corrigen solas con el cambio de config, sin
  tocar código.
- `.env.example`/`.env`: `APP_TIMEZONE=America/Bogota`, `DB_TIMEZONE=-05:00`.
- `nexolu-infra/docker-compose.yml`: `TZ=America/Bogota` en `mysql`,
  `pos-web`, `pos-queue`, `pos-scheduler` - belt-and-suspenders a nivel de
  contenedor/SO, no solo a nivel de Laravel/PDO.
- Test nuevo (`DashboardTest`) prueba explícitamente el corte de día a
  medianoche Bogotá, no a las 7pm UTC - falla en la config vieja, pasa en la
  nueva (verificado corriendo ambas).
- Suite completa verde (1073/1074 - el único fallo es preexistente, ajeno a
  este cambio).

- [ ] Pendiente la ejecución operativa: correr contra SG y, cuando
  corresponda, producción real - mismo tipo de decisión de timing que el
  ítem 4. Antes de tocar producción, correr `SELECT @@global.time_zone,
  NOW(), UTC_TIMESTAMP();` ahí primero (no asumir que está en el mismo
  estado que SG) - ver cómo se verificó en SG antes de aplicar.

---

## 9. Tabla `support_tickets` huérfana en este repo (2026-09-03)

Se retiró el módulo de tickets de soporte de `nexolu-pos-api`: controllers
(negocio y superadmin), modelo, resource, requests, factory, rutas y el
contador `open_support_tickets` del dashboard y del detalle de negocio.
Motivo: nadie lo usó nunca — en el frontend nuevo ni siquiera llegó a
existir una pantalla que lo consumiera. El soporte ahora es por WhatsApp
(`App\Support\SupportContact` + `GET /v1/support/contact`).

**La tabla `support_tickets` NO se borró y no debe borrarse todavía**: es
parte del esquema compartido y el monolito legacy sigue leyéndola y
escribiéndola desde su propio panel. Un `DROP TABLE` desde este repo
rompería el legacy en producción.

Precondición para borrarla: que el monolito esté retirado (o que se quite
primero su módulo de tickets allá). Recién entonces:

```sql
DROP TABLE support_tickets;
```

Las **guías de ayuda** (`support_guide_categories`, `support_guide_articles`)
son otra cosa y siguen activas en este repo — no confundirlas al limpiar.
