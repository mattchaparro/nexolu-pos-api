# Backlog de migración — jobs y funcionalidad de legacy pendientes

Este documento lleva la cuenta de lo que falta migrar de `pos-saas-legacy`
(los ~12 jobs del `Kernel.php` legacy y los módulos que dependen) y qué lo
bloquea. A diferencia de `CUTOVER_TODO.md` (que es deuda que solo se puede
pagar retirando el monolito), esto es simplemente trabajo no hecho todavía -
se tacha en cuanto se construye, sin precondición de cutover.

---

## Jobs programados de legacy

| Job (legacy) | Estado | Bloqueado por |
|---|---|---|
| `subscriptions:notify-expiring` | ✅ Migrado | — |
| `trials:notify-expiring` | ✅ Migrado | — |
| `audit:prune` | ✅ Migrado | — |
| `appointments:send-reminders` | ✅ Migrado (solo correo) | — |
| `inventory:send-low-stock-alerts` | ✅ Migrado (correo, productos + ingredientes) | Canal WhatsApp: ver abajo. |
| `reminders:send-whatsapp-notifications` | ✅ Migrado | — |
| `expenses:register-scheduled` | ✅ Migrado | — |
| `notifications:send-daily-whatsapp-summary` | ❌ Pendiente | Motor de insights (`ResumenInteligenteInsight` en legacy) - no existe equivalente aquí, es un módulo aparte |
| `businesses:send-trial-winback` | ❌ Pendiente | Decisión de mailer/Brevo pendiente (no evaluado a fondo todavía) |
| `businesses:warn-inactive-trial` | ❌ Pendiente | Nada - es portable ya, paralelo al filtro de negocios inactivos de SuperAdmin ya construido |
| `queue:work` / `database:backup` | N/A | Infra de hosting legacy, no aplica a esta API (Cloud/Sail maneja colas y backups distinto) |
| `exchange-rate:fetch` | ❌ Sin decidir | Encaje arquitectónico poco claro - los costos de IA ya se rastrean en USD en el IA Core, no en COP aquí |

## WhatsApp Cloud API - fases pendientes (ver commit de Fase 1)

- **Fase 2 - WhatsApp Flows** (confirmación nativa de borradores `gasto`,
  `entrada_inventario`, `entrada_ingrediente`): requiere primero un proxy en
  esta API hacia `POST /v1/drafts/{id}/confirm` del IA Core (no existe
  todavía - hoy solo existe el proxy de `/v1/chat`). El módulo Ingredientes
  que bloqueaba `entrada_ingrediente` ya está migrado.
- **Fase 3 - Notificaciones proactivas por WhatsApp**:
  - Resumen diario (`resumen_diario`): depende del motor de insights.
  - ~~Recordatorios (`recordatorio`)~~ ✅ Migrado (`reminders:send-whatsapp-notifications`).
  - Inventario bajo (`inventario_bajo`): depende de `AiChannelIdentity`
    (ya existe, Fase 1) + `WhatsAppRecipients` (falta portar, es trivial) +
    extender `InventorySendLowStockAlerts` con el canal WhatsApp.

## Módulos completos que faltan (no son solo un job)

- ~~**Ingredientes/recetas**~~ ✅ Migrado (`Ingredient`, `Product::ingredients()`,
  `ProductAvailability::effectiveStock()`, `StockService::ingredientEntry/Exit/Adjust`,
  API en `/v1/ingredients` + `/v1/ingredient-stock-movements`).
  `LowStockAlertReport` ya combina productos + ingredientes.
- ~~**Retrofit del flujo de ventas para productos con receta**~~ ✅ Migrado:
  `SaleService::applyItems/reverseSale` y `OpenTabService::syncItems/cancelOpenTab`
  ahora llaman `registerIngredientsConsumption`/`restoreIngredientsConsumption`,
  y la validación de stock disponible usa `ProductAvailability::effectiveStock()`
  en vez de `product->stock` crudo para productos con receta.
  `StockService::registerSale/registerSaleReversal` no mueven `products.stock`
  cuando el producto es gestionado por receta (`isStockManagedByIngredientsRecipe()`).
  **Sin portar todavía**: el mismo retrofit en `LayawayService` (apartados
  reservan/liberan stock via `reserveForLayaway`/`releaseLayawayReservation`,
  que siguen sin considerar recetas - un apartado de un producto con receta
  hoy mueve `products.stock`, que es la columna "fantasma"). Tampoco se portó
  el CRUD de la receta en sí (`ingredient_product` attach/sync desde el
  endpoint de productos) ni `PurchaseService` admitiendo líneas de compra de
  ingrediente (`purchase_lines.ingredient_id` existe en el schema compartido,
  sin usar).
