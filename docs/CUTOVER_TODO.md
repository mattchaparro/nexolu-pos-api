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

- [ ] Pendiente. No tocar hasta confirmar que el monolito ya no escribe estas tablas.

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

**Por qué no se agrega la columna ahora:** `sales`, `layaways` y `receivables`
ya existen en `schema.sql` y el legacy todavía las lee/escribe en producción —
un `ALTER TABLE ... ADD COLUMN client_id` desde esta API violaría la regla del
proyecto (`CLAUDE.md`: "nunca migración que toque una tabla que el legacy ya
usa"), y aunque la columna en sí sería aditiva (no rompe lecturas del legacy),
coordinar el cambio de esquema con el retiro/parcheo del legacy es el proceso
correcto, no una excepción de "es solo agregar una columna".

**Fix cuando sea seguro:**

```sql
ALTER TABLE sales ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER customer_identification,
  ADD CONSTRAINT sales_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
ALTER TABLE layaways ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER customer_phone,
  ADD CONSTRAINT layaways_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
ALTER TABLE receivables ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER customer_identification,
  ADD CONSTRAINT receivables_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
```

Mismo patrón que `appointments`/`service_orders` ya usan: la columna `client_id`
convive con el texto libre existente (una snapshot del nombre/teléfono al
momento de la venta, no se borra), el FK es opcional (`ON DELETE SET NULL`).
Una vez agregada la columna: `ClientQuickAssociate` pasa de "prefill de texto"
a "guardar el vínculo real" (un cambio de app trivial), y se puede hacer un
backfill de las filas existentes que matcheen por teléfono contra `clients`.

**`customers`:** superada por este enfoque — no vale la pena portar el modelo
`Customer` ni el hook `syncCustomerProfile()`; `clients` ya cumple ese rol una
vez tenga los FKs de arriba. La tabla `customers` queda huérfana en el schema
compartido (no se puede borrar sola sin coordinar con el legacy), sin datos
nuevos escritos desde esta API.

- [ ] Pendiente. Requiere coordinar con el retiro/parcheo del legacy antes del
  `ALTER TABLE`. Mientras tanto, `ClientQuickAssociate.vue` ya cubre el caso de
  uso real (que quede un `Client` correcto) sin la columna.

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

- [ ] Pendiente. Requiere el comando con `--dry-run` + revisión manual descrita
  arriba, probado contra un dump real de producción, antes de correr contra
  negocios reales.
