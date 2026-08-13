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

- ~~**Clientes frecuentes (`customers`)**~~ ✅ Decisión revisada
  (2026-08-13): el usuario pidió explícitamente unificar - "un cliente es un
  cliente, sin importar si le vendo algo, le agendo un servicio o le abro un
  apartado" - así que el análisis de abajo (que recomendaba mantenerlas
  separadas, replicando la decisión del legacy) queda **superado**: se
  mantiene como contexto de por qué legacy las separó, no como la decisión
  final de esta migración. Lo que se construyó en su lugar:
  - `ClientQuickAssociate.vue` (Vender, Cuentas abiertas, Apartados) - busca
    un `Client` existente por nombre/teléfono/email y copia sus datos al
    formulario, o crea un `Client` nuevo desde lo recién tipeado. `clients`
    pasa a ser el único concepto de "cliente" en esta app; no se construye
    `Customer` como tabla/modelo aparte.
  - `GET /clients/search` y `POST /clients` ya no exigen `clients.manage`
    (permiso solo para el directorio completo) - cualquiera que pueda
    vender/agendar/apartar puede buscar o dar de alta un cliente.
  - **Sin `client_id` en `sales`/`layaways`/`receivables` todavía** - esas 3
    tablas ya existen en `schema.sql` y el legacy las sigue leyendo/
    escribiendo, así que agregar la columna requiere coordinar con su
    retiro (regla del proyecto, ver `CLAUDE.md`). Documentado en
    `docs/CUTOVER_TODO.md` (ítem 4) con el `ALTER TABLE` exacto para cuando
    sea seguro. Mientras tanto, `ClientQuickAssociate` ya resuelve el caso
    de uso real (que quede un `Client` correcto) sin necesitar esa columna.
  - `customers` queda sin usarse desde esta API - la tabla sigue en el
    schema compartido (no se puede borrar sola), pero no se construye nada
    nuevo sobre ella.

  <details><summary>Análisis original (superado, contexto histórico)</summary>

  (2026-08-13, a pedido del usuario: "¿`clients` y `customers` no deberían
  ser lo mismo?"):

  **Por qué son dos tablas separadas, sin FK entre sí, y así hay que
  dejarlas.** Confirmado en el schema compartido (`schema.sql:586-599` y
  `:629-646`): `clients` no tiene ninguna columna `customer_id`, `customers`
  no tiene ninguna columna `client_id`, y ninguna clase en legacy importa
  ambos modelos a la vez - no es un vínculo que se nos haya pasado al migrar,
  legacy mismo nunca lo tuvo. Es una decisión deliberada de legacy, no un
  descuido: el propio código lo explica
  (`app/Services/Ai/Insights/ClientesResumenInsight.php:14-18`, comentario
  original) - *"Son dos tablas sin relación directa entre sí -- se cruzan por
  teléfono (más confiable que el nombre) solo cuando ambos lo tienen, nunca
  por coincidencia de nombre, para no inventar un vínculo que no existe."*

  Son conceptos distintos aunque puedan describir a la misma persona:
  - **`clients`**: directorio curado. Una fila se crea *a propósito* -
    alguien del negocio decide "este es un cliente" y lo registra a mano
    (nombre, teléfono, correo, notas). Es lo único que otras tablas
    referencian por FK real: `appointments.client_id` y
    `service_orders.client_id` (`schema.sql:320-340,1717-1740`) - agendar
    una cita o abrir una orden de servicio **requiere** (opcionalmente)
    señalar un `Client` real.
  - **`customers`**: ficha automática, sin curaduría. Se alimenta sola
    desde datos sueltos de texto libre que ya vive en otras tablas -
    `sales.customer_name/customer_phone/customer_identification`
    (`SaleService::syncCustomerProfile()`, ver detalle abajo) - cada vez
    que una venta de mostrador (Vender) se cierra con esos campos
    diligenciados, exista o no un `Client` detrás. `layaways` y
    `receivables` tienen las mismas 2-3 columnas de texto libre
    (`schema.sql:965-966,1401-1402`) pero **no** están conectadas a
    `customers` en legacy (solo `sales` lo está) - si se construye esto
    acá, hay que decidir si se amplía esa cobertura o se porta 1:1 solo
    para Sale.

  **Por qué NO conviene fusionarlas en una tabla** (aunque conceptualmente
  describan a la misma persona): las restricciones de unicidad son
  incompatibles a propósito. `customers` tiene
  `UNIQUE(business_id, phone)` y `UNIQUE(business_id, identification)`
  (dedup automático, tiene sentido para una ficha que se autogenera).
  `clients` no tiene ninguna restricción de unicidad sobre teléfono/correo -
  un negocio puede tener a propósito dos `Client` con el mismo teléfono
  (ej. una familia que comparte número). Fusionar rompería esa libertad del
  directorio curado, y forzaría que cada venta anónima de mostrador con
  teléfono repetido cree/edite un "cliente" real sin que nadie lo haya
  pedido.

  **Cómo legacy los cruza, solo para lectura** (no para escritura, nunca
  agrega una FK): `ClientesResumenInsight::ultimaActividad()` calcula la
  última actividad de un `Client` como el máximo entre su última cita
  completada (`appointments.client_id`) y, **si el `Client` tiene
  teléfono**, la última venta del `Customer` con ese mismo teléfono
  (`customers.phone`) - un join en memoria por teléfono, hecho en el
  momento de leer, nunca guardado. Si algún día se construye esto acá,
  el mismo patrón (bridge de solo-lectura por teléfono, sin FK) es el que
  hay que portar - no una fusión de tablas.

  **Recomendación para cuando se construya**: portar `Customer` como su
  propio modelo/tabla (ya existe en el schema, sin tocar), alimentado
  igual que legacy desde `SaleService`/`SaleService`-equivalente
  (`syncCustomerProfile()`) al cerrar una venta. En el frontend, la lista
  de "clientes frecuentes" puede señalar (sin persistirlo) cuáles ya
  coinciden por teléfono con un `Client` existente, y ofrecer una acción
  explícita tipo "Registrar como cliente" que **cree** un `Client` nuevo
  copiando nombre/teléfono - una promoción manual y deliberada, igual que
  el resto del directorio, nunca un vínculo automático ni una fila
  compartida.

  **Estado**: superado por la decisión de unificar (ver arriba) - este plan
  de portar `Customer` como tabla aparte no se va a ejecutar.

  </details>
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
- ~~**Ajustes de IA a nivel superadmin**~~ ✅ Parcialmente migrado. El
  cupo mensual + paquetes que `SuperAdmin/AiSettingsController` de legacy
  edita (`monthly_included_messages`, `pack_size`, `pack_price_cop`) sí se
  migró como modelo comercial (ver "Addon de IA" más abajo) - lo que falta
  es solo la pantalla de SuperAdmin para editar esas 3 claves en caliente;
  `AiQuotaSettings` ya lee de `SystemConfigStore` con las mismas claves que
  el legacy, lista para esa pantalla el día que se construya.
  Lo que sí se portó desde el principio, y es genuinamente útil
  independiente del cupo: **`ai_chat_blocked`** como interruptor de
  emergencia por negocio (abuso,
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
- ~~**Bug real: Vender no mostraba productos más allá del ítem #200 en
  orden alfabético**~~ ✅ `fetchSellableProducts()` pedía
  `/products?per_page=200` (tope de `ProductController::index`, ordenado
  por `name`) y se quedaba solo con esa página - cualquier negocio con más
  de 200 productos perdía silenciosamente los que caían después en el
  alfabeto (peor caso: los que empiezan por "Z"). Se agregó
  `GET /products/sellable`, respaldado por
  `ProductAvailability::forBusiness()` (fusionada con la clase existente
  `effectiveStock()`, igual que en legacy) - trae el catálogo activo
  completo sin tope, cacheado 10 min por negocio (salteado si el negocio
  tiene el feature `ingredients`, igual que legacy, porque ahí el stock es
  demasiado volátil para cachear). Invalidado al guardar/borrar un
  producto y al registrar un movimiento de stock (que muta `stock` con un
  `increment()` SQL directo, sin disparar el evento `saved` de `Product`).

**Pendientes, por módulo** (repo entre paréntesis):

### Vender (front)
- Dictado de pedido por voz (`Components/POS/DictadoPedido.vue` en
  legacy, 276 líneas) - explícitamente fuera de alcance hasta ahora
  (ver comentario en `SellView.vue`). Prioridad baja.

### Catálogo (api + front)
- ~~**Duplicar producto**~~ ✅ Migrado (2026-08-13): `ProductService::duplicate()`
  + `POST /products/{product}/duplicate`, mismo patrón que
  `Admin\ProductsController::duplicate()` del legacy (sufijo `-COPIAN` libre
  contra `withTrashed()`, stock en 0, receta copiada con las mismas
  cantidades).
- **Exportar catálogo a PDF**: decisión explícita de no migrar - nadie lo
  usaba en el legacy (`ProductsController::catalogPdf()`,
  `routes/admin.php:69`). No construir salvo que el usuario lo pida de
  nuevo.

### Órdenes de servicio (api + front)
- ~~**Eliminar orden**~~ ✅ Migrado (2026-08-13): `ServiceOrderService::delete()`
  + `DELETE /service-orders/{serviceOrder}` - a diferencia del legacy (un
  `delete()` sin más, bug real: un abono ya cobrado desaparecía de los
  libros), acá se reembolsan los pagos primero (mismo patrón de fila
  negativa que `cancel()`) y se cancela la cita vinculada si sigue activa.
- ~~**Filtro por etapa del workflow en el listado**~~ ✅ Migrado
  (2026-08-13): `stage_id` en `GET /service-orders` (puerto directo de
  `ServiceOrdersController.php:31-32`); las etapas para los chips ya se
  obtenían de `GET /service-workflow` (existía desde antes, usado en
  `ServiceOrderShowView.vue`), así que no hizo falta duplicar
  `workflowStages` en la respuesta del índice - `ServiceOrdersView.vue`
  reusa `useServiceWorkflow()` para pintarlos.
- ~~**Card de saldo pendiente agregado**~~ ✅ Migrado (2026-08-13):
  `GET /service-orders/summary` (mismo patrón que
  `ProductController::summary()`) suma `total - amount_paid` de las
  órdenes `pending`/`partial` de todo el negocio, sin importar los filtros
  activos del listado - igual que `$pendingBalance` en
  `ServiceOrdersController.php:51-53` del legacy.

### Agenda (api + front)
- ~~**Vista por día/mes**~~ ✅ Migrado y ampliado (2026-08-13): legacy
  tiene toggle Día/Semana (`Pages/Admin/Appointments/Index.vue:582-603`);
  `AgendaView.vue` ahora tiene Día/Semana/**Mes** (Mes es nuevo, sin
  equivalente en legacy - pedido explícito). `WeekCalendar.vue` se
  generalizó a `TimeGridCalendar.vue` (recibe `days: Date[]` en vez de
  `weekStart`, así Día y Semana comparten el mismo posicionamiento por
  hora); `MonthCalendar.vue` es la grilla de mes nueva. De paso se
  corrigió un bug real en `AppointmentController::index()`: el filtro
  `to` comparaba contra la medianoche del día (`Request::date()`), así
  que cualquier cita de esa tarde/noche quedaba fuera del rango - no se
  notaba con semana/día porque el `to` casi nunca caía en un día con
  citas de la tarde, pero con Mes (rango de varios días, cada límite
  diario mal cortado) sí. También se agregó `per_page` a ese mismo
  endpoint (tope 200, mismo patrón que `ProductController`) - el
  `paginate(50)` fijo se quedaba corto para el rango de un mes completo.
- ~~**Eliminar cita**~~ ✅ Migrado: `DELETE /v1/appointments/{id}`, puerto
  directo de `AppointmentsController::destroy()` de legacy (sin guardas
  extra). `Appointment` usa `SoftDeletes`, así que es un soft-delete - una
  orden de servicio vinculada (si la hay) no se toca, sigue con sus pagos
  intactos, solo la cita desaparece del calendario. Botón "Eliminar" en
  `AppointmentDetailModal.vue`, distinto de "Cancelar" (que además
  reembolsa la orden).
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
- ~~Verificar que el modal de pago de la agenda excluya fiado/crédito de
  los medios de pago ofrecidos~~ ✅ Confirmado que NO es un gap
  (2026-08-13): `AppointmentDetailModal.vue` reusa
  `PayServiceOrderModal.vue` tal cual (el mismo modal de "Abonar" de
  Órdenes de servicio, no uno propio de Agenda), que ya filtra con
  `nonCreditPaymentMethods` (`isCreditPaymentMethodId`) antes de pintar
  `PaymentMethodPicker` - mismo comportamiento que
  `AppointmentsController.php:48-52` del legacy.
- **Confirmación al agendar + recordatorio 2h antes por WhatsApp** ✅
  Construido (2026-08-13, pedido explícito - sin equivalente en legacy,
  que solo mandaba un recordatorio por correo el día anterior via
  `AppointmentsSendReminders`): `AppointmentWhatsappNotifier` +
  `SendAppointmentConfirmationJob` (se dispara al agendar, si hay
  `client_phone`) + el comando nuevo `appointments:send-two-hour-reminders`
  (cada 5 min, mismo patrón que `reminders:send-whatsapp-notifications`).
  Sin columna propia para trackear el envío del recordatorio
  (`appointments` es tabla compartida con el monolito, no se le puede
  agregar una - ver `CLAUDE.md`; y en toda esta migración nunca se ha
  usado `database/migrations/`, las 85 tablas ya vienen pre-provistas en
  `schema.sql`): se reutiliza el modelo `Reminder` polimórfico (mismo
  patrón que los 4 hooks de Compra/Proveedor/Gasto/Plantilla de gasto
  fijo) con `notify_whatsapp=false` a propósito, para que
  `reminders:send-whatsapp-notifications` (que le avisa al STAFF via su
  `AiChannelIdentity`) la ignore por completo - el comando nuevo es el
  único que la procesa, y le escribe al teléfono del cliente, no al
  creador. `ReminderController::index()` excluye estos reminders del
  Planificador (son un detalle de implementación del sistema, no una
  tarea del staff). Cancelar/reprogramar/eliminar una cita
  actualiza/borra su `Reminder` pendiente en `AppointmentService`.
  **Sin plantillas de WhatsApp aprobadas todavía en Meta**:
  `WHATSAPP_TEMPLATE_CITA_CONFIRMACION`/`WHATSAPP_TEMPLATE_CITA_RECORDATORIO`
  (`.env`) quedan vacías por defecto - mismo patrón tolerante que
  `WHATSAPP_FLOW_GASTO_ID` - hasta que alguien cree y apruebe las
  plantillas reales en Meta, nada se envía.

### Apartados (api)
- ~~`layaway_allowed_category_ids` no se aplica~~ ✅ Migrado
  (`ProductController::index()` con `for_layaway=1`, puerto de
  `LayawaysController.php:41-55,97-108`: excluye servicios, inactivos, sin
  stock, y respeta la lista de categorías permitidas del negocio;
  `include_ids` trae de vuelta un producto ya apartado aunque se haya
  agotado, igual que el `whereIn($currentProductIds)` de `show()` en
  legacy).

### Dashboard (api + front)
- ~~**Desglose efectivo/transferencia del día**~~ ✅ Migrado (2026-08-13):
  `today_cash`/`today_transfer` en `DashboardService::todaySummary()`,
  mismo cálculo que `DashboardController.php:44-56` del legacy -
  `Sale::allocatedRevenueByPaymentMethod()` (ya existía, compartida con
  cierre de caja) por cada venta cerrada + `payment_method` de los fiados
  cobrados hoy. Solo efectivo/transferencia se desglosan, igual que
  legacy (el resto de métodos no se abren en cards aparte).
- **Atajos configurables por usuario**: `userShortcuts` +
  `DashboardController::updateShortcuts()` (`:88,152`) no están portados
  (`DashboardView.vue` solo tiene stat cards + consejo del día).
  Prioridad baja.
- ~~**Card de onboarding de WhatsApp**~~ ✅ Migrado con alcance reducido
  (2026-08-13): `GET /dashboard/whatsapp-onboarding` +
  `POST .../dismiss` (mismas condiciones que
  `DashboardController::whatsappOnboarding()` del legacy: null si el
  usuario ya lo cerró, no tiene `ai_chat.use`, o el negocio tiene
  `ai_chat_blocked`) + `WhatsappOnboardingCard.vue`. Dos diferencias
  deliberadas con legacy:
  - El checklist de "alertas activas" (`notification_preferences`) no se
    portó - esta API todavía no tiene una pantalla de Ajustes donde
    configurarlas (no existe en ningún lado del frontend, ver
    `BusinessSettingsView.vue`), así que mostrar un check que nadie puede
    prender confundiría más de lo que ayuda. Cuando exista esa pantalla,
    agregar el checklist completo es trabajo aparte.
  - Legacy enlaza a Ajustes > IA para vincular; acá no existe esa
    pantalla tampoco, así que el flujo de vínculo (teléfono → código)
    vive inline en el card mismo, reusando
    `POST /ai/channels/whatsapp/start|confirm` (ya existían, sin ningún
    frontend que los consumiera hasta ahora - la vinculación en sí era un
    gap más grande de lo que el ítem original del backlog sugería).

### SuperAdmin (api + front)
- ~~**Salir de impersonación del lado del servidor**~~ ✅ Resuelto
  (2026-08-13): no hace falta un endpoint `stop` dedicado - el front ya
  salía llamando `POST /logout` con el token de impersonación como bearer
  (`AuthStore::stopImpersonating`), y eso ya revocaba ese token server-side
  (`AuthController::logout` -> `currentAccessToken()->delete()`). Lo que
  faltaba era la auditoría del cierre: solo quedaba loggeado
  `superadmin.impersonation.started`, nunca el fin de la sesión. `logout()`
  ahora detecta si el token que se está cerrando es de impersonación
  (prefijo `ImpersonateController::TOKEN_NAME_PREFIX`) y loguea
  `superadmin.impersonation.ended` con `superadmin_id`/`impersonated_user_id`/
  `business_id` antes de revocarlo. Ver `ImpersonateTest::test_ending_an_impersonation_session_is_audited`.
- ~~**Toggle de features por negocio en la UI**~~ ✅ Resuelto (2026-08-13):
  el backend (`PATCH .../businesses/{business}/config`) ya existía completo;
  la pestaña "Features" de `SuperAdminBusinessShowView.vue` era de solo
  lectura a propósito (ver el comentario que tenía el archivo). Ahora cada
  fila tiene un `NxSwitch`, con "Guardar cambios"/"Descartar cambios" (una
  sola escritura por lote, no una por click) - ver
  `useBusinessMutations.ts`.
- ~~**Acciones de suscripción en la UI**~~ ✅ Resuelto (2026-08-13): el
  backend ya tenía `activate`/`extendTrial`/`setCustomPrice`/`changePlan`
  (`BusinessesController.php:225-262`); ahora `SuperAdminBusinessShowView.vue`
  tiene un botón "Gestionar suscripción" que abre
  `SubscriptionActionsModal.vue` (selector de acción + el formulario de
  cada una) en vez de 4 componentes separados para 4 formularios chicos.
- ~~**Bitácora de comunicaciones filtrable por canal/negocio**~~ ✅ Resuelto
  (2026-08-13): no existía ningún log granular por-mensaje de WhatsApp
  (solo `whatsapp_usage_daily`, agregado por día/categoría) - se agregó la
  tabla `whatsapp_logs` (nueva, el legacy nunca la toca, aplicada a mano
  via `mysql` CLI contra `pos_saas`/`testing`, documentada en
  `schema.sql` - sin archivo en `database/migrations/`, mismo patrón que
  el resto del proyecto). `App\Services\WhatsApp\LoggingMessagingChannel`
  (decorator, bind real de `MessagingChannel` en `AppServiceProvider`)
  loguea cada envío exitoso/fallido sin importar el proveedor
  (`WhatsAppCloudClient`/`NexoluCommsChannel`) - los ~9 call sites
  (recordatorios, resumen diario, alertas de stock, comprobantes, OTP,
  bienvenida, chat de IA) ahora declaran `business_id`/`type`. Nuevo
  `CommunicationController::index` (`GET /superadmin/communications`) une
  `email_logs` + `whatsapp_logs` con un `UNION ALL` a nivel SQL (pagina y
  ordena sin cargar todo el histórico a memoria), filtrable por
  `channel`/`business_id`. Frontend: pantalla "Comunicaciones" nueva
  (`superadmin-communications`), reemplaza el item de menú "Correos" que
  quedaba deshabilitado.
- ~~**Comunicaciones dirigidas por negocio (enviar, no solo ver)**~~ ✅
  Migrado (2026-08-13), pero NO como puerto de
  `communications()`/`previewEmail()`/`sendEmail()` del legacy
  (`BusinessesController.php:606,648,688`): esos 3 métodos solo mandan 2
  correos de marketing con contenido fijo (`promo_reminder`,
  `special_price`), y lo que se pidió esta vez fue algo más general - que
  el superadmin pueda escribir lo que quiera. `App\Services\BusinessCommunicationService::send()`
  redacta y manda un correo (asunto + cuerpo libres, `App\Mail\BusinessDirectedMail`)
  o un WhatsApp (vía la plantilla genérica ya aprobada 'recordatorio' /
  `general_reminder` - una única variable de texto libre, tope 300
  caracteres, mismo mecanismo que `reminders:send-whatsapp-notifications`)
  a un negocio puntual. Ambos canales quedan automáticamente en la
  bitácora de Comunicaciones (arriba) sin código adicional: el correo vía
  `LogSentEmail` (headers `X-Nexolu-*`), el WhatsApp vía
  `LoggingMessagingChannel`. Modal "Redactar" en
  `SuperAdminCommunicationsView.vue` (nexolu-pos-front).
- **Limpiar caché del negocio**: `flushCache()` de legacy
  (`BusinessesController.php:357`) sin portar.
- ~~**Addon de IA (cupo mensual + compra de paquetes adicionales)**~~ ✅
  Migrado (2026-08-13). El legacy tiene dos modelos comerciales
  superpuestos para el Asistente: uno vestigial (trial gratis, addon pago
  con expiración - `ai_chat_billable`, `ai_chat_addon_expires_at`,
  `ai_chat_trial_*`) que `AiAccessService::estado()` (el único método que
  de verdad gatea el acceso) nunca consulta, y uno activo (cupo mensual
  incluido en el plan + paquetes comprados sin expiración como fallback)
  que sí. Solo el segundo se portó - el primero es código muerto en el
  legacy y no tiene sentido replicarlo aquí. Tampoco existía un flujo de
  compra real en el legacy (`AiMessagePackService::acreditar()` solo se
  llamaba desde tests); el checkout self-serve se diseñó desde cero
  reusando el patrón `SubscriptionCheckoutOrder`/`SubscriptionService`
  (Nexolu Payments Core + webhook firmado) ya construido para "Mi
  suscripción".

  Backend: `App\Support\AiQuotaSettings` (cupo mensual/tamaño y precio de
  paquete, vía `SystemConfigStore` con fallback a `config/ai.php`, mismas
  claves que el legacy `AiSettings` para una futura pantalla de Ajustes de
  IA en SuperAdmin); `App\Models\AiUsageDaily` sobre la tabla ya existente
  `ai_usage_daily`; `App\Services\AiQuotaService::assertAccess()` (plan
  vencido, hooked en `AiTenantContext::forUser()` - los 5 puntos de
  entrada al Asistente quedan cubiertos), `consumeMessage()` (consumo
  atómico cupo→paquetes, nunca al revés, con reserva del 60% para
  empleados - piso de 1 mensaje - via `employeeQuotaShare`) y `state()`
  (para la card de Ajustes); `App\Services\AiMessagePackService::credit()`
  /`consumeOne()` sobre `Business::ai_message_pack_balance` +
  `ai_message_pack_purchases` (auditoría); tabla nueva
  `ai_message_pack_checkout_orders` (sin equivalente en el legacy,
  documentada en `schema.sql`) +
  `App\Services\AiMessagePackCheckoutService`; `PaymentsCoreWebhookController`
  extendido para reconocer ambos tipos de orden (referencia
  `NEX-`/`NEXPACK-`) en `approve()`/`fail()`/`void()`. Nuevas excepciones
  `AiQuotaExceededException`/`AiSubscriptionExpiredException`.

  Frontend: card "Asistente de IA" en `BusinessSettingsView.vue` (barra de
  cupo mensual usado, cupo restante, balance de paquetes, botón "Comprar
  paquete" solo para admin/dueño reusando el widget de Wompi) - módulo
  `src/modules/ai-message-packs/`.

  **Sigue sin portar**: pantalla de SuperAdmin para editar
  `monthly_included_messages`/`pack_size`/`pack_price_cop` en caliente (el
  legacy sí la tiene, `AiSettingsController`/`AiUsage/Settings.vue`) - las
  claves de `SystemConfigStore` ya están listas para eso.
- ~~**Filtros de estado del listado de negocios**~~ ✅ Resuelto
  (2026-08-13): el backend ya soportaba `?status=trial|paid|inactive|expired`
  (`BusinessesController::index`); se agregó el selector en
  `SuperAdminBusinessesView.vue` junto al buscador de texto. `winback` del
  legacy no tiene equivalente (no hay campo que lo derive todavía).
- ~~**Gestión de equipo desde SuperAdmin**~~ ✅ Resuelto (2026-08-13): el
  backend ya tenía `SuperAdmin\UserController::store/toggle/resetPassword`;
  la pestaña "Equipo" de `SuperAdminBusinessShowView.vue` ahora tiene
  "Agregar usuario" (modal nombre/correo/contraseña/rol), activar/desactivar
  por fila, y "Restablecer contraseña" (la muestra una sola vez, con botón
  de copiar - nunca se persiste en claro, igual que el backend).

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
