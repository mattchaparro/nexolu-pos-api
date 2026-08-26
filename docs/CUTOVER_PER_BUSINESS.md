# Cutover gradual, negocio por negocio (legacy → nexolu-pos-prod)

Este documento reemplaza el enfoque "big bang" que `PRODUCTION_CUTOVER.md` § 4.7
dejaba como pregunta abierta. Decisión explícita del usuario (2026-08-26): la
migración de negocios reales **no es un corte único** — es gradual, un negocio
a la vez, disparado manualmente desde el panel super-admin del legacy, y
**sin posibilidad de regreso** una vez un negocio migra.

No repite el contenido ya construido en `PRODUCTION_CUTOVER.md` §§ 4.1-4.6bis
(normalización, catálogo de payment methods, `client_id`) — ese trabajo sigue
siendo válido, solo cambia **cómo y cuándo** se aplica: por negocio, dentro del
comando nuevo de este documento, no como pase único contra un dump completo.

## 1. Por qué no dos bases en vivo (dual-write)

Se consideró que legacy y `nexolu-pos-api` escribieran la misma base en vivo
mientras dura la transición (para que negocios no migrados y migrados
convivieran sin fricción). Descartado: obliga a legacy y la API nueva a
mantener dos escrituras sincronizadas por transacción (riesgo de divergencia
si una de las dos falla), reabre el ítem 1 de `CUTOVER_TODO.md`
(`payment_method` se ensucia de nuevo en cada venta nueva mientras legacy siga
escribiendo), y no aporta nada frente a la alternativa: como el corte es
irreversible y por negocio, no hace falta sincronía continua — alcanza con un
solo corte limpio por negocio.

## 2. Máquina de estados (`business_migrations`)

Tabla nueva en **legacy** (`pos-saas-legacy`), separada de `businesses` para no
acoplar la migración al modelo de negocio:

| Columna | Tipo | Nota |
|---|---|---|
| `business_id` | FK → `businesses.id` | única |
| `status` | enum | `pending` → `notified` → `migrating` → `completed` / `failed` |
| `notified_at` | datetime nullable | se pone al marcar `notified` desde super-admin |
| `migration_started_at` | datetime nullable | se pone al lanzar `businesses:migrate` |
| `migrated_at` | datetime nullable | se pone solo si termina en `completed` |
| `error` | text nullable | detalle si termina en `failed` |

Transiciones:

