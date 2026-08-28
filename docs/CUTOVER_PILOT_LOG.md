# Bitácora del piloto de migración gradual (negocio por negocio)

Documento vivo. Registra qué se construyó, qué ya está verificado en
producción real, qué bugs aparecieron y cómo se resolvieron, para poder
retomar la fase de pruebas sin depender de memoria de conversación. Ver
`docs/CUTOVER_PER_BUSINESS.md` para el diseño completo del mecanismo — este
documento es el registro de ejecución, no el diseño.

## 1. Qué se construyó

- **`pos-saas`** (legacy, repo real en GitLab — no confundir con
  `pos-saas-legacy`, un snapshot congelado en GitHub sin relación con lo que
  se despliega): tabla `business_migrations`, modelo, comando
  `businesses:migrate`, `BusinessDataExporter` (export scoped a un negocio
  con remapeo de IDs), middleware de redirect/bloqueo, pantalla super-admin
  ("Notificar migración" / "Lanzar migración"), y la comunicación de
  marketing (modal + banner) para el negocio migrado.
- **`nexolu-pos-front`**: modal de bienvenida (`WelcomeExperienceModal.vue`)
  disparado por `?bienvenida=1` en el redirect final. Construido y probado
  localmente; **este repo todavía no tiene deploy automatizado**, así que no
  está en ningún servidor todavía.
- Desplegado a `pos-sg.nexolu.co` (staging) y a `pos.nexolu.co` (producción
  real) — ambas ramas (`staging`/`master`) están sincronizadas al mismo
  commit a la fecha de este documento.

## 2. Estado verificado de `nexolu-pos-prod` (174.138.42.118)

Auditoría de tablas generales vs. business-scoped hecha el 2026-08-26 (ver
`docs/CUTOVER_PER_BUSINESS.md` § 4.2 para la metodología de descubrimiento
por FK). Resultado:

| Tabla general | Estado |
|---|---|
| `roles`, `permissions`, `model_has_roles`/`model_has_permissions`/`role_has_permissions` | Sembradas / sincronizadas |
| `pos_payment_methods` (7), `stock_movement_reasons` globales (10) | Sembradas |
| `service_workflows` (2), `service_workflow_stages` (2) | Copiadas a mano desde legacy (estaban vacías con FK `NOT NULL` desde `business_service_workflows`/`service_orders`) |
| `schema:apply-patches` | Sin pendientes |
| Superadmin (`mattchaparrof@gmail.com`) | Copiado con el hash real de legacy — la contraseña de siempre funciona ahí también |

Tablas revisadas sin FK real apuntándoles (`cities`, `genders`,
`user_statuses`, `email_templates`, `settings`, `system_configs`) — scaffolding
sin usar o config con default en código, no bloquean nada.

`payment_methods`/`payment_methods_types`/`payment_providers`/`payments` son
el sistema de facturación de la suscripción SaaS (Wompi), no el catálogo de
medios de pago del POS — vacías porque nadie pagó suscripción ahí todavía,
no por falta de datos.

## 3. Conexión de red (legacy producción → nexolu-pos-prod)

- MySQL de `nexolu-pos-prod` publicado **solo** en su IP privada VPC
  (`10.116.0.6:3306`, nunca en la IP pública) — cambio real en
  `/opt/nexolu/nexolu-infra/docker-compose.yml` (proyecto compose `nexolu`,
  cuidado: **no** editar el `compose.yaml` de `nexolu-pos-api`, ese no es el
  que corre en producción).
- Firewall de DigitalOcean (`nexolu-pos-prod-mysql-vpc`): `3306` solo desde
  `10.116.0.3` (legacy producción); `22`/`80`/`443` preservados explícitamente.
- Usuario MySQL dedicado `legacy_migration@10.116.0.3`, solo `SELECT,INSERT`
  sobre `pos_saas`.
