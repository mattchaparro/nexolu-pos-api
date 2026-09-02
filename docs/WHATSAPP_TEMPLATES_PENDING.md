# Plantillas de WhatsApp pendientes de crear/aprobar en Meta

Este archivo lista las plantillas (`whatsapp.templates` en `config/services.php`)
que el código ya sabe usar pero que **todavía no existen o no están aprobadas**
en el WhatsApp Business Manager (Meta). El patrón en todos los casos es
tolerante: sin el nombre real configurado, el envío correspondiente se salta
en silencio (log, sin romper el flujo) - ver cada servicio referenciado abajo.

Cuando se cree/apruebe una plantilla, tachar el ítem aquí con la fecha. Cómo
se activa depende de cómo esté declarada:

- Las que tienen **env** (las de citas): poner el nombre real en esa variable.
- Las de **pedidos** (§5-§8): el nombre ya está escrito en `config/services.php`
  y lo único que hay que hacer es **borrarles la línea `'pending_approval' => true`**.
  El nombre no va en env porque no es un secreto, no cambia entre ambientes y
  es el mismo para todos los negocios — hay un solo WABA. `pending_approval`
  existe para que una plantilla declarada pero sin aprobar no genere un envío
  fallido contra Meta por cada pedido.

---

## 1. `cita_confirmacion` - confirmación al agendar una cita

- **Env:** `WHATSAPP_TEMPLATE_CITA_CONFIRMACION` (vacía hoy).
- **Dispara:** `AppointmentWhatsappNotifier::sendConfirmation()`, desde
  `SendAppointmentConfirmationJob` al crear una cita con `client_phone`.
- **Variables sugeridas (body):** nombre del cliente, nombre del negocio,
  fecha/hora de la cita (formateada en zona `America/Bogota`), servicio
  agendado (si aplica).
- **Texto sugerido:**
  > Hola {{1}}, tu cita en {{2}} quedó agendada para el {{3}}. Te
  > escribiremos 2 horas antes para recordártela. Si necesitas cambiarla,
  > contáctanos.
- **Estado:** sin crear en Meta.

## 2. `cita_recordatorio` - recordatorio 2 horas antes de la cita

- **Env:** `WHATSAPP_TEMPLATE_CITA_RECORDATORIO` (vacía hoy).
- **Dispara:** `AppointmentWhatsappNotifier::sendReminder()`, desde el comando
  `appointments:send-two-hour-reminders` (cron cada 5 minutos).
- **Variables sugeridas (body):** nombre del cliente, nombre del negocio,
  hora de la cita.
- **Texto sugerido:**
  > Hola {{1}}, te recordamos tu cita en {{2}} hoy a las {{3}}. ¡Te
  > esperamos!
- **Estado:** sin crear en Meta.

## 3. `resumen_diario` - resumen diario del negocio

- **Ya tiene nombre real hardcodeado** en `config/services.php`
  (`daily_business_summary`, `es_CO` - mismo WABA que legacy).
- **Dispara:** `notifications:send-daily-whatsapp-summary` +
  `SmartSummaryInsight`.
- **Estado:** **EN REVISIÓN** en Meta desde 2026-07-23. Confirmar que ya haya
  sido aprobada antes de asumir que los envíos están funcionando en
  producción - mientras siga en revisión, Meta la rechaza y el envío falla
  en silencio (mismo comportamiento que legacy).

## 4. Flow `gasto` - confirmar borrador de gasto desde WhatsApp (Fase 2 del Asistente IA)

- **Env:** `WHATSAPP_FLOW_GASTO_ID` (vacía hoy) - no es una plantilla de
  mensaje sino un WhatsApp Flow publicado en Meta.
- **Dispara:** `ProcessWhatsAppInbound`, al confirmar un borrador de
  `CreateExpenseCapability` sin salir de WhatsApp.
- **Sin el `id` configurado:** cae de vuelta al aviso de texto de siempre
  ("confirmalo desde el POS").
