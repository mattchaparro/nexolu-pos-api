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