- Variables `NEXOLU_POS_DB_*` en el `.env` de legacy producción
  (`/var/www/pos.nexolu.co/.env`).
- Verificado con `tinker` en vivo: conexión real funcional.

## 4. Bugs encontrados durante las pruebas (todos corregidos y desplegados)

### 4.1. `BusinessDataExporter`: usuarios migrados sin rol

`model_has_roles`/`model_has_permissions` no tienen FK real hacia `users`
(polimórfico, Spatie nunca la declara) — el descubrimiento por FK nunca las
encontraba. Fix: paso explícito `exportUserAssignments()` post-loop, remapeo
por nombre de rol/permiso contra el catálogo ya sembrado en destino.

### 4.2. `BusinessDataExporter`: corrupción silenciosa en columnas polimórficas

`expenses.linkable_type/_id` y `reminders.remindable_type/_id` (morphTo,
tampoco tienen FK real) se copiaban **sin remapear** - a diferencia de una
FK sin estrategia (falla ruidoso), esto apuntaba en silencio a cualquier
fila con ese id crudo en destino, de cualquier negocio. Fix:
`MORPH_COLUMNS`/`MORPH_TYPE_TO_TABLE` + dependencia sintética en el orden
topológico.

### 4.3. Catálogo `service_workflows` vacío en destino

Ver § 2 — copiado a mano, y el exporter ganó una entrada de clave natural
compuesta (`workflow_id` remapeado + `label`) para `service_workflow_stages`.

### 4.4. Modal de aviso no se podía cerrar

`visible` era un `computed` que llamaba una función normal leyendo
`localStorage` - eso no es una dependencia reactiva de Vue. `cerrar()` sí
guardaba el dismiss, pero el modal nunca se volvía a evaluar y quedaba
abierto para siempre (sin error en consola). Fix: `ref` + `watch` explícito.
Reportado por el usuario en producción, verificado y desplegado.

### 4.5. Worker de colas con config vieja (causa del intento fallido del negocio 3)

Al configurar la conexión `nexolu_pos` (§ 3), se corrió `config:clear` +
`config:cache` pero **no** `queue:restart` en ese momento puntual. El worker
persistente (`supervisor`, `pos-prod-queue-worker`) siguió corriendo con la
config vieja (sin `NEXOLU_POS_DB_HOST`) hasta el siguiente deploy — un
`host` vacío hace que PDO intente conectar por socket local en vez de TCP,
de ahí `SQLSTATE[HY000] [2002] No such file or directory`. Los deploys
posteriores (§ 4.1/4.2/4.4) ya reiniciaron el worker correctamente; se forzó
un `queue:restart` adicional el 2026-08-26 18:37 para confirmar.

**Lección para el runbook:** cualquier cambio de `.env` en producción que
afecte a un worker persistente necesita `queue:restart` explícito en el
momento, no asumir que el próximo deploy lo va a cubrir a tiempo.

### 4.6. `BusinessDataExporter`: FK de `users` apuntando fuera del negocio (superadmin)

`users` es tabla raíz (scoped por `business_id`, solo se exporta la del
negocio). Pero columnas de auditoría con FK real hacia `users`
(`sales.closed_by_user_id`, `cash_shifts.closed_by_user_id`,
`layaways.created_by_user_id`/`cancelled_by_user_id`,
`reminders.created_by_user_id`, etc. — ver
`database/legacy-schema/schema.sql`) pueden apuntar a un usuario **fuera**
del cierre exportado: en el intento 2 del negocio 3, una fila referenciaba
`users#1`, el superadmin (`mattchaparrof@gmail.com`, `business_id` NULL),
que tocó datos del negocio piloto durante las pruebas. Como `users` no
tenía entrada en `NATURAL_KEY_FALLBACKS`, `remap()` fallaba ruidoso (correcto
que fallara, pero faltaba la estrategia de resolución).

