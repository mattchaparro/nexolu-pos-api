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
  ~~**Sin portar todavía**: el mismo retrofit en `LayawayService`~~ ✅ Migrado:
  `LayawayService::applyItems/cancel/updateItems` ahora llaman
  `StockService::reserveIngredientsForLayaway`/`releaseIngredientsLayawayReservation`
  (nuevas, análogas a `registerIngredientsConsumption`/`restoreIngredientsConsumption`
  de ventas) y validan disponibilidad con `ProductAvailability::effectiveStock()`.
  `reserveForLayaway`/`releaseLayawayReservation` ganaron el mismo guard
  `isStockManagedByIngredientsRecipe()` que ya tenían `registerSale`/
  `registerSaleReversal`, así que un apartado de un producto con receta ya
  no mueve `products.stock`. De paso se extrajo `adjustIngredientsForRecipeProduct()`
  como núcleo compartido de las 4 variantes de movimiento de ingredientes
  (venta/reverso/reserva de apartado/liberación de apartado - antes las dos
  de venta duplicaban el mismo bucle "un `StockMovement` por ingrediente").
  ~~Sigue pendiente: `PurchaseService` admitiendo líneas de compra de
  ingrediente~~ ✅ Migrado: una línea de `POST /v1/purchases` ahora es de
  producto O de ingrediente (nunca ambos ni ninguno - validado en
  `StorePurchaseRequest::validateLineItemRules()`, mismas columnas
  mutuamente excluyentes que `stock_movements`). Nueva
  `StockService::registerIngredientPurchase()` (análoga a `registerPurchase()`
  de producto, con `purchase_line_id` para trazabilidad);
  `PurchaseService` recalcula el costo promedio ponderado del ingrediente
  y propaga el nuevo costo a los productos con receta que lo usan
  (`Ingredient::syncLinkedProductCosts()`, una vez por ingrediente tocado
  tras el loop, no por línea). Rechaza comprar un producto que ya gestiona
  su stock por receta ("compra los ingredientes, no el producto terminado"),
  igual que el legacy. Con esto queda cerrado por completo el retrofit de
  Ingredientes/recetas iniciado en este backlog.
- ~~**CRUD de la receta desde el endpoint de productos**~~ ✅ Migrado: igual
  que el legacy, `ingredients` (`[{ingredient_id, quantity}]`) viaja como
  parte del payload de `POST`/`PUT /v1/products`, no por un endpoint aparte -
  `ProductService::syncIngredients()` hace el `sync()` del pivot
  `ingredient_product` + `Product::syncRecipeCost()`. Reglas de negocio
  portadas en `Store/UpdateProductRequest`: un servicio o un producto de
  venta única no puede tener receta; con receta no vacía se fuerza
  `track_stock=true`; sin el feature `ingredients` la receta enviada se
  ignora en silencio. `ProductResource` expone `ingredients`/`has_recipe`
  (cargados condicionalmente en `index`/`show` solo si el negocio tiene el
  feature). **Mejora sobre el legacy**: la regla "no se puede activar
  `is_single_sale` con receta" en legacy solo miraba el estado YA guardado
  del producto, así que quitar la receta y activar `is_single_sale` en la
  misma request quedaba bloqueado sin razón - acá se valida el estado
  EFECTIVO (payload si lo manda, si no el persistido), permitiendo ambos
  cambios en un solo `PUT`.
- ~~**Reminders**~~ ✅ Migrado (`Reminder` + `ReminderService`, API en
  `/v1/reminders`). El `remindable` polimórfico existe en el modelo pero
  todavía no lo alimenta nada (los hooks desde Purchase/Supplier/
  FixedExpenseTemplate/Expense de legacy no se portaron - ver nota abajo).
