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

- [ ] Pendiente. No tocar hasta confirmar que el monolito ya no escribe estas tablas.

---

## 2. `linkable_type` guarda el FQCN de la clase, no un alias de morph map

**El problema:** `expenses.linkable_type` (y el `linkable` polimórfico del legacy en
general) guarda literalmente `App\Models\Product` / `App\Models\Ingredient` — el
nombre completo de la clase PHP — en vez de un alias estable vía
`Relation::morphMap()`.

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