Fix: `NATURAL_KEY_FALLBACKS['users'] = ['email']` (`email` es `UNIQUE` en el
schema legacy) — resuelve por email contra la fila que ya existe en destino
(el superadmin fue copiado a mano, ver § 2), o contra un usuario de un
negocio migrado en una corrida anterior. Si el email no existe todavía en
destino, sigue fallando ruidoso — no hay riesgo de resolver a la persona
equivocada.

Efecto colateral detectado y corregido en el mismo cambio:
`exportUserAssignments()` (§ 4.1) iteraba sobre `array_keys($idMaps['users'])`
para copiar roles/permisos — una vez que `users` resuelve por email, ese
array también incluye usuarios ajenos al negocio (el superadmin), así que
intentaba copiar sus asignaciones de rol también (rol que puede no existir
en destino, o duplicar una asignación que ya existe). Fix: ese paso ahora
itera sobre los ids que el export realmente trajo como parte del negocio
(`$oldIdsByTable['users']`), no sobre todo lo que quedó resuelto en
`$idMaps['users']`.

Corregido y con test (`BusinessDataExporterTest::test_resolves_a_user_outside_the_business_by_email_natural_key`),
suite completa verde (712 tests). Desplegado a producción 2026-08-26
(`pos_deploy.sh` + `queue:restart` explícito para el worker persistente, ver
lección de § 4.5).

### 4.7. `BusinessDataExporter`: scoping de tabla hija por una sola FK descarta filas en silencio cuando las FKs son mutuamente excluyentes

Bug más serio que los anteriores: no fallaba ruidoso en todos los casos,
tenía potencial de **descartar filas en silencio** del export sin que nada
lo señalara (el `report` del comando simplemente mostraría un conteo más
bajo, fácil de no notar).

El scoping de una tabla hija (no-raíz) elegía **una sola** FK de cierre - la
primera que ya tuviera filas padre exportadas - y filtraba TODA la tabla por
esa columna únicamente (`WHERE columna IN (ids)`). Funciona bien cuando
todas las filas de la tabla siempre tienen las mismas columnas FK pobladas
(ej. `sale_items`: `sale_id` y `product_id` van juntos siempre). Se rompe
cuando dos FKs son mutuamente excluyentes por fila - `purchase_lines` tiene
`product_id` XOR `ingredient_id` (nunca ambas): si el negocio ya tenía
`ingredients` exportados en ese punto del recorrido, el algoritmo elegía
`ingredient_id` como la única columna de scope, y `WHERE ingredient_id IN
(...)` nunca matchea las filas de compra de **producto** (`ingredient_id`
IS NULL - un `IN()` no matchea NULL en SQL). Esas `purchase_lines` se
perdían sin ningún error en ese momento; el error solo aparecía después,
cuando otra tabla del cierre (`stock_movements.purchase_line_id`) intentaba
remapear una fila que nunca se exportó: `No se pudo remapear
purchase_lines#34...`.

Fix: en vez de elegir una sola FK, el scoping ahora usa **OR entre todas**
las FKs de cierre que ya tengan filas padre exportadas
(`WHERE product_id IN (...) OR ingredient_id IN (...) OR purchase_id IN
(...)`) - cualquier FK poblada del cierre basta para incluir la fila, sin
depender de cuál se evalúe primero. Es seguro porque los tres lados del OR
ya están scoped al negocio (esa es la garantía de fondo del mecanismo, ver
§ 4.2 del diseño).

Corregido y con test
(`BusinessDataExporterTest::test_scopes_a_child_table_by_any_populated_closure_fk_not_just_the_first_one`) -
verificado que el test falla con el código viejo (reproduce exactamente
`No se pudo remapear purchase_lines#1`) y pasa con el fix. Suite completa
verde (713 tests). Desplegado a producción 2026-08-26 (`pos_deploy.sh` +
`queue:restart`).

