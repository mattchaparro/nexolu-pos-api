# Cargar datos reales de sg (staging) en local

Este repo, por defecto, arranca localmente con `database/legacy-schema/schema.sql`
(solo estructura) y nada de datos - lo único que existe es lo que crees a mano
(p. ej. un usuario demo por tinker). Para probar contra algo más parecido a
producción, `scripts/import-sg-data.sh` trae un snapshot de datos reales de
staging (`sg`, `pos-sg.nexolu.co`).

```bash
bash scripts/import-sg-data.sh
```

Requiere acceso SSH a `root@104.248.230.120` (mismo servidor de
`pos-saas-legacy/scripts/deploy-sg.sh`) y el contenedor `mysql` de Sail
corriendo (`docker compose up -d`).

## Qué hace y qué no

- Trae **solo datos** (`mysqldump --no-create-info`) - nunca la estructura.
  La estructura local viene siempre de `schema.sql`.
- **Reemplaza por completo** las tablas de negocio locales (`TRUNCATE` +
  carga) - cualquier dato que hayas creado a mano localmente en esas tablas
  se pierde. Es repetible: cada corrida deja un snapshot fresco de `sg`.
- Excluye tablas transitorias/de sesión de `sg` (sesiones, tokens de acceso
  personal, cache, colas, `migrations`) - no tiene sentido copiar eso a un
  laptop de desarrollo.
- Al final, apunta `demo@nexolu.test` / `password123` al negocio 5
  ("Restaurante de prueba" en `sg`) - no crea un negocio vacío nuevo.
  Ese negocio se eligió a propósito porque tiene **todos** los
  `feature_flags` en `true`, así que es el único que deja probar cualquier
  módulo sin toparse con un feature apagado.

## Verificación de esquema (2026-08-07)

Se comparó el esquema real de `sg` contra `schema.sql`: de 85 tablas
compartidas, coinciden todas salvo 2, y **en las dos, `schema.sql` está
adelante de `sg`** (no al revés):

| Tabla | Columnas que `schema.sql` tiene y `sg` todavía no |
|---|---|
| `product_categories` | `parent_id` |
| `reminders` | `notify_time`, `notify_whatsapp`, `last_notified_on` |

O sea: cargar datos de `sg` sobre el schema local es seguro - esas columnas
nuevas simplemente quedan en su default (`NULL`/`0`) para las filas
importadas. Si en el futuro esto deja de ser cierto (si `sg` avanza con
columnas que `schema.sql` no tiene), el import fallaría con un error de
columna desconocida y hay que actualizar `schema.sql` primero.

**Actualización (2026-08-14):** desde la verificación de arriba se agregaron
3 tablas nuevas 100% propias de esta API (`whatsapp_logs`,
`pos_payment_methods`, `business_pos_payment_methods` - ver
`database/legacy-schema/patches/`). `sg` es un snapshot del monolito legacy
y **nunca tendrá estas tablas** (no existe el concepto en esa app), así que
no aparecen en el `mysqldump` y el import las deja intactas - no hay
conflicto. Si todavía no corriste `php artisan schema:apply-patches`
localmente, hazlo antes o después del import, en cualquier orden: son
independientes (una carga estructura nueva, la otra solo reemplaza datos en
tablas que ya existen).

## Problemas de datos conocidos que el import NO corrige

Ver `docs/CUTOVER_TODO.md` para el detalle completo. El import los deja tal
cual están en `sg`, a propósito:

- **`stock_movements.type = ''`** (#3 de CUTOVER_TODO): confirmado que `sg`
  tiene 8 filas así (bug real de `LayawayService` en legacy, protegido por
  `STRICT_TRANS_TABLES` en local pero no en `sg`/producción). El script
  relaja `sql_mode` solo para que el `INSERT` no falle - no "arregla" nada,
  solo permite que el dato real (con su bug) se cargue igual que existe.
- **Vocabulario de `payment_method` inconsistente** (#1 de CUTOVER_TODO):
  `sales`/`receivables` usan id-minúscula, `expenses`/`service_payments`
  usan label capitalizado. Corré aparte, si hace falta:

  ```bash
  docker compose exec laravel.test php artisan legacy:normalize-payment-methods --dry-run
  docker compose exec laravel.test php artisan legacy:normalize-payment-methods
  ```

  Este comando **solo corre con `APP_ENV=local`** (se niega a correr contra
  cualquier otra base) y queda deliberadamente desalineado de la validación
  actual de `StoreExpenseRequest` (que exige el enum capitalizado) - ver el
  docblock de `App\Console\Commands\LegacyNormalizePaymentMethods` para el
  razonamiento completo.
- **`linkable_type` con FQCN en vez de alias de morph map** (#2 de
  CUTOVER_TODO): no aplica ningún fix - hoy no rompe nada (ver la nota en
  CUTOVER_TODO.md), y arreglarlo requiere parchear legacy en simultáneo.
