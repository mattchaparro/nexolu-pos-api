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
| `inventory:send-low-stock-alerts` | ✅ Migrado (correo + WhatsApp, productos + ingredientes) | — |
| `reminders:send-whatsapp-notifications` | ✅ Migrado | — |
| `expenses:register-scheduled` | ✅ Migrado | — |
| `notifications:send-daily-whatsapp-summary` | ✅ Migrado | — |
| `businesses:send-trial-winback` | ✅ Migrado | — |
| `businesses:warn-inactive-trial` | ✅ Migrado | — |
| `queue:work` / `database:backup` | N/A | Infra de hosting legacy, no aplica a esta API (Cloud/Sail maneja colas y backups distinto) |
| `exchange-rate:fetch` | ❌ Sin decidir | Encaje arquitectónico poco claro - los costos de IA ya se rastrean en USD en el IA Core, no en COP aquí |

Los dos jobs de correo de reactivación (`send-trial-winback`,
`warn-inactive-trial`) se portaron usando el mismo patrón ya establecido en
esta API (`Mailable` + `Mail::to()->send()`, logueado automático a
`email_logs` vía `LogSentEmail`), **no** el `MailService` propio de legacy
que llama la API HTTP de Brevo directo (`BREVO_API_KEY`) - esta API ya envía
todo por SMTP a través del relay de Brevo (`MAIL_MAILER=smtp`,
`smtp-relay.brevo.com`), así que no hacía falta un segundo camino de envío.
`Business::extendTrial()` (usado por el winback) ya existía, portado junto
con el modelo `Business`.

## WhatsApp Cloud API - fases pendientes (ver commit de Fase 1)

- ~~**Fase 2 - WhatsApp Flows**~~ ✅ Migrada la mecánica genérica; **el mapeo
  de campos del Flow de gasto es un mejor-esfuerzo sin verificar**, ver
  detalle abajo.
  - `App\Services\AiDraftService` + `POST/DELETE /v1/ai/drafts/{id}/confirm`
    y `.../discard`: proxy hacia el IA Core (antes solo existía el de
    `/v1/chat`). Reenvía el código de estado del Core tal cual (200/404/409),
    para que tanto un futuro frontend como el job de WhatsApp lo interpreten
    sin adivinar. Mismo gate `ai_chat.use` que el chat.
  - `WhatsAppCloudClient::sendFlow()` portado (genérico, sin nada
    específico de un tipo de borrador).
  - `ProcessWhatsAppFlowReply`: procesa la respuesta del Flow (`nfm_reply`
    en el webhook, ya ruteado en `WhatsappWebhookController`). A diferencia
    de legacy, el `flow_token` es directamente el id del borrador en el IA
    Core (ese servicio ya sabe a qué herramienta/negocio pertenece - no hace
    falta codificar el tipo en el token). Genérico: no sabe qué campos trae
    cada Flow, todo lo que llega (menos `flow_token`) se manda como override
    de valores al confirmar.
  - `ProcessWhatsAppInbound::sendExpenseFlow()`: cuando el chat de WhatsApp
    devuelve un borrador `crear_gasto` pendiente, manda el Flow en vez de
    solo avisar "confirmalo desde el POS" - **si** `services.whatsapp.flows.gasto.id`
    está configurado (por ahora vacío, mismo patrón tolerante que las
    plantillas: sin `id`, cae al aviso de texto de siempre).
  - **Deuda dejada a propósito, no verificable sin el Flow real de Meta**:
    el nombre de pantalla (`screen: 'GASTO'`) y los campos que se le mandan
    al Flow (`concepto`/`monto`/`fecha`/`tipo_gasto`/`tipos`) son mi mejor
    esfuerzo contra los argumentos reales de `CreateExpenseCapability`, NO
    una copia verificada del Flow JSON publicado en Meta (a diferencia de
    legacy, que sí usaba su propio Flow con esos campos exactos - pero con
    `type_id` numérico en vez del `tipo_gasto` de nombre libre que espera
    esta API). Hay que confirmar/ajustar el mapeo una vez exista
    `WHATSAPP_FLOW_GASTO_ID` real y se pueda probar contra Meta de verdad.
  - **Bloqueados por falta de write capability, no por WhatsApp**: los Flows
    de `entrada_inventario`/`entrada_ingrediente` de legacy no se portaron
    porque **no existe todavía** una tool/capability de "registrar entrada de
    stock/ingrediente" en el catálogo de IA de esta API (`app/Capabilities`
    solo tiene `crear_gasto`, `crear_producto`, `crear_cliente` como
    escrituras) - sin esa capability no hay borrador de ese tipo que un Flow
    pudiera confirmar. Es un ítem de "AI Capabilities", no de "WhatsApp".