**Nota abierta:** dado que este bug podía perder filas sin error visible,
vale la pena, en algún momento, auditar si algún negocio ya migrado tiene
otras tablas hijas con FKs mutuamente excluyentes que se hayan visto
afectadas de la misma forma antes de este fix. Con un solo negocio piloto
migrado hasta ahora (el 3) el riesgo real hoy es bajo (ver § 4.8, la
verificación automática ya lo habría atrapado), pero conviene no perder de
vista el caso para negocios reales futuros.

### 4.8. Verificación automática post-export (nueva, no reemplaza los fixes de arriba)

El usuario pidió, con razón, que `status = completed` no fuera un acto de fe
- el bug de § 4.7 pudo perder filas en silencio sin que nada lo hubiera
notado si nada más las hubiera referenciado. `BusinessDataExporter` ahora
corre `verifyExport()` al final de cada export real (no en `--dry-run`),
**dentro de la misma transacción de destino** - si algo no cuadra, la
excepción aborta la transacción entera (nada queda a medias) y
`MigrateBusinessCommand` marca `failed` con el detalle exacto, igual que
cualquier otro fallo del export.

Qué verifica, y por qué es una segunda opinión genuinamente independiente
del loop de export (no solo re-ejecuta la misma lógica que ya se demostró
insuficiente en § 4.7):

- **Tablas raíz** (`business_id` directo): cuenta filas en legacy vs destino
  para el negocio - comparación directa, sin ambigüedad de FKs.
- **Tablas hija**: en vez de reusar la heurística "OR entre todas las FKs
  pobladas" (la que tuvo el bug), busca la FK **obligatoria (`NOT NULL`)**
  hacia una tabla raíz - ej. `purchase_lines.purchase_id` (nunca
  `product_id`/`ingredient_id`, que son opcionales). Una columna `NOT NULL`
  no tiene el problema de "IN() no matchea NULL" que causó § 4.7, así que
  aunque el loop principal tuviera un bug similar en el futuro, esta
  segunda ruta de código no comparte el mismo punto ciego.
- **`SUM(sales.total)`**: legacy vs destino - un chequeo de valor, no solo de
  conteo. Una fila con el total en cero pasaría cualquier verificación que
  solo cuente filas.
- Tolera que una tabla no exista en la conexión de destino SOLO si además
  el negocio tiene 0 filas de origen ahí (así los tests, que usan una base
  de destino con schema parcial, no generan falsos positivos) - si hay
  filas de origen sin tabla de destino, sigue fallando ruidoso.

Efecto colateral encontrado al escribir el test: la base de destino de test
(`testing_nexolu_pos`) no estaba aislada entre tests - `RefreshDatabase`
solo cubre la conexión por defecto (`mysql`), así que una tabla insertada
por un test anterior sobrevivía para el siguiente. No importaba antes
porque ningún test verificaba nada fuera de lo que ese test mismo tocaba;
`verifyExport()` sí recorre todo el cierre real, así que lo destapó. Fix:
`BusinessDataExporterTest::setUp()` ahora hace `DROP DATABASE` +
`CREATE DATABASE` completo en vez de `CREATE DATABASE IF NOT EXISTS`.

`MigrateBusinessCommand` imprime `Verificación post-export: OK...` cuando
pasa, visible en los logs de cada corrida real.

### 4.9. Catálogo nuevo de medios de pago - activación automática y transparente

No era un bug (ver aclaración más abajo), pero el usuario pidió
explícitamente que dejara de ser un paso manual: un negocio migrado no
debía depender de que alguien abriera Ajustes > Medios de pago y guardara
para pasar del JSON legacy al catálogo normalizado
(`business_pos_payment_methods`) - debía quedar activo de una vez,
transparente para el dueño.

