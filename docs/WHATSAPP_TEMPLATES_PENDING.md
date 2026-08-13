# Plantillas de WhatsApp pendientes de crear/aprobar en Meta

Este archivo lista las plantillas (`whatsapp.templates` en `config/services.php`)
que el código ya sabe usar pero que **todavía no existen o no están aprobadas**
en el WhatsApp Business Manager (Meta). El patrón en todos los casos es
tolerante: sin el nombre real configurado, el envío correspondiente se salta
en silencio (log, sin romper el flujo) - ver cada servicio referenciado abajo.

Cuando se cree/apruebe una plantilla, actualizar la env correspondiente en
producción y tachar el ítem aquí con la fecha.

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