- **Fase 3 - Notificaciones proactivas por WhatsApp**:
  - ~~Resumen diario (`resumen_diario`)~~ ✅ Migrado (`notifications:send-daily-whatsapp-summary`
    + `SmartSummaryInsight`), con el nombre real de la plantilla
    (`daily_business_summary`) ya puesto en `config/services.php`.
  - ~~Recordatorios (`recordatorio`)~~ ✅ Migrado (`reminders:send-whatsapp-notifications`).
  - ~~Inventario bajo (`inventario_bajo`)~~ ✅ Migrado:
    `InventorySendLowStockAlerts` ahora manda por correo Y/O WhatsApp, los
    dos canales independientes (un negocio puede tener uno prendido y el
    otro apagado). Plantilla real `low_stock_alert` (APPROVED en el mismo
    WABA de legacy) en `config/services.php`. Mismo formato de 3 parametros
    que legacy: negocio, cantidad total, bloque de hasta 5 items mas
    urgentes separados por `\x0B` (Meta rechaza `\n` en parametros de
    plantilla).

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
- ~~**Motor de insights**~~ ✅ Migrado por completo, dashboard-ready (se
  revirtió la decisión de alcance reducido de una pasada anterior: el
  producto sí va a tener dashboard, así que valía la pena portar la
  arquitectura completa, no solo lo que necesitaba WhatsApp). Estructura
  igual que legacy: `App\Services\Ai\Contracts\{AiInsightDefinition,
  HasSuggestedAction, ValidatesGeneratedText}` (renombrados a inglés, ver
  regla de idioma) + 7 clases en `App\Services\Ai\Insights` (`SmartSummaryInsight`
  = orquestador `resumen_inteligente`, `DailyOverviewInsight` = `panorama_diario`,
  `ExpensesSummaryInsight` = `gastos_resumen`, `IngredientsSummaryInsight` =
  `ingredientes_resumen`, `CashClosingHistoryInsight` = `cierres_historico`,
  `ReceivablesSummaryInsight` = `fiados_resumen`, `PayablesSummaryInsight` =
  `cuentas_por_pagar`), registradas en `InsightCatalog`.
  `App\Services\AiInsightService` es el cache-aside sobre la tabla compartida
  `ai_insights` (ya existía en `schema.sql`, sin usar hasta ahora) - a
  diferencia de legacy, no valida cupo de mensajes ni add-on (`AiAccessService`
  no se portó, ver nota de "fuera de alcance" de suscripciones más abajo): el
  acceso a IA en esta API ya se resuelve una sola vez, al permiso
  `ai_chat.use` (mismo gate que `POST /v1/ai/chat`), no por insight.
  API nueva: `GET /v1/insights` (lista todo lo que vale la pena mostrar) y
  `POST /v1/insights/{tipo}/refresh` (recarga forzada de un tipo puntual).
  **Requirió tocar `nexolu-ia-core`** (repo aparte): `/v1/chat` está armado
  para conversación + loop de herramientas, no encaja con "redactar 1-2
  frases de un system+user prompt propio, sin historial". Se agregó
  `POST /v1/completions` ahí (rama `claude/insight-completions-endpoint`,
  pusheada, sin PR todavía) - mismo `ModelRouter`/`ProviderRegistry` y mismo
  registro de uso/costo por negocio que el chat, sin persistir conversación.
  `App\Services\AiCompletionService` en el POS es el cliente hacia ese
  endpoint nuevo.
  Bug real de legacy corregido al portar (no solo trasladado): `calcularSalud()`
  contaba "productos por agotarse" a partir de la lista de nombres ya topada
  a 3 para mostrar en el dashboard (`productos_por_agotarse` de
  `DailyOverviewInsight`), así que un negocio con 20 productos cerca de
  agotarse computaba la misma salud que uno con 3. `SmartSummaryInsight`
  cuenta el total real bajo umbral vía `LowStockAlertReport`.
  De paso, `LowStockAlertReport` (que antes ordenaba solo por stock crudo) se
  alineó con el `StockUrgency` completo de legacy (velocidad real de venta/consumo,
  no cercanía al umbral) - esto ya estaba señalado como TODO en su propio
  docblock desde que se construyó, en el módulo de Inventario.
  `notifications:send-daily-whatsapp-summary` ahora consume
  `SmartSummaryInsight::gatherData()` directamente (sin pasar por
  `AiInsightService` ni el modelo, igual que legacy: esa notificación no
  cuesta tokens de IA a propósito) - se retiró `DailyBusinessSummaryService`,
  que quedó completamente redundante con `SmartSummaryInsight`.
  También se aprovechó para poner el nombre real de la plantilla
  `resumen_diario` en `config/services.php` (`daily_business_summary`,
  `es_CO` - el mismo WABA que legacy, EN REVISION al 2026-07-23 según su
  propio comentario), reemplazando el placeholder `null` de la pasada
  anterior.
  **Todavía sin portar del legacy**: los `teaser()`/`accionSugerida()` no se
  probaron contra un frontend real (no existe todavía); y la invalidación por
  evento (`AiInsightService::invalidate()` existe, pero nada la llama aún -
  legacy la dispara al registrar un gasto, un abono, etc.) queda para cuando
  el dashboard consuma esto de verdad y se sienta el "insight cacheado
  contradice el dato fresco".