- **`pending → notified`**: acción manual de super-admin ("Notificar
  migración"). Dispara el modal de aviso en el frontend de ese negocio (§ 5).
  Sin ventana mínima obligatoria entre `notified` y lanzar la migración real
  — decisión explícita del usuario: la disciplina de avisar con tiempo queda
  en manos de quien opera, no en el código.
- **`notified → migrating`**: acción manual de super-admin, ojalá en horario
  de baja actividad (madrugada). Es el punto donde el negocio queda
  **congelado en legacy** (§ 4) — no es solo un flag informativo.
- **`migrating → completed`**: el comando `businesses:migrate` (§ 4) terminó
  y verificó el push. A partir de acá, el middleware (§ 3) redirige toda
  request de ese negocio a `new-pos.nexolu.co`. **Irreversible**: no existe
  transición de vuelta a `notified`/`pending`.
- **`migrating → failed`**: el comando abortó a mitad de camino. El negocio
  **se descongela** (vuelve a operar normal en legacy, como si siguiera en
  `notified`) — nunca se deja un negocio bloqueado sin haber migrado. Se
  puede reintentar `businesses:migrate` de nuevo tras corregir la causa.

## 3. Middleware de redirect y congelamiento

Post-auth, global, en cada request de un usuario con `business_id`:

- `status = migrating` → bloquea cualquier request de **escritura** (métodos
  POST/PUT/PATCH/DELETE) con un mensaje de mantenimiento; lectura sigue
  funcionando para que el super-admin pueda verificar datos mientras corre el
  comando. Esto es lo que hace seguro correr `payment-methods:normalize`
  scoped a este negocio sin que una venta nueva lo vuelva a ensuciar a mitad
  del export (§ 4.2).
- `status = completed` → redirect total, cualquier método, cualquier ruta, a
  `new-pos.nexolu.co` (o el dominio final cuando se repunte, ver § 7). Se
  chequea también en el login, antes de armar la sesión.

**Mecanismo de redirect (resuelto, sin interceptor custom):** la app es
Inertia.js, no una SPA con fetch crudo. Para requests dentro del ciclo
Inertia, `Inertia::location($url)` devuelve un 409 con header
`X-Inertia-Location`; el cliente de Inertia intercepta esa respuesta y hace
`window.location.href` él mismo — no hay problema de CORS/XHR porque nunca se
intenta leer un body cross-origin como si fuera JSON de la misma app. Para
cualquier endpoint JSON fuera del ciclo Inertia (si existe alguno), el
middleware devuelve un body `{"redirect": url}` con status 409, y
`resources/js/bootstrap.js` gana un interceptor de Axios mínimo que reacciona
a ese shape haciendo `window.location.href` — hoy no existe ningún
interceptor global ahí, se agrega desde cero.

## 4. Comando `businesses:migrate {business} {--dry-run}`

Vive en `pos-saas-legacy` (sigue la convención `businesses:*` ya usada por
`WarnInactiveTrialBusinesses`/`SendTrialWinbackEmails`).

### 4.1. Secuencia

1. Guard: rechaza si `business_migrations.status` no es `notified` (no se
   puede lanzar dos veces, ni saltarse el aviso).
2. `status = migrating`, `migration_started_at = now()`.
3. `Artisan::call('payment-methods:normalize', ['--business' => [$id]])` —
   comando **ya existente** (`app/Console/Commands/NormalizePaymentMethodsCommand.php`),
   no se reescribe lógica de normalización nueva. Corre seguro acá porque el
   paso 2 ya congeló la escritura de este negocio.
4. Descubrir el grafo de tablas del negocio (§ 4.2) y exportar en orden
   topológico hacia la conexión `nexolu_pos` (§ 6), remapeando IDs (§ 4.3).
5. Verificar conteos (filas exportadas por tabla == filas en destino) antes
   de confirmar.
6. Si todo OK: `status = completed`, `migrated_at = now()`. Si algo falla en
   cualquier paso: `status = failed`, `error = <detalle>` — el negocio queda
   descongelado (vuelve a `notified` en efecto, el middleware ya no bloquea
   escritura salvo que `status` siga en `migrating`, que no debería persistir
   tras una excepción no capturada — el comando debe capturar y siempre
   dejar el registro en `failed`, nunca en `migrating` colgado).

### 4.2. Descubrimiento del grafo de tablas (no hardcodeado)

Confirmado auditando `database/legacy-schema/schema.sql`: **41 tablas** tienen
`business_id` directo (`sales`, `products`, `clients`, `expenses`, etc.), pero
varias tablas hijas **no** lo tienen y solo se relacionan vía FK a esas 41
(`sale_items`, `sale_payment_splits`, `sale_partial_payments`,
`layaway_items`, `layaway_payments`, `purchase_lines`, `purchase_payments`,
`service_order_items`, `service_payments`, `stock_movements`,
`product_cost_history`, la pivote `ingredient_product`). Un listado a mano de
"las tablas del negocio" arriesga olvidar alguna la próxima vez que el schema
crezca.

En vez de hardcodear la lista, el comando descubre el grafo en tiempo real:

1. Consulta `information_schema.COLUMNS` para hallar todas las tablas con
   columna `business_id` (raíces).
2. BFS sobre `information_schema.KEY_COLUMN_USAGE` /
   `REFERENTIAL_CONSTRAINTS`: cualquier tabla cuya FK apunte a una tabla ya
   incluida se agrega como hija, repitiendo hasta punto fijo (sin tablas
   nuevas por agregar).
3. El orden de export es el orden de descubrimiento BFS (padres antes que
   hijos) — garantiza que al insertar una fila hija, la fila padre ya existe
   en destino (necesario para el remapeo de FK del paso 4.3).

### 4.3. Remapeo de IDs — el punto crítico de correctitud

**`nexolu-pos-prod` no es una base vacía.** Ya tiene negocios reales que se
registraron directo desde `nexolu-pos-front` (ver `PRODUCTION_CUTOVER.md`
§ 1: "un negocio que se registre desde nexolu-pos-front a partir de aquí
funciona normal"). Sus tablas (`products`, `sales`, etc.) ya tienen
autoincrementos avanzando por cuenta propia.

**Insertar preservando los IDs originales del legacy es inseguro**: el
`product.id = 50` del negocio migrado puede coincidir con un `product.id = 50`
ya existente de otro negocio nativo de `nexolu-pos-prod` — colisión de PK
entre negocios distintos, silenciosa si se usa `INSERT IGNORE` o directamente
un error de PK duplicada si no.

**Solución (no es una alternativa entre varias, es la única correcta acá):**
por cada tabla, insertar SIN especificar la columna `id` (dejar que el
autoincrement de destino asigne uno nuevo), y mantener en memoria un mapa
`old_id → new_id` por tabla durante toda la corrida del comando. Antes de
insertar una fila hija, sus columnas FK (`sale_id`, `product_id`,
`layaway_id`, etc.) se reescriben usando el mapa de la tabla padre
correspondiente. Como el orden de export ya es padres-antes-que-hijos (§ 4.2),
el mapa del padre siempre existe cuando se necesita para la hija.

`business_id` en las tablas raíz se reescribe también: el negocio migrado
recibe una fila nueva en `businesses` (o ya la tiene si `nexolu-pos-api`
soporta pre-crear el registro de negocio antes del import — a definir en
implementación) con su propio `id` nuevo en esa base, y todas sus tablas
raíz usan ese `id` nuevo, no el `business_id` original de legacy.

### 4.4. `--dry-run`

Corre el descubrimiento del grafo y cuenta filas por tabla sin conectar a
`nexolu_pos` ni escribir nada — ni siquiera corre
`payment-methods:normalize` real (usa su propio `--dry-run` internamente).
Sirve para verificar el volumen antes de decidir la ventana de mantenimiento.

## 5. Modal de aviso (frontend)

Componente nuevo, adaptado de
`resources/js/Components/AnuncioNovedadesModal.vue` pero **sin** el dismiss
por `localStorage` de ese componente (ese patrón lo oculta para siempre en
ese navegador tras cerrarlo una vez). Decisión explícita del usuario: debe
reaparecer en cada login mientras `status = notified` — se resuelve
simplemente condicionando el render a un prop compartido por Inertia
(`page.props.auth.business.migration_status`), sin ningún estado de "ya lo
vi" persistido en el cliente. Cerrar el modal solo lo oculta para esa sesión
de navegación, no lo descarta permanentemente.

Solo visible para rol `admin` del negocio (mismo criterio que
`AnuncioNovedadesModal`), no para cajeros/empleados.

## 6. Conexión `nexolu_pos` (nueva, no existía)

`config/database.php` de legacy no tiene hoy ninguna segunda conexión — se
agrega `nexolu_pos` apuntando al MySQL de `nexolu-pos-prod`
(`174.138.42.118`). Requiere:

- Abrir el `3306` de `nexolu-pos-prod` a la IP privada de legacy dentro de la
  VPC (`10.116.0.0/20`) — firewall restringido solo a esa IP, no abierto a
  toda la VPC ni a internet.
- Credenciales nuevas (usuario de solo-escritura-necesaria, no el root de la
  app) para esta conexión específica.

Se decidió conexión MySQL directa en vez de HTTP hacia endpoints de
bulk-import en `nexolu-pos-api` (alternativa considerada y descartada por el
usuario): más simple y rápido para volumen histórico, a costa de bypassear
observers/eventos de modelo de la API nueva — aceptable porque el remapeo de
IDs (§ 4.3) y la normalización (§ 4.1 paso 3) ya cubren la integridad de
datos que esos observers normalmente garantizarían.

## 7. Dominio final

`new-pos.nexolu.co`/`pos-backend.nexolu.co` son de transición. Cuando la
migración gradual haya movido suficientes negocios (o todos), `pos.nexolu.co`
se repunta al stack nuevo y el droplet legacy se retira — no forma parte del
alcance de este documento, es un paso posterior.

## 8. Pendientes fuera de este documento

- Aviso proactivo (WhatsApp/email) del inicio de transición — hoy el modal
  de login es el único aviso; depende de credenciales pendientes de
  `comms-api` (BREVO, WhatsApp) para volverse proactivo.
- Snapshot de respaldo del droplet legacy antes de tocar nada — mencionado,
  no tomado todavía.
- Actualizar `nexolu-pos-api/CLAUDE.md` (sección "Issues that need the
  monolith retired first") una vez este mecanismo esté en producción: el
  guard de `legacy:normalize-payment-methods` (el comando de *esta* API, no
  `payment-methods:normalize` de legacy) puede documentarse como innecesario
  para el flujo por-negocio, que ya normaliza en origen antes de cada export.
