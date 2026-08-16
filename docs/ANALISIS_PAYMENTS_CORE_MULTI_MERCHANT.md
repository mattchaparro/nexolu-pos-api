# Análisis: nexolu-pos-api vs. nexolu-payments-core (refactor/multi-merchant-wompi)

**Fecha:** 2026-08-16
**Rama de referencia en `nexolu-payments-core`:** `origin/refactor/multi-merchant-wompi` (commit `d99b7b8`)
**Estado:** Paso 1 — Análisis. Ningún archivo de código fue modificado todavía.

## Resumen

Se comparó el contrato real de `nexolu-payments-core` en `origin/refactor/multi-merchant-wompi` contra la integración actual de `nexolu-pos-api`. Hallazgo principal: **la integración no es superficial** — es bastante completa (cliente HTTP, webhook firmado, tests, flujo `flow=api` con Nequi/PSE/Bancolombia/Fuentes de Pago) y ya apunta exclusivamente a Payments Core (cero Wompi directo). Pero tiene **un defecto de contrato crítico que rompe el flujo de confirmación de pago end-to-end**: sigue generando su propia `reference` y enviándola al Core, en vez de usar la que el Core genera.

---

## 1. Integración actual

`nexolu-pos-api` ya migró completamente de Wompi directo a Payments Core (no hay `WompiService` ni `WompiWebhookController`). Todo pasa por un único cliente HTTP: `PaymentsCoreService`, usado por dos flujos de checkout (suscripción SaaS y paquetes de mensajes IA), un proxy de catálogo de métodos de pago/bancos PSE, un endpoint de "Fuentes de Pago" (tarjetas/Nequi guardados), y un único webhook receptor.

## 2. Archivos afectados

| Área | Archivo |
|---|---|
| Cliente HTTP | `app/Services/PaymentsCoreService.php` |
| Servicios de checkout | `app/Services/SubscriptionService.php`, `app/Services/AiMessagePackCheckoutService.php` |
| Webhook | `app/Http/Controllers/Api/PaymentsCoreWebhookController.php` |
| Controllers | `app/Http/Controllers/Api/V1/SubscriptionController.php`, `AiMessagePackController.php`, `PaymentMethodsController.php`, `BusinessPaymentSourceController.php` |
| Validación | `app/Http/Requests/Api/V1/ChargeSubscriptionCheckoutRequest.php` |
| Modelos | `SubscriptionCheckoutOrder.php`, `AiMessagePackCheckoutOrder.php`, `BusinessPaymentSource.php`, `SaasSubscriptionPayment.php` |
| Config | `config/services.php`, `.env.example` |
| Tests | `tests/Feature/Api/PaymentsCoreWebhookTest.php`, `tests/Feature/Api/V1/SubscriptionChargeTest.php`, `BusinessPaymentSourceTest.php`, `PaymentMethodsControllerTest.php`, `SubscriptionTest.php`, `AiMessagePackTest.php` |
| Docs | `docs/PLAN_METODOS_PAGO_ALTERNOS.md`, `docs/MIGRATION_BACKLOG.md` |

## 3. Contratos actuales (lo que el POS ya asume, correctamente)

- Auth `Authorization: Bearer <api_key>` — coincide con `get_current_integration` del Core.
- Paths `POST /v1/payments/intents`, `GET /v1/payments/transactions/{reference}`, `POST /v1/payments/intents/{reference}/charge`, `GET /v1/payments/payment-methods`, `GET /v1/payments/pse/financial-institutions`, `POST /v1/payments/payment-sources` — todos coinciden.
- Formas de `payment_method` para CARD/NEQUI/PSE/BANCOLOMBIA_TRANSFER coinciden campo a campo con `PaymentMethodIn` del Core (diseñadas en paralelo, ver `docs/PLAN_METODOS_PAGO_ALTERNOS.md`).
- Firma de webhook: HMAC-SHA256 sobre `"{timestamp}.{raw_body}"`, headers `X-Nexolu-Signature`/`X-Nexolu-Timestamp` — **idéntica** a `core/webhooks/signing.py`.
- Eventos manejados: `payment.approved`, `payment.declined`/`payment.error`, `payment.voided` — coincide con `_EVENT_BY_KIND` del Core.
- `config/services.php` usa `PAYMENTS_CORE_API_KEY` / `PAYMENTS_CORE_BASE_URL` / `PAYMENTS_CORE_WEBHOOK_SECRET`, sin ninguna variable `WOMPI_*`.
- Modelo Merchant/Integration: correcto — el POS es una única Integration con una sola API key de plataforma; `business_id` viaja como `metadata`, no como identidad del Merchant. El POS no asume ser el Merchant.

## 4. Contratos incompatibles (rotos por el nuevo Core)

### 🔴 Crítico — reference generada por el POS, no por el Core