- **Deuda adicional una vez exista el Flow real:** el nombre de pantalla
  (`screen: 'GASTO'`) y los campos que se le mandan (`app/Support/services.php`,
  bloque `whatsapp.flows.gasto`) son el mejor esfuerzo contra el Flow JSON
  real de legacy - hay que verificarlos/ajustarlos contra Meta una vez el
  Flow exista (ver `docs/MIGRATION_BACKLOG.md`, sección Asistente IA /
  WhatsApp).
- **Estado:** sin crear en Meta.

---

## Ya aprobadas (para referencia, no requieren acción)

| Clave | Nombre en Meta | Usada por |
|---|---|---|
| `otp` | `verify_whatsapp_in_business` | Vínculo de WhatsApp (OTP) |
| `welcome` | `welcome_whatsapp_linked` | Bienvenida al vincular WhatsApp |
| `recordatorio` | `general_reminder` | `reminders:send-whatsapp-notifications` |
| `inventario_bajo` | `low_stock_alert` | `inventory:send-low-stock-alerts` |

---

## 5. `pedido_recibido` - al comprador, su pedido llegó a la tienda

- **Config:** `whatsapp.templates.pedido_recibido`, con `pending_approval => true`.
- **Dispara:** `OnlineOrderNotifier::sendReceived()`, al crear un pedido en la
  tienda online. Hoy sale solo el correo.
- **Categoría en Meta:** Utility.
- **Variables (body):** `{{1}}` comprador · `{{2}}` tienda · `{{3}}` número de
  pedido · `{{4}}` total.
- **Texto sugerido:**
  > Hola {{1}}, recibimos tu pedido #{{3}} en {{2}} por {{4}}. Te avisamos
  > apenas la tienda lo confirme.
- **Estado:** sin crear en Meta.

## 6. `pedido_confirmado` - al comprador, la tienda lo confirmó

- **Config:** `whatsapp.templates.pedido_confirmado`, con `pending_approval => true`.
- **Dispara:** `OnlineOrderNotifier::sendConfirmed()`.
- **Categoría en Meta:** Utility.
- **Variables (body):** `{{1}}` comprador · `{{2}}` tienda · `{{3}}` número.
- **Texto sugerido:**
  > {{1}}, {{2}} confirmó tu pedido #{{3}} y ya lo está alistando.
- **Estado:** sin crear en Meta.

## 7. `pedido_enviado` - al comprador, su pedido va en camino

- **Config:** `whatsapp.templates.pedido_enviado`, con `pending_approval => true`.
- **Dispara:** `OnlineOrderNotifier::sendShipped()`.
- **Categoría en Meta:** Utility.
- **Variables (body):** `{{1}}` comprador · `{{2}}` tienda · `{{3}}` número.
- **Texto sugerido:**
  > {{1}}, tu pedido #{{3}} de {{2}} ya va en camino.
- **Estado:** sin crear en Meta.

## 8. `pedido_nuevo_comercio` - al COMERCIANTE, entró un pedido

La más urgente de las cuatro. Hasta ahora al comerciante solo le llegaba
correo, que es justo el canal que no mira quien está atendiendo el mostrador:
un pedido del que se entera tarde es un pedido que se despacha tarde.

- **Config:** `whatsapp.templates.pedido_nuevo_comercio`, con `pending_approval => true`.
- **Dispara:** `OrderService::notifyMerchantOnWhatsApp()`, desde
  `StorefrontOrderController::store` junto al correo (no lo reemplaza: el
  correo deja constancia, WhatsApp es el que se ve).
- **Destino:** `businesses.whatsapp_number`, y si no `businesses.phone`, y si
  no el `cellphone` del dueño.
- **Categoría en Meta:** Utility.
- **Variables (body):** `{{1}}` número de pedido · `{{2}}` total · `{{3}}`
  nombre del comprador.
- **Texto sugerido:**
  > Entró el pedido #{{1}} por {{2}} de {{3}}. Míralo en tu POS para
  > confirmarlo.
- **Estado:** sin crear en Meta.