## Deuda dejada a propósito en el módulo Reminders

- ~~**Hooks polimórficos de otros módulos**~~ ✅ Migrado por completo: en
  legacy, una compra a crédito (`Purchase`), un proveedor (`Supplier`), una
  plantilla de gasto fijo (`FixedExpenseTemplate`) y un gasto (`Expense`)
  pueden crear un `Reminder` ligado via `remindable_type`/`remindable_id`.
  Los 4 hooks ya están portados:
  - `Purchase`: `payment_reminder_*` opcional en `POST /v1/purchases`
    (solo si `is_credit=true` y trae `payment_reminder_date`) -
    `PurchaseController::store()`. Al saldarse por completo, `PurchaseService::pay()`
    borra el recordatorio pendiente (igual que legacy).
  - `Supplier`: `POST /v1/suppliers/{supplier}/remind-visit`, toggle
    inexistente a propósito (a diferencia de `FixedExpenseTemplate`, legacy
    no lo hace toggle acá) - simplemente crea el recordatorio de visita.
    `SupplierResource`/`index()` exponen `has_pending_visit_reminder` (igual
    que legacy eager-carga `reminders` pendientes en su listado).
  - `Expense`: `reminder_*` opcional en `POST /v1/expenses` -
    `ExpenseController::store()`.
  - `FixedExpenseTemplate`: ya migrado antes (`toggleReminder()`).
  De paso, `StoreExpenseRequest.linkable_type` ahora admite `Ingredient::class`
  además de `Product::class` (el docblock decía "Ingredient no soportado
  aún", ya no es cierto desde que el módulo de Ingredientes se migró).
