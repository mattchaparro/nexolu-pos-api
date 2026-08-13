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
| `exchange-rate:fetch` | ✅ Migrado | — |

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
- **Abstracción de mensajería (Nexolu Communications, pendiente de crear)**:
  todo el envío saliente y el costeo de WhatsApp ahora pasan por dos
  interfaces nuevas en `App\Services\Messaging\Contracts`:
  `MessagingChannel` (`sendText`/`sendTemplate`/`sendFlow`/`markAsReadWithTyping`/`isConfigured`)
  y `MessagingCostReporter` (`costUsdForPeriod`). `WhatsAppCloudClient`
  implementa `MessagingChannel` sin cambiar de namespace; `WhatsAppCostReporter`
  (nuevo, extraído de lo que antes era un método privado en
  `PlatformFinanceService`) implementa `MessagingCostReporter` sumando
  `whatsapp_usage_daily`. El único lugar que sabe que el proveedor de hoy es
  WhatsApp es el binding en `AppServiceProvider::register()` - todos los
  jobs/comandos (`ProcessWhatsAppInbound`, `ProcessWhatsAppFlowReply`,
  `RemindersSendWhatsAppNotifications`, `SendDailyWhatsAppSummary`,
  `InventorySendLowStockAlerts`, `WhatsAppOtpSender`) y `PlatformFinanceService`
  dependen de las interfaces, no de las clases concretas de WhatsApp. Cuando
  exista Nexolu Communications (servicio externo, mismo patrón que Nexolu
  Payments Core / Nexolu IA Core), migrar el envío real es: escribir una
  clase nueva que implemente cada interfaz y cambiar esos dos bindings - cero
  cambios en los consumidores. A propósito **no** se abstrajo el lado
  entrante (`WhatsappWebhookController`, parseo de `nfm_reply`/`list_reply`)
  ni la resolución de identidad/destinatarios (`IdentityResolver`,
  `WhatsAppRecipients`, `AiChannelIdentity`): el formato de webhook es propio
  de cada proveedor y prematuro de abstraer sin conocer el del reemplazo, y
  vincular identidad es dato propio de Nexolú, no lógica de envío. La clave
  `whatsapp_cop` del resumen de Finance (`PlatformFinanceService::monthlySummary()`)
  pasó a `messaging_cop` + `messaging_cost_available` (mismo patrón que
  `ai_cop`/`ai_cost_available`), aunque hoy siempre está disponible porque la
  única implementación es local.

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
- ~~**`exchange-rate:fetch` + gastos reales en Finance**~~ ✅ Migrado. Estaba
  "sin decidir" porque cuando se construyó el dashboard de Finance de
  SuperAdmin (más arriba en este documento) esta API todavía no tenía ni
  costo de IA ni de WhatsApp que valorar - ya no es cierto, así que se cerró
  del todo, no solo el job:
  - `App\Models\ExchangeRate` + `App\Services\ExchangeRateService` +
    `exchange-rate:fetch` (`dailyAt('06:00')`, mismo horario que legacy):
    TRM oficial (`datos.gov.co`) con respaldo de mercado
    (`open.er-api.com`), igual que legacy. La tabla `exchange_rates` ya
    existía en el schema compartido (legacy también le escribe) - sin
    migración, solo el modelo.
  - `App\Services\AiPlatformUsageService`: cliente nuevo hacia
    `GET /v1/platform/usage` del IA Core - a diferencia de todo lo demás que
    habla con el Core, este usa la API key de **plataforma**
    (`IA_CORE_PLATFORM_API_KEY` / `NEXOLU_PLATFORM_API_KEY` del lado del
    Core), no la simétrica de `/v1/chat`, porque ese endpoint ve el gasto de
    TODAS las apps del Core. Nunca lanza: sin credencial o si el Core no
    responde, el costo de IA queda `null` (no `$0`), y
    `PlatformFinanceService` lo excluye del total marcando
    `ai_cost_available: false` en vez de mostrar un margen artificialmente
    mejor por una falla de red.
  - `App\Services\SuperAdmin\PlatformFinanceService::monthlySummary()`
    ahora sí calcula gastos y margen, como legacy: servidor + dominio
    (`SystemConfigStore`, ya existía), WhatsApp (`WhatsAppUsageDaily`, ya
    existía) e IA (nuevo, arriba), todo convertido con la TRM del día real
    (con fallback a `finance.usd_to_cop_rate` de `system_configs`, default
    4000). **A diferencia de legacy, sin comisión de Wompi**: esta API ya no
    cobra suscripciones vía Wompi directo (Nexolu Payments Core), así que
    esa comisión no es un gasto que esta plataforma pague - decisión ya
    tomada antes, no una omisión nueva.