Ya existe `payment-methods:migrate-catalog` en `nexolu-pos-api` que hace
exactamente este mapeo (alias JSON legacy → key del catálogo), pero corre
en un proceso y servidor distintos (nexolu-pos-api en `nexolu-pos-prod`)
sin ruta de invocación directa desde `pos-saas`. Se replicó el mismo mapeo
(`BusinessDataExporter::PAYMENT_METHOD_CATALOG_ALIASES`, copiado de
`MigratePaymentMethodsCatalog::ALIASES`) y se agregó
`activatePaymentMethodsCatalog()`, que escribe directo a
`business_pos_payment_methods` vía la conexión `nexolu_pos` - mismo
approach de conexión directa que el resto del mecanismo (§ 6 de
`CUTOVER_PER_BUSINESS.md`), no HTTP entre apps.

Corre dentro de la misma transacción que el resto del export, después del
loop principal y antes de `verifyExport()`. `pos_payment_methods`/
`business_pos_payment_methods` no están en el schema de legacy (0% - el
monolito nunca las lee ni escribe), así que `discoverClosure()` no las ve y
`verifyExport()` no las cubre - el reporte del comando (`business_pos_payment_methods:
N filas`) es la única confirmación visible por ahora.

**Si el catálogo global (`nexolu-pos-api/database/seeders/PosPaymentMethodSeeder.php`)
agrega o renombra keys, hay que actualizar también el alias map acá** - son
dos copias del mismo mapeo en dos repos, sin forma de compartir código
entre ellos dado que corren en servidores distintos.

Corregido con test
(`BusinessDataExporterTest::test_activates_the_new_payment_methods_catalog_transparently_for_the_migrated_business`).
Efecto colateral: `pos_payment_methods`/`business_pos_payment_methods` no
existen en la base de test de origen tampoco (mismo motivo que en
producción), así que no se pueden crear con `LIKE` - el `setUp()` base
ahora las crea con `CREATE TABLE` explícito. Suite completa verde (715
tests). Desplegado a producción 2026-08-26. El negocio 21 (piloto) no
necesitó backfill - ya tenía el catálogo activado a mano (cash/credit/transfer
en `enabled=1`, guardado por el usuario desde Ajustes antes de este fix).

### 4.10. Impersonación durante migración - probado, revertido, decisión final documentada

El usuario probó impersonar (desde el panel super-admin) el negocio ya
migrado y notó que legacy lo dejaba entrar normal, sin redirigir a la
nueva app - inicialmente pidió que la impersonación respetara el mismo
gate que un usuario real (`CheckBusinessMigrationStatus`, § 3 de
`CUTOVER_PER_BUSINESS.md`), para poder comprobar el redirect en producción.

Se implementó, se desplegó, y se revirtió en la misma sesión: el usuario
aclaró que necesita seguir pudiendo impersonar y navegar negocios
migrados como super-admin (soporte/administración), y que para probar el
redirect específicamente prefiere loguearse directo como el dueño del
negocio en vez de impersonar. **Decisión final: `CheckBusinessMigrationStatus`
sigue eximiendo `impersonator_id`, como estaba desde el principio** (mismo
criterio que `CheckBusinessSubscription`) - `git revert` del commit que lo
había cambiado, test original restaurado
(`test_impersonating_superadmin_is_not_redirected_even_if_completed`).

**Vale la pena que quede anotado para el futuro:** esta exención sigue
significando que un superadmin impersonando puede escribir en un negocio
con `status = migrating` (el freeze de § 4.5 no lo cubre) - riesgo
aceptado explícitamente por el usuario, no un descuido. Si esto llega a
causar un problema real (un superadmin escribiendo sin querer en un
negocio a mitad de export), revisar este párrafo primero.

Suite completa verde (715 tests) con el revert aplicado. Desplegado a
producción 2026-08-26.

## 5. Estado del piloto (negocio de prueba, id=3 en legacy producción)

- Intento 1 (2026-08-26 13:26): `notified` → `migrating` → **`failed`**
  (causa: § 4.5, ya resuelta).