- ~~**Reminders**~~ ✅ Migrado (`Reminder` + `ReminderService`, API en
  `/v1/reminders`). El `remindable` polimórfico existe en el modelo pero
  todavía no lo alimenta nada (los hooks desde Purchase/Supplier/
  FixedExpenseTemplate/Expense de legacy no se portaron - ver nota abajo).
- ~~**FixedExpenseTemplate**~~ ✅ Migrado (modelo + `expenses:register-scheduled`).
  Sin API propia todavía (CRUD de plantillas) - solo el job de creación
  automática de gastos.
- ~~**Checkout de suscripción vía Nexolu Payments Core**~~ ✅ Migrado: este
  POS ya no habla con Wompi directo. `SubscriptionService::initiateCheckout()`
  crea una `SubscriptionCheckoutOrder` pendiente y llama
  `POST /v1/payments/intents` del Core (`PaymentsCoreService`);
  `PaymentsCoreWebhookController` recibe la confirmación firmada
  (`POST /api/webhooks/payments-core`, verifica `X-Nexolu-Signature`/
  `X-Nexolu-Timestamp`), activa la suscripción (`Business::activate()`) y
  registra el `SaasSubscriptionPayment`, con `lockForUpdate()` + `status`
  como guarda de idempotencia (mejora sobre el legacy, que no tenía lock y
  dejaba las órdenes rechazadas en `pending` para siempre - acá se marcan
  `failed`/`cancelled` explícitamente). API en `GET /v1/subscription/status`
  y `POST /v1/subscription/checkout`.
  **Deliberadamente fuera de alcance** (no existían en el legacy vigente o
  no se pidieron):
  - **Addon de IA vía suscripción**: en el legacy actual, `ai_addon_included`
    siempre es `false` (el código que lo cobraba quedó muerto - ver
    `SubscriptionPricingService::breakdown()` del legacy, que lo fuerza a 0).
    Las columnas `ai_addon_included`/`ai_addon_amount_cop` existen en la
    orden pero nunca se llenan con datos reales todavía; tampoco existen los
    campos `ai_chat_*` en el `Business` de este repo. Si se decide revivir el
    cobro del addon, hay que portar `AiAccessService::contratarAddon()` del
    legacy (¡no `activarAddon()`, que es un método que no existe - bug
    inofensivo en legacy porque esa rama nunca corre hoy!).
  - **Promociones/descuentos de precio** (`plan_after_promo_cop`, ciclos
    pagados, etc. de `SubscriptionPricingService` del legacy): no existe
    ningún equivalente en este repo hoy. El monto se calcula con
    `Business::monthlyPriceCop()` (precio personalizado o precio del plan),
    ya usado por `SuperAdminBusinessService`/`PlatformFinanceService` - no se
    replicó la lógica de promos porque no hay nada que promocionar todavía.
  - **Notificaciones por correo** de pago aprobado/rechazado a superadmin y
    al admin del negocio (el legacy sí las manda). No se portaron.
  - **`WompiFees`** (comisión de Wompi): confirmado que no participa del
    flujo de cobro/activación en el legacy, solo de un reporte financiero
    interno (`PlatformFinanceService` legacy). No se necesita replicar acá -
    si se quiere mostrar comisión neta, pedírsela al Core en vez de
    recalcularla con una fórmula propia.
  - **`GET /v1/payments/transactions/{reference}`** (polling del Core): el
    Core lo expone para que el frontend haga polling de UX mientras espera
    el webhook, pero no se consume desde este POS todavía - no hay
    `PaymentsCoreService::getTransaction()` usado por ningún controller aún
    (el método existe, listo para cuando el frontend lo necesite).
- **Motor de insights** (`ResumenInteligenteInsight` en legacy): calcula
  salud del negocio/ventas vs. ayer/gasto inusual/prioridad del día. No
  evaluado a fondo todavía - es candidato a vivir como una Capability más
  (`App\Capabilities`) reutilizable tanto por el resumen diario de WhatsApp
  como por el chat de IA, en vez de portarlo tal cual de legacy.

## Deuda dejada a propósito en el módulo Reminders

- **Hooks polimórficos de otros módulos**: en legacy, una compra a crédito
  (`Purchase`), un proveedor (`Supplier`), una plantilla de gasto fijo
  (`FixedExpenseTemplate`, patrón toggle on/off) y un gasto (`Expense`)
  pueden crear un `Reminder` ligado via `remindable_type`/`remindable_id`.
  Ninguno de esos hooks se portó todavía - el campo `remindable` existe en
  el modelo pero nada lo alimenta. Portar cuando se retome cada uno de esos
  módulos (Purchase y Expense ya existen aquí, así que son los primeros
  candidatos).
- **CRUD de `FixedExpenseTemplate`**: solo se portó el modelo y el job de
  creación automática. Falta la API para que un negocio cree/edite/desactive
  sus propias plantillas de gasto fijo (en legacy, `Admin/FixedExpenseTemplatesController`).