- `SubscriptionService::initiateCheckout()` y `AiMessagePackCheckoutService::initiateCheckout()` generan `'NEX-{business_id}-{timestamp}-{random}'` / `'NEXPACK-...'` **antes** de llamar al Core, crean la orden local con `order_key = esa referencia`, y se la mandan al Core en `PaymentsCoreService::createIntent(reference: ...)`.
- El nuevo `PaymentIntentIn` del Core **no tiene campo `reference`** — el Core genera su propia `pay_<uuid>` y la persiste en `Transaction.reference`. El valor que manda el POS se ignora silenciosamente.
- Ninguno de los dos servicios lee `intent['reference']` ni `intent['transaction_id']` de la respuesta del Core.
- Consecuencia: `order_key` (POS, `NEX-...`) y `Transaction.reference` (Core, `pay_...`) **nunca coinciden**. Esto rompe:
  - `checkoutStatus()` → `getTransaction($reference)` con el `NEX-...` local → 404 en el Core siempre.
  - `chargeCheckout()` → `POST /v1/payments/intents/{reference}/charge` con `NEX-...` → 404 "No existe una transaccion pendiente para esa reference" siempre, en flujo `flow=api`.
  - El webhook entrante: el Core manda `reference = pay_...` en el payload; `PaymentsCoreWebhookController` busca `order_key = pay_...` en `subscription_checkout_orders`/`ai_message_pack_checkout_orders` → **nunca encuentra la orden** → el pago jamás se confirma, la suscripción nunca se activa, el paquete de IA nunca se acredita.

Este es exactamente el escenario "Antes/Ahora" descrito en la especificación del proyecto, y hoy rompería el 100% de los pagos si se desplegara contra el Core de `refactor/multi-merchant-wompi`.

### 🟡 Menor — comentarios/documentación desactualizados

- `.env.example`, `PaymentsCoreService.php` y `PaymentsCoreWebhookController.php` referencian `scripts/register_integration.py` como mecanismo de provisioning; ese script **fue eliminado** en la rama nueva (reemplazado por `POST /v1/admin/merchants(/{id}/integrations|/providers/wompi)` con header `X-Payments-Provisioning-Key`).

## 5. Cambios necesarios

1. **Invertir el orden reference/creación de orden**: crear la orden local sin `order_key` definitivo (o con un identificador interno temporal), llamar a `createIntent()` sin mandar `reference`, y recién al recibir la respuesta setear `order_key = intent['reference']` (evaluar agregar columna para `transaction_id` del Core, útil para soporte/debug). Si la llamada al Core falla, seguir borrando la orden huérfana como ya se hace hoy.
2. Quitar el parámetro `reference` de `PaymentsCoreService::createIntent()` — ya no se envía al Core.
3. Actualizar `SubscriptionService`, `AiMessagePackCheckoutService`, sus controllers y form requests en consecuencia.
4. Corregir bug independiente encontrado de paso: `ChargeSubscriptionCheckoutRequest::rules()` no incluye `PAYMENT_SOURCE` en `payment_method.type` ni valida `payment_source_id` — el docblock de `BusinessPaymentSourceController` indica que las Fuentes de Pago guardadas se cobran con `{type: "PAYMENT_SOURCE", payment_source_id}` desde `SubscriptionController::charge()`, pero la validación actual lo rechazaría con 422 antes de llegar al Core.
5. Actualizar comentarios/`.env.example` para reflejar el provisioning por API (`/v1/admin/...` + `PROVISIONING_KEY`) en vez del script eliminado.
6. Actualizar todos los tests que asuman una `reference` enviada por el POS (`SubscriptionChargeTest`, `PaymentsCoreWebhookTest`, los de `initiate()`) para que el `order_key` se derive de la respuesta mockeada del Core.
7. (Opcional, no bloqueante) Decidir si vale la pena que `BusinessPaymentSourceController::destroy()` llame también a algo del Core — hoy el soft-delete local no anula nada del lado del proveedor.

## 6. Riesgos

- **Alto**: si esto no se corrige antes de apuntar al Core de `refactor/multi-merchant-wompi`, ningún pago de suscripción ni de paquete de IA se confirmará jamás vía webhook — negocios pagando y nunca activándose.
- **Medio**: cambiar el momento en que se fija `order_key` toca la lógica de idempotencia del webhook (`lockForUpdate` + `status=pending` sobre `order_key`) — hay que verificar que sigue siendo atómico y que no queda ventana de carrera entre "Core creó la transacción" y "POS persistió el `order_key` real" (en la práctica el Core no puede mandar webhook antes de que la respuesta de `createIntent` haya vuelto, pero conviene confirmarlo explícitamente con un test).
- **Bajo**: `BusinessPaymentSourceController::destroy()` no revoca nada en el proveedor — una fuente "eliminada" en el POS sigue siendo técnicamente cobrable si alguien tuviera su `payment_source_id`. Preexistente, no relacionado al refactor, pero vale mencionarlo.
- **Bajo**: comentarios/documentación desactualizados no rompen nada en runtime, pero confunden a quien mantenga el código.

---

## Próximo paso

Pendiente de decisión: avanzar con el Paso 3 (implementación de los cambios listados en la sección 5, incluyendo tests) o revisar algún punto puntual de este análisis antes de tocar código.