## Módulos de legacy que todavía NO se han empezado a migrar

Encontrados el 2026-08-06 al comparar modelos/controladores de `pos-saas-legacy`
contra este repo (no habían salido antes porque hasta ahora se venía trabajando
módulo por módulo desde los jobs programados, no desde un inventario completo).
Todos tienen datos ya presentes en `schema.sql` (compartido), así que no hace
falta migración, solo el código.

- **Clientes frecuentes (`customers`)**: tabla `customers` (`visits_count`,
  `total_spent`, `last_sale_at`) ya existe en el schema, distinta de `clients`
  (el directorio CRM que sí se migró). En legacy, `SaleService::syncCustomerProfile()`
  la alimenta automáticamente desde `sale.customer_name/phone/identification`
  cada vez que una venta cierra - acá esos 3 campos ya se guardan en `sales`
  (`app/Models/Sale.php`), pero nunca se agregan a un perfil de cliente
  recurrente. No hay modelo `Customer`, ni hook en `SaleService`, ni endpoint.
- ~~**Kitchen Board (comandas de cocina)**~~ ✅ Migrado. `SaleItem` ganó
  `kitchen_status`/`kitchen_updated_at` en fillable/casts (ya existían en
  `Sale`, sin usar - el propio docblock del modelo decía "modulo aparte que
  todavia no existe en esta API", ya no es cierto). `App\Services\KitchenBoardService`:
  `openTickets()` (ventas `status=open` con al menos un item, mismo query
  que legacy) + `updateStatus()` (actualiza uno, varios o todos los items de
  una venta y recalcula el rollup de la venta como el "menos avanzado" de
  sus items - si algo sigue pendiente, la comanda completa se ve pendiente
  aunque otro item ya este listo). API: `GET /v1/kitchen/tickets`,
  `POST /v1/kitchen/tickets/{sale}/status`. Gateado solo por
  `feature:kitchen_board`, **sin** `permission:` - a proposito, igual que
  legacy (accesible por igual a admin y a cualquier empleado, no es una
  accion administrativa sensible; incluso legacy comparte el mismo metodo
  `updateStatus` entre sus namespaces Admin y Employee).
- ~~**Contabilidad gerencial (cierre de mes)**~~ ✅ Migrado. `AccountingPeriodClosing`
  (tabla ya existía en el schema, sin usar) + `App\Services\ManagerialAccountingService`
  (`monthlyReport()`/`monthlyLines()`/`annualReport()`/`closeMonth()`, mismo
  cálculo que legacy: ingresos = ventas directas cerradas no-credito
  no-non-revenue + fiados cobrados + pagos de servicios; gastos = suma de
  `expenses.value`; utilidad por producto usa `unit_cost_at_sale` congelado
  al vender, no el costo actual, para que un mes ya cerrado no cambie solo
  cuando entra una compra nueva - productos con costo $0 en el periodo van
  aparte en `uncosted` en vez de mostrar un margen falso del 100%). No
  confundir con `PlatformFinanceService` (esa es la plata de Nexolú como
  plataforma SaaS; esta es la contabilidad del negocio cliente).
  API: `GET /v1/accounting/monthly`, `GET /v1/accounting/annual`,
  `GET /v1/accounting/closings`, `POST /v1/accounting/close-month`,
  `GET /v1/accounting/monthly/export` (CSV, mismo patron de
  `response()->streamDownload()` que `AuditLogController::export()` - sin
  PDF: legacy lo generaba con una libreria propia que este repo no tiene
  como dependencia, y agregar una nueva dependencia necesita aprobación).
  Gateada por `feature:managerial_accounting` (la clave ya existía en
  `BusinessFeaturePresets`, sin usar hasta ahora) + el permiso nuevo
  `accounting.manage` (categoria "finanzas", `warning: true`): a diferencia
  de legacy, que lo restringía a `role:admin` sin excepción, acá el admin
  puede delegarlo a un empleado de confianza si quiere - el admin sigue
  pasando siempre por rol.
  **Pendiente para cuando se mejoren los reportes en general** (a proposito
  fuera de esta pasada, ver items de abajo): esta pasada no toca
  `Admin/ReportsController`/`InventoryReportsController`/`SupplierReportsController`.
- **Reportes de ventas/inventario/proveedores**: 3 controladores grandes de
  legacy sin equivalente:
  - `Admin/ReportsController` (516 líneas): resumen diario, historial de
    ventas, ventas por vendedor + exports CSV/PDF. Las capabilities de IA ya
    cubren un subconjunto (`sales summary/by-day`), pero no hay endpoint de
    reporte dedicado ni exports.
  - `Admin/InventoryReportsController` (486 líneas): márgenes por producto,
    valorización de inventario + exports.
  - `Admin/SupplierReportsController`: historial de compras filtrable por
    proveedor/producto con totales - los datos crudos ya están en
    `GET /v1/purchases`, pero no el endpoint de reporte agregado.
- ~~**Silenciar alertas de inventario bajo (`NotificationSnoozeController`)**~~
  ✅ Migrado. `GET /notifications/low-stock/{business}/snooze?days=N` (fuera
  del prefijo `/v1`, público, sin login - la firma de la URL, middleware
  `signed`, es la única autenticación, igual que legacy). **Simplificación
  sobre legacy**: legacy mostraba un formulario (`GET`, elegir días) y
  aplicaba el cambio en un segundo paso (`POST`); acá el correo ya trae un
  link firmado por cada opción de días (3/7/15/30) - un solo clic, sin
  formulario intermedio (un cliente de correo no puede disparar un `POST`
  desde un link de todos modos, así que el paso intermedio no aportaba
  nada). `LowStockAlertMail` arma los 4 links con `URL::signedRoute()`;
  `InventorySendLowStockAlerts`/`LowStockAlertReport` no cambiaron.
- **Login social (Google OAuth)**: `SocialController` (`auth/google`,
  `auth/google/callback`) no tiene equivalente - hoy `POST /v1/login` es
  únicamente usuario/contraseña vía Sanctum.
- ~~**Ajustes de IA a nivel superadmin**~~ ✅ Parcialmente migrado - alcance
  redefinido tras revisar legacy de cerca. `SuperAdmin/AiSettingsController`
  de legacy (cupo mensual incluido, tamaño/precio del paquete adicional) es
  config del sistema de addon-por-suscripción, que ya está fuera de alcance
  de esta migración (ver "Addon de IA vía suscripción" más abajo - el propio
  legacy nunca cobra por ahí, `ai_addon_included` siempre es `false`) - no
  se portó, sería configurar un cobro que no existe.
  Lo que sí se portó, y es genuinamente útil independiente del addon:
  **`ai_chat_blocked`** como interruptor de emergencia por negocio (abuso,
  soporte, etc.). En legacy este campo existe pero **nunca tenía un botón
  real para activarlo** - solo era un efecto secundario de
  `AiAccessService` (sistema de addon, dead code) al abrir una prueba
  gratis; ni el Asistente de esta API lo revisaba en ningún punto de
  entrada. Ahora sí es una acción deliberada: `PATCH
  /v1/superadmin/businesses/{business}/ai-chat-block` (mismo patrón que
  `toggle` de `active`) + enforcement centralizado en
  `App\Support\AiTenantContext::forUser()` (el único choke point de los 5
  puntos de entrada al Asistente: chat web, insights, drafts, y los 2 jobs
  de WhatsApp) via `App\Exceptions\AiChatBlockedException` - los 3
  controllers HTTP la atrapan y devuelven 403; los jobs de WhatsApp ya
  atrapaban `RuntimeException` genérico y responden el mensaje por el mismo
  canal sin necesitar cambios.
  **Sigue sin portar** (`SuperAdmin/AiUsageController`, uso de IA detallado
  por negocio): requeriría que `nexolu-ia-core` exponga un desglose por
  negocio en su API de uso, no solo por app - `AiPlatformUsageService`
  actual solo trae el total de la app `pos` completa. Es un cambio de
  alcance mayor (repo aparte) que queda pendiente de decidir si vale la
  pena, ahora que el costeo real vive en el Core.
- ~~**Log de corridas de jobs programados (`CronJobLog`)**~~ ✅ Migrado, con
  una mejora arquitectónica sobre legacy: en vez de llamar
  `CronJobLog::record()` a mano dentro de cada uno de los 11 comandos (como
  hace legacy, con un try/catch y un mensaje armado a la medida repetidos en
  cada archivo), acá se centraliza por completo en `routes/console.php` via
  `App\Support\CronJobLogger::attachTo()`: engancha el logging a los hooks
  del scheduler de Laravel (`sendOutputTo` + `after`), captura la salida de
  consola real del comando (lo que ya imprime con `$this->info()`/`error()`)
  y registra éxito/error según el exit code - **cero cambios** en los 11
  comandos ya migrados.
  `App\Support\CronJobCatalog` es la fuente única de verdad de la lista de
  jobs (nombre, descripción, horario, comando), igual patrón que
  `PermissionCatalog`; también resuelve el enable/disable por job
  (`SystemConfigStore`, misma clave `cron.{key}.enabled` que legacy) que se
  aplica con `->when()` en el scheduler.
  API: `GET /v1/superadmin/cron-jobs` (cada job con su última corrida e
  historial de las últimas 10), `PATCH .../{key}/toggle`,
  `POST .../{key}/run-now` (dispara el comando fuera de su horario,
  logueado como `triggered_by=manual` - `Artisan::call()` no pasa por los
  hooks del scheduler, así que este camino llama `CronJobLogger::record()`
  directo). Sin permiso adicional: ya vive bajo `/superadmin`, gateado por
  el middleware `superadmin` completo.
  **Bug real de legacy corregido al portar, no solo trasladado**: la poda a
  las últimas 50 filas por job usaba `->skip(50)` sin `->limit()` - MySQL
  exige `LIMIT` junto con `OFFSET`, así que esa consulta era un error de
  sintaxis silencioso (atrapado por el mismo try/catch que protege el
  logging) que **nunca borraba nada** en legacy tampoco. Acá se resolvió
  con `Collection::slice()` en vez de `skip()`/`offset()` en la query.
  Tampoco se repitió el uso de `'warning'` como status: la columna
  `status` es un enum de solo `success`/`error` en el schema compartido,
  y `FetchExchangeRate.php` de legacy le manda `'warning'` - un valor de
  enum inválido que en producción (sin `STRICT_TRANS_TABLES`) se trunca en
  silencio a `''`, la misma familia de bug que las 26 filas de
  `stock_movements` con `type=''` documentadas en `CUTOVER_TODO.md`.
  `queue:work`/`database:backup` de legacy no tienen entrada en el catálogo
  (infraestructura de su hosting propio, ya excluida más arriba en este
  documento).
- **Preguntas sin responder del chat de IA (`AiUnansweredQuestion`)**: en
  legacy, `AiChatService` registra las preguntas que el modelo no pudo
  responder. Con el chat viviendo en `nexolu-ia-core`, esto probablemente
  debería migrar allá en vez de acá - falta confirmar si el IA Core ya tiene
  algo parecido antes de decidir.

**Revisado y confirmado que NO son gaps** (ya cubiertos por otro lado o
código muerto):

- ~~`Admin/InvoiceController`/`LayawayInvoiceController`/`ServiceOrderInvoiceController`~~
  ✅ Migrado: `ReceiptPdfService` + `SendsReceipts` (Sale/ServiceOrder/Layaway
  reciben `GET .../receipt` y `POST .../receipt/send`), con
  `ReceiptActionsModal.vue` en el frontend. Ver la sección "Paridad de
  módulos ya migrados" más abajo para el detalle completo de qué se cubrió.
- `Payment`/`PaymentLink`/`PaymentMethod`/`PaymentProvider`/`WompiWebhookController`:
  reemplazados por Nexolu Payments Core (ya migrado).
- `AiConversation`/`AiDraft`/`AiMessage`/`AiUsageDaily`: viven en Nexolu IA
  Core, no en el POS.
- `Role` (modelo propio con pivot `role_user`): reemplazado por Spatie
  Permission (ya instalado desde el inicio de esta migración).
- `SaasLead`: sin ningún uso en controllers/services del propio legacy -
  código muerto ahí también, no vale la pena portarlo.

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

## Paridad de módulos ya migrados — auditoría de frontend (2026-08-12)

A diferencia del resto de este documento (módulos/jobs enteros sin
empezar), esto es una auditoría de **funcionalidad faltante dentro de
módulos que ya están migrados y en uso** - comparación módulo por módulo
contra `pos-saas-legacy`. Cada ítem indica qué repo(s) toca
(`nexolu-pos-api` backend, `nexolu-pos-front` frontend, o ambos).

**Ya resueltos en esta ronda** (ambos eran los gaps más grandes encontrados):

- ~~**Recibos/comprobantes en Vender, Cuentas abiertas, Órdenes de
  servicio, Apartados**~~ ✅ Ninguno de los 4 flujos de venta tenía forma de
  entregarle un comprobante al cliente. Ver `ReceiptPdfService`,
  `SendReceiptJob`, `SendsReceipts`, `ReceiptActionsModal.vue` - imprimir
  (PDF con dompdf, respeta `ticket_paper_width`) + enviar por WhatsApp
  (`MessagingChannel::sendDocument()`, nuevo) o correo (`ReceiptMail`).
- ~~**Pantalla de gestión de Clientes**~~ ✅ El backend (`ClientController`)
  ya tenía el CRUD completo; solo faltaba `ClientsView.vue`/
  `ClientFormModal.vue` en el frontend, más un item real en el menú
  (legacy nunca tuvo uno tampoco - el módulo era inalcanzable ahí también).

**Pendientes, por módulo** (repo entre paréntesis):

### Vender (front)
- Dictado de pedido por voz (`Components/POS/DictadoPedido.vue` en
  legacy, 276 líneas) - explícitamente fuera de alcance hasta ahora
  (ver comentario en `SellView.vue`). Prioridad baja.

### Catálogo (api + front)
- **Duplicar producto**: `Admin\ProductsController::duplicate()` en legacy
  (`routes/admin.php:94`) no tiene equivalente en `ProductController`.
- **Exportar catálogo a PDF**: `ProductsController::catalogPdf()` en legacy
  (`routes/admin.php:69`). Con dompdf ya instalado (ver receipts arriba),
  esto es mucho más chico de lo que hubiera sido antes.

### Órdenes de servicio (api + front)
- **Eliminar orden**: `ServiceOrdersController::destroy()` en legacy
  (`routes/admin.php:90`) no existe en `ServiceOrderController`; el show
  nuevo solo ofrece Abonar/Editar/Cancelar.
- **Filtro por etapa del workflow en el listado**: legacy filtra por
  `stage_id` y devuelve `workflowStages` para los chips
  (`ServiceOrdersController.php:31-32,57`); el índice nuevo solo acepta
  `status`/`search`.
- **Card de saldo pendiente agregado**: legacy suma el saldo de todas las
  órdenes pendientes/parciales (`ServiceOrdersController.php:51-53`); el
  listado nuevo solo muestra el saldo por fila. Menor.

### Agenda (api + front)
- **Vista por día**: legacy tiene toggle Día/Semana
  (`Pages/Admin/Appointments/Index.vue:582-603`); `AgendaView.vue` solo
  maneja semana. En agendas densas la vista semanal no alcanza.
- **Eliminar cita**: `AppointmentsController::destroy()` en legacy
  (`:306`) no existe en `AppointmentController` ni en
  `useAppointmentMutations.ts`.
- ~~**"Cobrar cita" sin orden previa**~~ ✅ Confirmado que NO es un gap
  (2026-08-13): en legacy una cita se podía agendar sin decidir el
  servicio, y `chargeAppointment()` (`AppointmentsController.php:313-360`)
  existía como acción aparte para crear la orden + abono inicial en el
  momento de cobrar. Acá esa arquitectura ya se cerró de raíz en vez de
  portarse: `StoreAppointmentRequest` exige `services` (`required|min:1`),
  así que toda cita nueva ya trae al menos un servicio, y
  `AppointmentService::create()` ya crea la `ServiceOrder` vinculada (con
  abono inicial opcional) en el mismo paso de agendar - nunca existe una
  cita sin orden en la práctica, así que no hace falta un camino
  "cita sin orden → cobrar" (`ServiceOrderService::create()` ya es el
  único punto de entrada que arma la orden, ver su docblock). "Abonar"
  sobre la orden ya vinculada (`AppointmentDetailModal.vue`) cubre el
  resto.
- Verificar que el modal de pago de la agenda excluya fiado/crédito de
  los medios de pago ofrecidos, como hace legacy
  (`AppointmentsController.php:48-52`). Menor, sin confirmar si ya aplica.

### Apartados (api)
- ~~`layaway_allowed_category_ids` no se aplica~~ ✅ Migrado
  (`ProductController::index()` con `for_layaway=1`, puerto de
  `LayawaysController.php:41-55,97-108`: excluye servicios, inactivos, sin
  stock, y respeta la lista de categorías permitidas del negocio;
  `include_ids` trae de vuelta un producto ya apartado aunque se haya
  agotado, igual que el `whereIn($currentProductIds)` de `show()` en
  legacy).

### Dashboard (api + front)
- **Desglose efectivo/transferencia del día**: legacy calcula
  `today_cash`/`today_transfer` repartiendo por medio de pago, incluyendo
  fiados cobrados (`DashboardController.php:44-56,71-72`);
  `DashboardService` nuevo no los devuelve.
- **Atajos configurables por usuario**: `userShortcuts` +
  `DashboardController::updateShortcuts()` (`:88,152`) no están portados
  (`DashboardView.vue` solo tiene 5 stat cards + consejo del día).
  Prioridad baja.
- **Card de onboarding de WhatsApp**: `whatsappOnboarding()` +
  `dismissWhatsappOnboarding()` (`:107-152`) - sin ella, nadie descubre
  desde el dashboard que debe vincular el canal de WhatsApp.

### SuperAdmin (api + front)
- **Salir de impersonación del lado del servidor**: legacy tiene
  `ImpersonateController::stop()`/`switchUser()` (`:42,70`); el nuevo solo
  expone `start()`. El front hoy resuelve esto solo con el token guardado
  en el store, sin un endpoint de cierre limpio.
- **Acciones de suscripción en la UI**: el backend ya tiene `activate`/
  `extendTrial`/`setCustomPrice`/`changePlan` (`BusinessesController.php:225-262`),
  pero `SuperAdminBusinessesView.vue` los omite a propósito (ver comentario
  en el archivo) - hoy no se puede activar un pago ni extender un trial
  desde el panel nuevo. Solo falta el front.
- **Comunicaciones por negocio**: `communications()`/`previewEmail()`/
  `sendEmail()` de legacy (`BusinessesController.php:606,648,688`) sin
  equivalente - el `EmailController` nuevo solo hace logs/plantillas
  globales, no envío dirigido por negocio.
- **Limpiar caché del negocio / addon de IA**: `flushCache()` (`:357`) y
  `setAiAddon()` (`:541`) sin portar (`toggleAiChatBlock` es otra cosa).
- **Filtros de estado del listado de negocios**: legacy filtra por
  `trial|paid|inactive|expired|winback` (`:37-54`);
  `SuperAdminBusinessesView.vue` solo tiene buscador de texto.
- **Gestión de equipo desde SuperAdmin**: el backend ya tiene
  `SuperAdmin\UserController::store/toggle/resetPassword`, pero la
  pestaña "Equipo" de `SuperAdminBusinessShowView.vue` es solo lectura +
  impersonar - falta conectar lo que ya existe del lado API.

### Auth (api + front)
- ~~**Recuperar contraseña**~~ ✅ Migrado: `POST /v1/forgot-password` +
  `POST /v1/reset-password` (`AuthController`), tabla compartida
  `password_resets` (legacy también la usa, con esa migración vieja de
  Laravel - config/auth.php apuntaba al default `password_reset_tokens`
  del skeleton, que no existe en el schema). `ResetPasswordMail` (patrón
  Mailable ya establecido en esta API, no `Notification` como legacy) con
  link al frontend separado (`config('app.frontend_url')`, nueva env
  `FRONTEND_URL` - a diferencia del legacy, monolito Blade/Inertia donde el
  link apuntaba a su propia ruta web). **Mejora sobre legacy**: la
  respuesta de `forgot-password` es siempre el mismo mensaje genérico,
  exista o no ese correo (el broker de contraseñas por defecto de Laravel,
  que legacy también usa sin modificar, responde distinto según el caso,
  permitiendo enumerar qué correos están registrados).
  `ForgotPasswordView.vue`/`ResetPasswordView.vue` + link "¿Olvidaste tu
  contraseña?" en `LoginView.vue`.
  **Confirmado que NO es un gap**: verificación de email
  (`MustVerifyEmail`) - el propio legacy la implementa pero nunca la
  aplica a ninguna ruta real (`web.php` no tiene ni un solo
  `middleware('verified')`; el único uso es en `routes/jetstream.php`,
  scaffolding propio de Jetstream sin conectar al negocio) - mismo patrón
  de "existe pero está muerto" que otros hallazgos ya documentados en este
  archivo (`SaasLead`, `WompiFees`).
- **Pantalla de registro de negocios**: el backend ya soporta `register`
  vía `BusinessRegistrationService`, pero no existe ninguna vista que lo
  consuma - hoy el alta de negocios depende exclusivamente de SuperAdmin.
- **Login con Google**: `SocialController::googleRedirect/googleCallback`
  de legacy (`routes/web.php:44-45`) sin portar. Prioridad baja/opcional.

**Confirmado que NO es un gap** (aparecía como incierto en la auditoría
inicial, ya verificado): el límite de clientes por plan
(`Client::LIMIT_PER_BUSINESS`) sí se aplica - `ClientService::create()`
lo valida antes de crear, igual que `ClientsController::store()` en
legacy.