- ~~**FixedExpenseTemplate**~~ ✅ Migrado por completo: CRUD en
  `/v1/fixed-expense-templates` (gate `expenses.manage`, igual que el
  legacy que lo trata como configuración administrativa, no algo que un
  empleado con solo `expenses.create` deba tocar) + dos acciones extra del
  controller de legacy que también se portaron:
  - `POST .../register-now`: disparo manual del gasto de un mes puntual
    (`FixedExpenseTemplateService::registerNow()`), rechaza si ese mes ya
    tiene su gasto o si no hay un monto resuelto (override o el de la
    plantilla).
  - `POST .../toggle-reminder`: primer hook real de `remindable`
    polimórfico de `Reminder` (ver nota de deuda abajo, ahora parcialmente
    pagada) - crea/quita un recordatorio mensual sobre el `day_of_month`
    de la plantilla.
  De paso, `expenses:register-scheduled` se refactorizó para reusar
  `FixedExpenseTemplateService::registerForMonth()` (la misma
  comprobación de idempotencia por mes que ahora usa también el disparo
  manual, en vez de tenerla duplicada en el comando).
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
  Además, ya migrado en una segunda pasada:
  - ~~**Promociones/descuentos de precio**~~ ✅ Migrado: `SystemConfig` +
    `SystemConfigStore` (config editable en caliente, tabla `system_configs`
    ya existía en el schema compartido, sin usar) + `SubscriptionPricingService`
    (`breakdown()`/`totalCop()`, con `config/marketing.php` como default:
    40% de descuento los primeros 2 ciclos) + `Business::subscriptionPromoCyclesConsumed()`.
    `SubscriptionService::initiateCheckout()` y `GET /v1/subscription/status`
    (campo `pricing`) ya usan el monto con promo aplicada, no el precio de
    lista. `PlatformFinanceService::realMonthlyRecurringRevenueCop()` tambien
    se actualizó a usar `SubscriptionPricingService::totalCop()` (antes usaba
    `Business::monthlyPriceCop()`, que ignora la promo - así calculaba el
    legacy también). `Business::monthlyPriceCop()` (precio de lista, sin
    promo) se mantiene intacto para lo que ya lo usaba
    (`SuperAdminBusinessService::resolveActivationAmount()`, activación
    manual del superadmin - una promo pensada para autoservicio online no
    aplica ahí). **Gap real**: no hay todavía un `SuperAdmin\SettingsController`
    (existe en legacy) para que el superadmin edite `marketing.promo_*`/
    `plans.*_price_cop` sin tocar la base de datos directamente - hoy sólo
    corren los defaults de `config/marketing.php` (65000 COP, 40% x 2 meses)
    hasta que alguien escriba una fila en `system_configs`.
  - ~~**Notificaciones por correo de pago**~~ ✅ Migrado:
    `SubscriptionPaymentResultMail` (al admin del negocio,
    `is_business_owner=true`) y `SubscriptionPaymentSuperadminNoticeMail`
    (a cada usuario con rol `superadmin`), encoladas (`Mail::queue()`, no
    síncronas) desde `PaymentsCoreWebhookController::notifyPaymentResult()`
    tanto en `payment.approved` como en `payment.declined`/`payment.error`.
  - ~~**Polling `GET /v1/payments/transactions/{reference}`**~~ ✅ Migrado:
    `SubscriptionService::checkoutStatus()` + `GET /v1/subscription/checkout/{reference}`,
    scoped al negocio del usuario autenticado; sólo llama al Core cuando la
    orden local sigue `pending` (si ya está `confirmed`/`failed`/`cancelled`,
    responde con el estado local sin tocar la red).
  **Sigue deliberadamente fuera de alcance**:
  - **Addon de IA vía suscripción**: en el legacy actual, `ai_addon_included`
    siempre es `false` (el código que lo cobraba quedó muerto - ver
    `SubscriptionPricingService::breakdown()` del legacy, que lo fuerza a 0).
    Las columnas `ai_addon_included`/`ai_addon_amount_cop` existen en la
    orden pero nunca se llenan con datos reales todavía; tampoco existen los
    campos `ai_chat_*` en el `Business` de este repo. Si se decide revivir el
    cobro del addon, hay que portar `AiAccessService::contratarAddon()` del
    legacy (¡no `activarAddon()`, que es un método que no existe - bug
    inofensivo en legacy porque esa rama nunca corre hoy!).
  - **`WompiFees`** (comisión de Wompi): confirmado que no participa del
    flujo de cobro/activación en el legacy, solo de un reporte financiero
    interno (`PlatformFinanceService` legacy). No se necesita replicar acá -
    si se quiere mostrar comisión neta, pedírsela al Core en vez de
    recalcularla con una fórmula propia.
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
  ~~Ninguno de esos hooks se portó todavía~~ **Parcial**: el de
  `FixedExpenseTemplate` (`toggleReminder()`, ver arriba) ya está portado -
  quedan Purchase, Supplier y Expense. Portar cuando se retome cada uno de
  esos módulos (Purchase y Expense ya existen aquí, así que son los
  primeros candidatos).