- Intento 2: **`failed`** (causa: § 4.6, corregido y desplegado).
- Intento 3 (notificado 2026-08-26 14:36): **`failed`** (causa: § 4.7,
  corregido y desplegado).
- Intento 4: **`completed`**. El negocio 3 ("Cafetería Delicious Moments")
  quedó en `nexolu-pos-prod` con `business_id = 21`.

**Verificación manual (read-only, 2026-08-26) de que quedó íntegro** -
conteos por tabla, legacy (`business_id=3`) vs destino (`business_id=21`):

| Tabla | Legacy | Destino |
|---|---|---|
| products | 139 | 139 |
| clients | 3 | 3 |
| sales | 4367 | 4367 |
| sale_items | 4520 | 4520 |
| expenses | 255 | 255 |
| purchases | 5 | 5 |
| stock_movements | 3566 | 3566 |
| suppliers | 2 | 2 |
| ingredients | 28 | 28 |
| users | 4 | 4 |

`SUM(sales.total)`: `$17.640.900` en ambos lados. Todo cuadra. A partir de
la próxima migración real, este chequeo lo hace `verifyExport()`
automáticamente (§ 4.8) - no debería hacer falta repetirlo a mano.

### Medios de pago del negocio migrado - aclaración, no es un bug

El usuario notó que en `new-pos.nexolu.co/ajustes` (Ventas > Métodos de
pago) los toggles del negocio migrado aparecen todos apagados, y preguntó
si el proceso de migración debía activarlos. **No es una inconsistencia**:
`businesses.payment_methods` (JSON legacy: efectivo/transferencia/fiado)
sí se copió correctamente - verificado igual que la tabla de arriba, byte
a byte. Lo que está vacío es `business_pos_payment_methods` (el catálogo
normalizado nuevo), y eso es **deliberado** para todo negocio, migrado o
nativo - ver `Business::posPaymentMethods()`/`paymentMethods()` en
`nexolu-pos-api/app/Models/Business.php` § 214-266: un negocio solo pasa al
catálogo nuevo la primera vez que un admin abre esa pantalla y guarda; hasta
entonces `paymentMethods()` cae automáticamente al JSON legacy y el negocio
opera normal. La propia UI se lo dice al admin en un banner: *"Hoy tienes
configurado (todavía sin pasar al catálogo nuevo): Efectivo, Transferencia,
Fiado. Selecciona abajo los que uses y guarda para pasarlos al catálogo."*

**Actualización:** implementado en § 4.9 - `businesses:migrate` ya activa
`business_pos_payment_methods` automáticamente para negocios migrados de
acá en adelante (no para negocios nativos existentes, esa sigue siendo la
decisión aparte de `docs/CUTOVER_TODO.md`). El negocio 3/21 en particular
no necesitó backfill - ya lo tenía activado a mano.

### Negocio 5 ("Restaurante de prueba") - segundo piloto, `completed`

Migrado 2026-08-26 16:47 (`migration_started_at` 16:47:20, `migrated_at`
16:47:23) con todos los fixes de § 4.6-4.9 ya desplegados. `business_id`
en destino = 22. `verifyExport()` pasó sin intervención.

**Susto sin fundamento (2026-08-27):** una comparación posterior legacy
vs destino mostró `stock_movements` 74→86 y `SUM(sales.total)`
$941.400→$930.700 - parecía una fila perdida. Investigado a fondo
(`log_actions` de `nexolu-pos-api`, tabla de auditoría): el negocio ya
está **vivo** en la app nueva - una venta se reversó (`sale.reversed`,
23:39:20, exactamente los mismos $14.000 de diferencia) y se creó una
venta nueva 10 segundos después, más impersonación activa y actividad real
(cobros, aperturas de turno). No hay bug: comparar un snapshot legacy
(congelado) contra un negocio ya en uso real en destino **siempre** va a
divergir - eso es correcto, no una señal de alarma. **Lección:** la
verificación por conteo/suma solo es confiable INMEDIATAMENTE después de
migrar (que es exactamente cuándo corre `verifyExport()`), no como
auditoría ad-hoc días/horas después con el negocio ya en producción real.

