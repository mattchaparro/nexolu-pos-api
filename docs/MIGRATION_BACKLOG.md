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
| `inventory:send-low-stock-alerts` | ✅ Migrado (solo correo, solo productos) | Canal WhatsApp: ver abajo. Ingredientes: ver abajo. |
| `reminders:send-whatsapp-notifications` | ❌ Pendiente | Módulo Reminders (modelo `Reminder`, tabla `reminders` ya existe en `schema.sql`) |
| `expenses:register-scheduled` | ❌ Pendiente | Modelo `FixedExpenseTemplate` (tabla `fixed_expense_templates` ya existe en `schema.sql`) |
| `notifications:send-daily-whatsapp-summary` | ❌ Pendiente | Motor de insights (`ResumenInteligenteInsight` en legacy) - no existe equivalente aquí, es un módulo aparte |
| `businesses:send-trial-winback` | ❌ Pendiente | Decisión de mailer/Brevo pendiente (no evaluado a fondo todavía) |
| `businesses:warn-inactive-trial` | ❌ Pendiente | Nada - es portable ya, paralelo al filtro de negocios inactivos de SuperAdmin ya construido |
| `queue:work` / `database:backup` | N/A | Infra de hosting legacy, no aplica a esta API (Cloud/Sail maneja colas y backups distinto) |
| `exchange-rate:fetch` | ❌ Sin decidir | Encaje arquitectónico poco claro - los costos de IA ya se rastrean en USD en el IA Core, no en COP aquí |

## WhatsApp Cloud API - fases pendientes (ver commit de Fase 1)

- **Fase 2 - WhatsApp Flows** (confirmación nativa de borradores `gasto`,
  `entrada_inventario`, `entrada_ingrediente`): requiere primero un proxy en
  esta API hacia `POST /v1/drafts/{id}/confirm` del IA Core (no existe
  todavía - hoy solo existe el proxy de `/v1/chat`). `entrada_ingrediente`
  además depende del módulo Ingredientes (ver abajo).
- **Fase 3 - Notificaciones proactivas por WhatsApp**:
  - Resumen diario (`resumen_diario`): depende del motor de insights.
  - Recordatorios (`recordatorio`): depende del módulo Reminders.
  - Inventario bajo (`inventario_bajo`): depende de `AiChannelIdentity`
    (ya existe, Fase 1) + `WhatsAppRecipients` (falta portar, es trivial) +
    extender `InventorySendLowStockAlerts` con el canal WhatsApp.

## Módulos completos que faltan (no son solo un job)

- **Ingredientes/recetas** (`Ingredient`, `ingredient_product` pivot con
  `Product`): tablas ya existen en `schema.sql`. Bloquea: paridad completa de
  `LowStockAlertReport` (hoy solo mira productos, ver
  `app/Support/LowStockAlertReport.php`), el Flow `entrada_ingrediente`, y
  cualquier negocio que venda con receta (ej. gastronomía).
- **Reminders** (`Reminder`, recurrencia, `remindable` polimórfico): tabla
  `reminders` ya existe en `schema.sql`, incluyendo las columnas de
  `notify_time`/`notify_whatsapp`/`last_notified_on`.
- **FixedExpenseTemplate**: tabla ya existe en `schema.sql`. Modelo simple,
  sin lógica compleja - el job solo crea un `Expense` por plantilla activa
  cuyo `day_of_month` coincide con hoy.
- **Motor de insights** (`ResumenInteligenteInsight` en legacy): calcula
  salud del negocio/ventas vs. ayer/gasto inusual/prioridad del día. No
  evaluado a fondo todavía - es candidato a vivir como una Capability más
  (`App\Capabilities`) reutilizable tanto por el resumen diario de WhatsApp
  como por el chat de IA, en vez de portarlo tal cual de legacy.
