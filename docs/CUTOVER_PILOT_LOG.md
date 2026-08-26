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

## 5. Estado del piloto (negocio de prueba, id=3 en legacy producción)

- Intento 1 (2026-08-26 13:26): `notified` → `migrating` → **`failed`**
  (causa: § 4.5, ya resuelta).
- Pendiente: volver a notificar ("Notificar migración" - `failed` permite
  re-notificar) y lanzar de nuevo desde el panel super-admin.

## 6. Próximos pasos

1. Reintentar la migración del negocio 3 desde el panel.
2. Si migra completo, verificar en `nexolu-pos-prod`: catálogo de pagos del
   negocio, usuarios con su rol correcto, datos de ventas/productos
   presentes y sin colisión de IDs con negocios nativos.
3. Verificar el redirect real (`?bienvenida=1`) y que legacy bloquee el
   negocio para siempre.
4. Si todo sale bien con este negocio de prueba, definir con el usuario el
   criterio para empezar a migrar negocios reales.