### 4.11. SSO de un solo login al migrar

Encontrado probando el negocio 5: un usuario de un negocio migrado hacía
login normal en legacy, `CheckBusinessMigrationStatus` lo redirigía a
`new-pos.nexolu.co`, y tenía que volver a escribir las mismas credenciales
ahí - dos apps, dos sesiones, nada que las conectara.

Fix (los tres repos, ver commits del 2026-08-27):

- **`pos-saas`**: `App\Http\Responses\LoginResponse` (la clase real
  bindeada a `Laravel\Fortify\Contracts\LoginResponse` en
  `JetstreamServiceProvider` - **no** `App\Http\Controllers\Auth\LoginController`,
  que resultó ser scaffolding sin usar, la ruta real de login es
  `Laravel\Fortify\AuthenticatedSessionController@store`, confirmado con
  `route:list` después de escribir el fix en el lugar equivocado la
  primera vez). Con el email/password en texto plano que Fortify todavía
  tiene en el request (único punto de todo el flujo donde existe), le pide
  un token nuevo a `nexolu-pos-api` vía su `/api/v1/login` ya existente
  (cero cambios de ese lado), cierra la sesión de legacy, y redirige con
  `#token=...` en el **fragmento** (nunca query string - no queda en
  logs/Referer). Si el handoff falla por lo que sea, cae al redirect
  simple de siempre - nunca bloquea el login de legacy.
- **`nexolu-pos-front`**: `stashSsoTokenFromUrl()` (`tokenStorage.ts`,
  llamado desde `main.ts` junto a `stashPendingWelcomeFromUrl()` ya
  existente) guarda el token antes de que exista el store de Pinia -
  `router.beforeEach` ya sabía rehidratar un token existente vía
  `fetchCurrentUser()` (mismo camino que un F5 con sesión viva), no hizo
  falta bootstrap especial aparte de guardar el token a tiempo.
- **`nexolu-pos-api`**: sin cambios - `/api/v1/login` ya hacía exactamente
  lo necesario (`$user->createToken(...)->plainTextToken`, Sanctum por
  token, no por cookie - por eso un token en el fragmento alcanza sin
  problema de dominio cruzado).

Con test (`LoginSsoHandoffTest`, 3 casos: token exitoso, fallback si el
handoff falla, negocio no migrado sin tocar). Suite completa verde (728
tests). Desplegado a producción en los dos repos.

**Nota de rama:** al comitear esto, `master` de `pos-saas` ya tenía un
commit (`feat(payments): migrar el webhook de Wompi...`) que `staging` no
tiene - las ramas dejaron de estar sincronizadas (algo ajeno a este
piloto). No se forzó la sincronización unilateralmente: se comiteó sobre
`master` (lo que despliega producción) y se avisó al usuario para que
decida si hace falta reconciliar.

## 6. Próximos pasos

1. ~~Reintentar la migración del negocio 3 desde el panel.~~ Hecho, `completed`.
2. ~~Si migra completo, verificar en `nexolu-pos-prod`.~~ Hecho, todo cuadra.
3. ~~Migrar un segundo negocio de prueba.~~ Hecho (negocio 5 → destino 22),
   `completed`, verificado.
4. ~~SSO de login para negocios migrados.~~ Hecho (§ 4.11).
5. Reconciliar `master`/`staging` en `pos-saas` (ver nota de § 4.11) -
   pendiente, decisión del usuario.
6. Verificar que legacy bloquee el negocio para siempre incluso para
   accesos directos (no solo login) - probablemente ya cubierto por
   `CheckBusinessMigrationStatus`, pero no se ha probado explícito aparte
   del flujo de login.
7. Si todo sigue saliendo bien, definir con el usuario el criterio para
   empezar a migrar negocios reales.
