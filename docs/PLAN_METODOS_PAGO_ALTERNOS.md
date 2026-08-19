# Plan: métodos de pago alternos (Nequi, PSE, Botón Bancolombia) via `flow="api"`

Estado (2026-08-15):
- **Fase 1 (`nexolu-payments-core`)**: **implementada y validada**.
  `providers/base.py`/`wompi.py` generalizados a `NEQUI`/`PSE`/
  `BANCOLOMBIA_TRANSFER` (+ `CARD` que ya existia), `ChargeResult.redirect_url`
  con polling acotado, endpoints nuevos `GET /v1/payments/payment-methods` y
  `GET /v1/payments/pse/financial-institutions`. `pytest -v`: 42/42. Validado
  contra Wompi sandbox real con los 4 metodos — un bug real se encontro y
  arregló en el camino: `BANCOLOMBIA_TRANSFER` requiere `user_type: "PERSON"`,
  campo que la documentacion principal de Wompi no mostraba. Detalle completo
  en `docs/APP_INTEGRATION.md` sección 8 de ese repo.
- **Fase 2 (`nexolu-pos-api`, este repo)**: **implementada** —
  `PaymentsCoreService::charge()`/`paymentMethods()`/`pseFinancialInstitutions()`,
  `SubscriptionService::chargeCheckout()` + `flow` en `initiateCheckout()`,
  `ChargeSubscriptionCheckoutRequest`, `SubscriptionController::charge()`,
  `PaymentMethodsController` nuevo, rutas, y tests
  (`tests/Feature/Api/V1/SubscriptionChargeTest.php`,
  `tests/Feature/Api/V1/PaymentMethodsControllerTest.php`). **No verificada
  con `php artisan test`** — esta maquina no tiene PHP/Composer instalados
  ni `vendor/` armado, asi que solo se pudo revisar el codigo a mano
  (releido completo) en vez de ejecutar la suite. Primera tarea para quien
  retome esto con un entorno PHP disponible: correr la suite y confirmar.
  Ahora que la Fase 1 esta lista, el endpoint de cobro SI puede funcionar de
  punta a punta contra el Core real (antes no podia: `charge()` solo
  aceptaba `CARD`, y los dos endpoints de catalogo no existian) — falta
  probarlo de punta a punta con el Core corriendo, no solo con `Http::fake()`.
- **Fase 3 (`nexolu-pos-front`)**: **implementada** (junto con 9.5, ver
  abajo) — `useDirectCheckout` nuevo (tokeniza/cobra flow="api": tarjeta,
  Nequi, fuente guardada, PSE, Boton Bancolombia), `DirectCheckoutPanel.vue`
  + modales (`AddCardModal`, `AddNequiModal`, `PseModal`), integrado en
  `SubscriptionView.vue` conviviendo con el boton de widget legado (sin
  tocar `useSubscriptionCheckout`). **No verificado** — esta maquina no
  tiene Node/npm instalados, asi que no se pudo compilar/type-check ni
  correr el dev server. Revisado a mano contra los patrones existentes del
  repo (props/emits de `NxModal`/`NxSelect`/`NxSwitch`, convenciones de
  `httpClient`/TanStack Query) pero sin ejecutar nada.
- **Fuentes de Pago (sección 9, nueva)**: Core (9.3) **implementado y
  validado en sandbox real** (ver `docs/APP_INTEGRATION.md` sección 9 de
  `nexolu-payments-core`). `nexolu-pos-api` (9.4) **implementado** —
  tabla `business_payment_sources` (schema.sql + patch),
  `PaymentsCoreService::createPaymentSource()`,
  `BusinessPaymentSourceController` (index/store/destroy, destroy es
  soft-delete local, no llama a Wompi), rutas, tests
  (`tests/Feature/Api/V1/BusinessPaymentSourceTest.php`) — no verificados
  con `php artisan test` por la misma limitación de entorno que la Fase 2.
  `nexolu-pos-front` (9.5) **implementado** (ver arriba, mismo repo que
  Fase 3). Ampliacion
  pedida por el usuario el 2026-08-15: segregar, en el checkout de
  suscripcion, **tarjeta y Nequi** (los unicos medios que Wompi permite
  tokenizar de verdad para reuso -- "Fuentes de Pago") de **PSE y Boton
  Bancolombia** (siempre de una sola vez, nunca reusables). Alcance
  confirmado con el usuario: "guardar metodo de pago" -- tokenizar UNA vez,
  guardar el `payment_source_id`, reusarlo en pagos futuros con un click.
  El negocio sigue iniciando cada pago manualmente (sin cron de cobro
  automatico silencioso, eso queda fuera de alcance a proposito).

Este documento se escribio ANTES de tocar codigo, para acordar el diseño
entre los tres repos involucrados (`nexolu-payments-core`, `nexolu-pos-api`,
`nexolu-pos-front`).

## 1. Punto de partida (lo que ya existe, verificado)

- `nexolu-payments-core` ya tiene `flow="api"` funcionando de punta a punta,
  pero **solo para `payment_method.type: "CARD"`** (tarjeta tokenizada).
  Validado contra Wompi sandbox real (aprobado y declinado) el 2026-08-14 —
  ver `docs/APP_INTEGRATION.md` sección 7 de ese repo.
- `nexolu-pos-api` **ya migró por completo** de Wompi directo al Core (no
  hay `WompiService`/`WompiWebhookController` legacy en este repo — fue una
  reescritura desde cero que ya integró contra el Core). Pero solo usa
  `flow="widget"` (el default), y solo para dos casos de cobro: suscripción
  SaaS (`SubscriptionService`) y compra de packs de mensajes IA
  (`AiMessagePackCheckoutService`). El POS de venta en tienda (`Sale`,
  `Receivable`) no toca el Core en absoluto — el método de pago ahí es una
  etiqueta manual (efectivo/tarjeta/transferencia), sin pasarela.
- Archivos reales relevantes en `nexolu-pos-api` (ya leídos, no supuestos):
  - `app/Services/PaymentsCoreService.php` — único cliente HTTP saliente
    hacia el Core (`createIntent`, `getTransaction`). No tiene método
    `charge()` todavía.
  - `app/Services/SubscriptionService.php` — `initiateCheckout()` crea la
    orden (`SubscriptionCheckoutOrder`, prefijo `NEX-`) y llama
    `createIntent()` sin pasar `flow` (default `widget`).
  - `app/Http/Controllers/Api/V1/SubscriptionController.php` — expone
    `POST /v1/subscription/checkout` y `GET /v1/subscription/checkout/{reference}`.
  - `app/Http/Controllers/Api/PaymentsCoreWebhookController.php` — recibe
    `POST /api/webhooks/payments-core`, verifica firma HMAC, es agnóstico
    del método de pago usado (solo mira `event`/`reference`/`status`) —
    **no necesita cambios** para lo que propone este plan.
  - `config/services.php` bloque `payments_core` — un solo
    `api_key`/`webhook_secret` para toda la plataforma (Nexolu le cobra a
    cada negocio, no hay credenciales por tenant).
- Frontend: `nexolu-pos-front/src/modules/subscription/composables/useSubscriptionCheckout.ts`
  abre el widget de Wompi (`checkout.wompi.co/widget.js`) con los params que
  regresa el Core, y hace polling de `GET /v1/subscription/status` tras un
  resultado `APPROVED` del widget (nunca confía en esa respuesta, espera el
  webhook real).

## 2. Alcance de este plan

Confirmado con el usuario: agregar, además de tarjeta, los métodos **Nequi**,
**PSE** y **Botón de Transferencia Bancolombia**, para **pagos recurrentes**
(el caso de uso existente: suscripción SaaS del negocio hacia Nexolu).

**Fuera de alcance explícito** (no se toca en este plan):
- Cobro con tarjeta/Nequi/PSE a los CLIENTES FINALES del negocio en el POS
  de venta en tienda (`Sale`/`Receivable`) — hoy no pasa por el Core y
  extenderlo ahí es una iniciativa aparte, mucho más grande (multi-tenant:
  cada negocio necesitaría sus propias credenciales de Wompi, cosa que el
  modelo actual de `ProviderCredential` del Core ya soporta, pero
  `nexolu-pos-api` no tiene ese concepto de credenciales por negocio hoy).
- Reemplazar el widget: se agrega `flow="api"` como alternativa, el flujo
  widget existente no se borra en esta fase (ver sección 6, decisión
  pendiente).

Los packs de mensajes de IA (`AiMessagePackCheckoutService`) usan un patrón
**idéntico** a suscripción — se deja como fast-follow de una fila (fase 4),
no bloqueante.

## 3. Cómo pide Wompi cada método (verificado contra docs.wompi.co, no supuesto)

Todos comparten: requieren `acceptance_token` (ya lo resuelve
`build_payment_init`/`charge` de hoy) y todos excepto `CARD` son
**asíncronos** — Wompi responde `PENDING` y el resultado final llega vía
long-polling a `GET /transactions/{id}` y/o el webhook. Esto encaja con la
arquitectura actual del Core (el webhook YA es la única fuente de verdad),
así que no hace falta rediseñar esa parte — solo generalizar `charge()`.

### `NEQUI` (más simple, sin redirect)

```json
{ "payment_method": { "type": "NEQUI", "phone_number": "3107654321" } }
```
Confirmación: notificación push en la app Nequi del cliente. No hay URL de
redirect — solo esperar el webhook (o el push, del lado del usuario).
Sandbox: `3991111111` → aprobada, `3992222222` → declinada.

### `PSE` (requiere elegir banco + datos del pagador)

Prerrequisito: `GET /v1/pse/financial_institutions` en Wompi (lista de
bancos), con `Authorization: Bearer <public_key>` — mismo tipo de auth
pública que `POST /tokens/cards`. Verificado contra sandbox real (no
supuesto) con la llave de prueba ya registrada:

```
GET https://sandbox.wompi.co/v1/pse/financial_institutions
Authorization: Bearer pub_test_9LSCndGOI1rdvYB9WW8Nwjt5NJxpInaj
```
```json
{
  "data": [
    { "financial_institution_code": "1", "financial_institution_name": "Banco que aprueba" },
    { "financial_institution_code": "2", "financial_institution_name": "Banco que declina" },
    { "financial_institution_code": "3", "financial_institution_name": "Banco que simula un error" }
  ],
  "meta": {}
}
```
(En sandbox son los 3 bancos de prueba documentados en `datos-de-prueba-en-sandbox`; en producción esta misma llamada trae la lista real de bancos colombianos.) Este plan propone que el Core lo exponga proxeado (sección 4.5) en vez de que cada frontend integrador tenga que llamar a Wompi directo y saber armar la URL sandbox/production.

```json
{
  "customer_email": "cliente@correo.com",
  "payment_method": {
    "type": "PSE",
    "user_type": 0,
    "user_legal_id_type": "CC",
    "user_legal_id": "1099888777",
    "financial_institution_code": "1",
    "payment_description": "Suscripcion Nexolu - <business_id>"
  },
  "customer_data": { "phone_number": "573145678901", "full_name": "Nombre Apellido" }
}
```
Confirmación: Wompi devuelve, tras crear la transacción, un campo
`data.payment_method.extra.async_payment_url` (no siempre en la respuesta
inicial — hay que consultar `GET /transactions/{id}` hasta que aparezca).
Hay que redirigir al usuario ahí para que complete el pago en su banco.
Sandbox: código de institución `"1"` → aprobada, `"2"` → declinada.

### `BANCOLOMBIA_TRANSFER` (Botón Bancolombia)

```json
{
  "payment_method": {
    "type": "BANCOLOMBIA_TRANSFER",
    "payment_description": "Suscripcion Nexolu - <business_id>",
    "ecommerce_url": "https://pos.nexolu.co/subscription?paid=1"
  }
}
```
Mismo patrón que PSE: `extra.async_payment_url` aparece por polling, hay que
redirigir ahí.

## 4. Diseño: `nexolu-payments-core` (Fase 1, prerrequisito de todo lo demás)

### 4.1 `providers/base.py`

Reemplazar el único `CardPaymentMethod` por una unión discriminada por
`type` (todas `frozen=True`, siguiendo el estilo actual):

```python
@dataclass(frozen=True)
class CardPaymentMethod:
    token: str
    installments: int = 1
    type: Literal["CARD"] = "CARD"

@dataclass(frozen=True)
class NequiPaymentMethod:
    phone_number: str
    type: Literal["NEQUI"] = "NEQUI"

@dataclass(frozen=True)
class PsePaymentMethod:
    user_type: int  # 0 natural, 1 juridica
    user_legal_id_type: str
    user_legal_id: str
    financial_institution_code: str
    payment_description: str
    customer_full_name: str
    customer_phone_number: str
    type: Literal["PSE"] = "PSE"

@dataclass(frozen=True)
class BancolombiaTransferPaymentMethod:
    payment_description: str
    ecommerce_url: str
    type: Literal["BANCOLOMBIA_TRANSFER"] = "BANCOLOMBIA_TRANSFER"

PaymentMethodInput = (
    CardPaymentMethod | NequiPaymentMethod | PsePaymentMethod | BancolombiaTransferPaymentMethod
)
```

`ChargeResult` gana un campo nuevo, siempre presente (`None` si no aplica):

```python
redirect_url: str | None
```

`PaymentProvider.charge()` cambia su tipo de `payment_method` de
`CardPaymentMethod` a `PaymentMethodInput`.

### 4.2 `providers/wompi.py`

- `charge()` construye el `payment_method` dict según `type(payment_method)`
  (un `match`/`isinstance` por variante) en vez de asumir siempre `CARD`.
  El `acceptance_token` se sigue pidiendo igual para todos (mismo código de
  hoy, sin cambios).
- Para `PSE`, el `customer_data` va como llave hermana de `payment_method`
  en el body (no anidado dentro) — ver forma exacta en sección 3.
- Para métodos async (`PSE`, `BANCOLOMBIA_TRANSFER`): después de crear la
  transacción, hacer un polling **acotado y corto** contra
  `GET /transactions/{id}` (propongo 8 intentos × 1s = 8s tope) buscando
  `data.payment_method.extra.async_payment_url`. Si aparece, se devuelve en
  `ChargeResult.redirect_url`; si no aparece en el tope de tiempo, se
  devuelve `redirect_url=None` (ver riesgo conocido en sección 7).
- `NEQUI`: sin polling adicional, `redirect_url` siempre `None`.

### 4.3 `core/payments/service.py`

`charge_payment_intent()` no cambia de forma — ya devuelve el `ChargeResult`
completo, que ahora trae `redirect_url`. Sin cambios estructurales.

### 4.4 `api/v1/payments.py`

- `ChargeIn` pasa de un solo modelo `CardPaymentMethodIn` a una unión
  discriminada por Pydantic (`Annotated[Union[...], Field(discriminator="type")]`),
  un sub-modelo por método con sus propios campos/validaciones (ej.
  `phone_number` con regex de celular colombiano en `NequiIn`).
- La respuesta de `POST /intents/{reference}/charge` agrega
  `"redirect_url": str | None` — el consumidor (`nexolu-pos-api`) decide si
  redirigir al usuario con eso.

### 4.5 Endpoints nuevos de descubrimiento: métodos disponibles y bancos PSE

Pedido explícito del usuario: en vez de enterrar `accepted_payment_methods`
dentro de `payment_init` (que solo existe DESPUÉS de crear un intent),
exponerlo como **endpoint propio**, consultable antes de que el usuario
elija monto/inicie checkout — y lo mismo para la lista de bancos PSE, en
vez de que cada frontend integrador tenga que hablarle a Wompi directo.

Ambos datos ya se verificaron reales contra el comercio sandbox registrado
(`pub_test_9LSCndGOI1rdvYB9WW8Nwjt5NJxpInaj`), no son supuestos:

```
GET https://sandbox.wompi.co/v1/merchants/pub_test_9LSCndGOI1rdvYB9WW8Nwjt5NJxpInaj
```
```json
{
  "data": {
    "public_key": "pub_test_9LSCndGOI1rdvYB9WW8Nwjt5NJxpInaj",
    "accepted_currencies": ["COP"],
    "accepted_payment_methods": [
      "BANCOLOMBIA_TRANSFER", "NEQUI", "PSE", "CARD", "BANCOLOMBIA_COLLECT",
      "BANCOLOMBIA", "BANCOLOMBIA_QR", "DAVIPLATA", "BANCOLOMBIA_BNPL",
      "SU_PLUS", "CARD_POS"
    ]
  }
}
```

Nota importante: Wompi reporta TODO lo que el comercio activó (11 métodos en
este caso), pero el Core de este plan solo va a saber orquestar 4
(`CARD`/`NEQUI`/`PSE`/`BANCOLOMBIA_TRANSFER`, ver sección 3) — los demás
(`DAVIPLATA`, `BANCOLOMBIA_QR`, `BANCOLOMBIA_BNPL`, `SU_PLUS`, `CARD_POS`,
`BANCOLOMBIA_COLLECT`, `BANCOLOMBIA` a secas) no tienen `PaymentMethodInput`
ni lógica de `charge()` en esta fase. El endpoint del Core debe devolver la
**intersección** entre lo que Wompi acepta y lo que el Core sabe procesar,
para que `nexolu-pos-front` nunca ofrezca un botón que el Core rechazaría.

#### `GET /v1/payments/payment-methods`

Autenticado igual que el resto (`Authorization: Bearer <api_key>` de la
integración → resuelve `Integration` → credencial activa de `wompi`).
Reusa la llamada que `_fetch_acceptance_tokens()` ya hace a
`GET /merchants/:public_key` — se propone refactorizarla a una función que
devuelva el `data` completo del merchant (no solo los tokens), para que la
usen tanto `build_payment_init`/`charge` como este endpoint nuevo sin una
segunda llamada de red.

Respuesta:
```json
{
  "provider": "wompi",
  "accepted_payment_methods": ["CARD", "NEQUI", "PSE", "BANCOLOMBIA_TRANSFER"]
}
```
(la constante `_SUPPORTED_PAYMENT_METHODS = {"CARD", "NEQUI", "PSE",
"BANCOLOMBIA_TRANSFER"}` en `providers/wompi.py` es la que filtra; crece
sola el día que se implemente un método más, sin tocar el endpoint).

#### `GET /v1/payments/pse/financial-institutions`

Mismo auth. Proxea `GET /pse/financial_institutions` de Wompi usando la
`public_key` de la credencial activa de la integración (sandbox/producción
inferido igual que hoy, por el prefijo de la key). Normaliza el nombre de
los campos (agnóstico de proveedor, mismo criterio que `ProviderEvent.kind`):

```json
{
  "financial_institutions": [
    { "code": "1", "name": "Banco que aprueba" },
    { "code": "2", "name": "Banco que declina" },
    { "code": "3", "name": "Banco que simula un error" }
  ]
}
```

#### Cambios de código que implican (`nexolu-payments-core`)

- `providers/base.py`: nuevo dataclass `FinancialInstitution(code: str, name: str)`;
  `PaymentProvider` gana dos métodos al protocolo:
  `async def list_payment_methods(self, *, credentials) -> list[str]` y
  `async def list_pse_financial_institutions(self, *, credentials) -> list[FinancialInstitution]`.
- `providers/wompi.py`: implementa ambos. `list_payment_methods` llama al
  merchant endpoint (reusando el helper refactorizado) y filtra contra
  `_SUPPORTED_PAYMENT_METHODS`. `list_pse_financial_institutions` llama
  `GET /pse/financial_institutions` con la `public_key`.
- `core/payments/service.py`: dos funciones nuevas, mismo patrón que
  `create_payment_intent` (resuelven `Integration` → credencial activa →
  `IntegrationNotConfigured` si no hay → delegan al provider).
- `api/v1/payments.py`: los dos endpoints `GET` nuevos, mismo
  `Depends(get_current_integration)` que ya usan `/intents` y `/transactions`.
- `docs/APP_INTEGRATION.md`: documentar ambos en una sección 2c nueva
  ("Descubrir métodos de pago disponibles"), con la recomendación de
  llamarlos al montar la pantalla de checkout, no en cada request de cobro.

### 4.6 Tests y validación

- `tests/test_wompi_provider.py`: un test de `charge()` por método nuevo con
  `httpx_mock` (payload correcto enviado a Wompi, `redirect_url` extraído
  bien del polling simulado).
- `tests/test_payments_flow.py`: extender `test_charge_intent_*` con casos
  Nequi (sin redirect) y PSE (con redirect).
- `scripts/test_direct_api_flow.py`: agregar `--payment-method
  {card,nequi,pse,bancolombia_transfer}` y los datos de prueba
  correspondientes (sección 3), para repetir la validación real contra
  sandbox que ya se hizo con tarjeta.
- Tests nuevos con `httpx_mock` para `GET /payment-methods` (filtra
  correctamente contra `_SUPPORTED_PAYMENT_METHODS`) y
  `GET /pse/financial-institutions` (normaliza los campos de Wompi).
- `docs/APP_INTEGRATION.md` sección 2b: documentar los 4 shapes de
  `payment_method` soportados, y sección 2c nueva para los dos endpoints
  de descubrimiento (4.5).

## 5. Diseño: `nexolu-pos-api` (Fase 2, depende de la Fase 1)

### 5.1 `app/Services/PaymentsCoreService.php`

Agregar:

```php
/**
 * @param  array<string, mixed>  $paymentMethod  Forma exacta segun el tipo,
 *   ver docs/APP_INTEGRATION.md del Core seccion 2b.
 * @return array<string, mixed>
 */
public function charge(string $reference, array $paymentMethod): array
{
    try {
        $response = $this->client()->post(
            '/v1/payments/intents/'.rawurlencode($reference).'/charge',
            ['payment_method' => $paymentMethod],
        );
    } catch (ConnectionException $e) {
        throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
    }

    if ($response->failed()) {
        throw new RuntimeException($response->json('error') ?? 'El Payments Core no pudo procesar el cobro.');
    }

    return $response->json();
}
```

### 5.2 `app/Services/SubscriptionService.php`

- `initiateCheckout()` gana un parámetro (con default que preserve el
  comportamiento actual, ver decisión pendiente en sección 6):
  `initiateCheckout(Business $business, User $user, string $redirectUrl, string $flow = 'widget')`,
  y lo reenvía a `createIntent()` (que hoy no manda `flow` — hay que
  agregarlo también a `PaymentsCoreService::createIntent()`).
- Nuevo método `chargeCheckout()`:

```php
/**
 * @param  array<string, mixed>  $paymentMethod
 * @return array<string, mixed>
 */
public function chargeCheckout(Business $business, string $reference, array $paymentMethod): array
{
    // Verifica que la orden le pertenezca a este negocio ANTES de reenviar
    // el cobro al Core - mismo criterio de scoping que checkoutStatus().
    SubscriptionCheckoutOrder::where('business_id', $business->id)
        ->where('order_key', $reference)
        ->where('status', 'pending')
        ->firstOrFail();

    return $this->paymentsCore->charge($reference, $paymentMethod);
}
```

### 5.3 `app/Http/Controllers/Api/V1/SubscriptionController.php` + ruta nueva

```
POST /v1/subscription/checkout/{reference}/charge
```

Body validado por un `FormRequest` nuevo (`ChargeSubscriptionCheckoutRequest`)
con reglas condicionales según `payment_method.type` (`required_if`), para
no dejar pasar un PSE sin `financial_institution_code`, etc. Controller:

```php
public function charge(ChargeSubscriptionCheckoutRequest $request, string $reference): JsonResponse
{
    try {
        $result = $this->subscriptions->chargeCheckout(
            $request->user()->business,
            $reference,
            $request->validated('payment_method'),
        );
    } catch (RuntimeException $e) {
        return response()->json(['error' => $e->getMessage()], 502);
    }

    return response()->json($result);
}
```

### 5.4 Proxy de los endpoints de descubrimiento (4.5)

`nexolu-pos-front` no puede llamar al Core directo para estos dos GETs
(requieren `Authorization: Bearer <api_key>` de la integración, que es un
secreto de plataforma en `config/services.php` — nunca debe llegar al
navegador, a diferencia de la `public_key` de Wompi que sí es segura de
exponer). Igual que `createIntent`/`getTransaction`, `nexolu-pos-api` hace
de intermediario:

- `PaymentsCoreService.php`: dos métodos nuevos, `paymentMethods(): array`
  (`GET /v1/payments/payment-methods`) y
  `pseFinancialInstitutions(): array` (`GET /v1/payments/pse/financial-institutions`),
  mismo patrón try/catch que `getTransaction()`.
- Controller nuevo y liviano, `app/Http/Controllers/Api/V1/PaymentMethodsController.php`
  (no es específico de suscripción — el mismo catálogo aplica a packs de IA
  en la fase 4, y potencialmente a cualquier otro checkout futuro):
  - `GET /v1/payment-methods` → `paymentMethods()`
  - `GET /v1/pse/financial-institutions` → `pseFinancialInstitutions()`
  Ambos detrás de Sanctum (usuario autenticado normal), sin lógica de
  negocio — son catálogos, no dependen de `business_id`.
- El frontend los llama una vez al montar la pantalla de checkout (no en
  cada submit), cachea en memoria durante la sesión de checkout.

### 5.5 Nada que tocar en `PaymentsCoreWebhookController.php`

Ya es agnóstico del método de pago (confirmado leyendo el archivo completo)
— el webhook trae `event`/`reference`/`status`, nunca el método usado.

### 5.6 Tests

Nuevo `tests/Feature/Api/SubscriptionChargeTest.php` con `Http::fake()`
simulando al Core, cubriendo: cobro exitoso con cada tipo, orden ajena
(`404`), orden ya no-pending (`404`/error), Core caído (`502`). Nuevo
`tests/Feature/Api/PaymentMethodsControllerTest.php` para los dos endpoints
de catálogo (5.4), mockeando la respuesta del Core.

## 6. Diseño: `nexolu-pos-front` (Fase 3, depende de la Fase 2)

- Nuevo selector de método de pago en el checkout de suscripción. Al montar
  la pantalla (antes de que el usuario elija nada), el frontend llama a los
  dos endpoints proxy de `nexolu-pos-api` (5.4): `GET /v1/payment-methods`
  para saber qué botones mostrar (de los 4 posibles), y
  `GET /v1/pse/financial-institutions` para precargar la lista de bancos
  si `PSE` está entre los disponibles. Si el catálogo trae solo `["CARD"]`
  (comercio de Wompi sin los demás métodos activados todavía), el selector
  no se muestra y el checkout se comporta como hoy. El `flow` a pedir al
  crear el intent depende de la opción elegida (`api` para los 4 nuevos
  flujos, ver decisión pendiente abajo).
- **Tarjeta**: mismo patrón ya documentado en `docs/APP_INTEGRATION.md`
  sección 2b del Core — tokenizar directo contra
  `https://{sandbox|production}.wompi.co/v1/tokens/cards` con
  `payment_init.public_key`, luego `POST .../charge` con el token.
- **Nequi**: input de celular (regex 10 dígitos), `POST .../charge` con
  `{type: "NEQUI", phone_number}`, mensaje "revisa la notificación en tu
  app Nequi", reusar tal cual el `pollStatus`/`startPolling` que ya existe.
- **PSE**: usa la lista de bancos ya precargada (arriba) para el selector;
  formulario (tipo/número de documento, nombre, teléfono, banco elegido),
  `POST .../charge`, y si la respuesta trae `redirect_url` →
  `window.location.href = redirect_url`. Si no la trae (ver riesgo sección
  7), hacer 2-3 reintentos de `GET .../checkout/{reference}` antes de
  avisarle al usuario que puede haber demora.
- **Botón Bancolombia**: sin formulario adicional, mismo patrón de redirect
  que PSE.
- Verificar (pendiente de revisar al implementar, no confirmado todavía) si
  `SubscriptionView.vue` ya maneja el query param `?wompi_paid=1` al montar
  para reanudar el polling tras el regreso del banco — si no, agregarlo,
  ya que PSE/Bancolombia dependen de ese mismo mecanismo de retorno.

## 7. Riesgos / decisiones abiertas (antes de implementar)

1. **`redirect_url` no garantizado a tiempo.** Wompi no documenta un SLA
   para que `async_payment_url` aparezca — el polling acotado de 8s
   (sección 4.2) es una apuesta razonable pero no una garantía. Si en
   sandbox se observa que tarda más, hay que decidir entre: (a) alargar el
   tope, o (b) exponer el campo también en `GET /transactions/{reference}`
   del Core para que el frontend pueda seguir preguntando después del
   `charge()` inicial. Recomiendo validar esto empíricamente contra sandbox
   en la Fase 1 antes de comprometerse a un diseño de frontend específico.
2. **¿`flow="api"` reemplaza el widget o coexisten?** Esta pregunta no se
   resolvió explícitamente con el usuario. Propuesta: coexistir en esta
   fase (igual que el propio Core coexiste hoy) — el selector de método de
   pago nuevo en el frontend simplemente ofrece más opciones; no hay
   necesidad de apagar el widget todavía. Confirmar antes de la Fase 3.
3. **`SubscriptionCheckoutOrder.provider`** hoy es `'wompi'` siempre (es el
   *proveedor*, no el *método*). Si se quiere reportar/filtrar por método de
   pago usado más adelante, haría falta una columna nueva
   (`payment_method_type`) — no bloqueante para esta fase, se puede leer del
   `payload` JSON que ya se guarda completo.
4. **Packs de mensajes IA** (Fase 4): mismo patrón exacto, se puede copiar
   `chargeCheckout`/endpoint/tests de suscripción casi 1:1 una vez esta fase
   esté validada.

## 8. Orden de ejecución propuesto

1. **Fase 1 — Core**: NEQUI + PSE + BANCOLOMBIA_TRANSFER en
   `nexolu-payments-core`, más los dos endpoints de descubrimiento
   (`GET /payment-methods`, `GET /pse/financial-institutions`, sección 4.5),
   tests con mocks, validación real en sandbox (incluyendo medir cuánto
   tarda `async_payment_url` en aparecer, para resolver el riesgo #1).
2. **Fase 2 — `nexolu-pos-api` backend**: `PaymentsCoreService::charge()` +
   proxy de los endpoints de descubrimiento (5.4), endpoint de cobro, tests
   Feature.
3. **Fase 3 — `nexolu-pos-front`**: selector de método + los 4 flujos.
4. **Fase 4 (fast-follow)**: replicar en packs de mensajes IA.

Cada fase es verificable de forma independiente (tests automatizados +, para
la Fase 1, sandbox real) antes de pasar a la siguiente.

## 9. Fuentes de Pago -- "guardar método de pago" (Tarjeta y Nequi)

Verificado contra `docs.wompi.co/docs/colombia/fuentes-de-pago/` (no
supuesto). Wompi solo permite tokenizar-para-reuso **Tarjeta y Nequi** (y
Daviplata/Bancolombia, fuera de alcance aquí) -- **PSE nunca aparece en esa
lista**, siempre es de una sola vez. Esto confirma la premisa del usuario:
"tarjeta y Nequi son los medios tokenizables" es correcto y ya está
soportado por Wompi, no es una idea nueva que haya que inventar.

### 9.1 El detalle que cambia la arquitectura: la llave privada

Tokenizar (Paso 1: `POST /tokens/cards` o `POST /tokens/nequi`) usa la
**llave pública** -- se puede seguir haciendo directo frontend→Wompi, igual
que ya hace el checkout de tarjeta hoy (`docs/APP_INTEGRATION.md` sección
2b del Core).

**Crear la fuente de pago (Paso 2: `POST /payment_sources`) exige la llave
PRIVADA**, y Wompi es explícito: *"debe hacerse desde tu back-end (servidor)
para mantener protegida dicha llave. Nunca debes hacerlo desde el
dispositivo del usuario"*. Esto **fuerza** a que ese paso viva en el Core
(el único que tiene la llave privada de cada integración) -- no puede ser
una llamada directa del frontend a Wompi como sí lo es la tokenización.

### 9.2 El flujo completo (3 pasos, verificados con ejemplos reales de Wompi)

**Paso 1 -- Tokenizar (frontend → Wompi directo, llave pública):**

Tarjeta: igual que hoy (`POST /tokens/cards`, sección 2b del Core).

Nequi (nuevo):
```
POST https://{sandbox|production}.wompi.co/v1/tokens/nequi
Authorization: Bearer {public_key}
{ "phone_number": "3107654321" }
→ { "data": { "id": "nequi_test_xxx", "status": "PENDING", "phone_number": "...", "name": "..." } }
```
El usuario recibe una notificación push en Nequi para APROBAR LA
SUSCRIPCIÓN (una única vez, no en cada cobro futuro). El frontend hace
polling directo a Wompi (llave pública, sin pasar por el Core) hasta que
`status` pase a `APPROVED`:
```
GET https://{env}.wompi.co/v1/tokens/nequi/{id}
Authorization: Bearer {public_key}
```

**Paso 2 -- Crear la fuente de pago (Core → Wompi, llave privada, NUEVO):**
```
POST /payment_sources  (contra Wompi, con Authorization: Bearer {private_key})
{
  "type": "CARD" | "NEQUI",
  "token": "<token del paso 1, ya APPROVED si es Nequi>",
  "customer_email": "...",
  "acceptance_token": "...",       // igual que build_payment_init/charge de hoy
  "accept_personal_auth": "..."
}
→ { "data": { "id": 3891, "type": "CARD", "status": "AVAILABLE", "public_data": {"type": "CARD"} } }
```
`id` es numérico (no un string `tok_...`) -- ojo al tipar. `status:
"AVAILABLE"` significa lista para cobrar.

**Paso 3 -- Cobrar con la fuente (Core → Wompi, llave privada, mismo
`POST /transactions` de siempre):**
```json
{
  "amount_in_cents": 4990000,
  "currency": "COP",
  "signature": "...",
  "customer_email": "...",
  "payment_method": { "installments": 2 },
  "reference": "...",
  "payment_source_id": 3891
}
```
Sin `type` dentro de `payment_method` esta vez -- `payment_source_id` va
HERMANO de `payment_method`, no dentro. `installments` solo aplica si la
fuente es tarjeta (Wompi lo ignora si es Nequi). **Confirmar en sandbox si
`acceptance_token` sigue siendo obligatorio aquí** -- el ejemplo de Wompi no
lo muestra en este paso, pero el mismo aviso de "obligatorio para crear
transacciones o fuentes de pago" en la misma página sugiere que sí; dado que
ya se pide siempre en `charge()`, la implementación lo manda por defecto
(no hace daño si Wompi lo ignora).

Wompi es explícito en que este cobro **no requiere intervención del usuario
en cada ocasión** -- es la base real de "pagos recurrentes/automáticos", a
diferencia de un cobro suelto con `payment_method: {type: NEQUI,
phone_number}` (lo que ya existe en la Fase 1), que sí manda push cada vez.

**Cancelar una fuente guardada** (para "eliminar método de pago"):
```
PUT /payment_sources/{id}/void   (llave privada) → status: "VOIDED"
```

### 9.3 Diseño: `nexolu-payments-core`

- `providers/base.py`: `PaymentSource(id: str, type: str, status: str)`;
  `PaymentSourceChargeMethod(payment_source_id: str, installments: int = 1)`
  se suma a `PaymentMethodInput`. Protocolo gana
  `async def create_payment_source(self, *, credentials, source_type: Literal["CARD","NEQUI"], token: str, customer_email: str) -> PaymentSource`
  y `async def void_payment_source(self, *, credentials, payment_source_id: str) -> PaymentSource`.
- `providers/wompi.py`: implementa ambos (reusa `_fetch_acceptance_tokens`).
  `charge()` gana una rama para `PaymentSourceChargeMethod`: en vez de
  `_payment_method_payload`, arma `{"payment_source_id": ..., "payment_method": {"installments": ...}}`.
- `core/payments/service.py`: `create_payment_source()` /
  `void_payment_source()`, mismo patrón resolver-credencial-o-503 que el
  resto. El Core **no persiste** el `payment_source_id` en su propia BD --
  lo crea y lo devuelve; quien decide guardarlo contra un negocio/cliente es
  el consumidor (`nexolu-pos-api`), igual que hoy el Core no sabe qué es un
  "Business". Mantiene al Core agnóstico de semántica de negocio.
- `api/v1/payments.py`: `POST /v1/payments/payment-sources` (body:
  `type`, `token`, `customer_email`) y `PUT /v1/payments/payment-sources/{id}/void`.
  `ChargeIn` gana la variante `{"type": "PAYMENT_SOURCE", "payment_source_id": "...", "installments": 1}`.
- Tests con `httpx_mock` + validación real en sandbox (tokenizar Nequi de
  prueba, esperar `APPROVED`, crear fuente, cobrar dos veces con el mismo
  `payment_source_id` para comprobar reuso real).

**Estado: implementado y validado (2026-08-15).** `pytest -v`: 51/51.
Validado en sandbox real: tarjeta tokenizada → fuente creada →
**cobrada dos veces con el mismo `payment_source_id` sin re-tokenizar**
(la prueba real de que el reuso funciona). Nequi también: con el celular
de prueba `3991111111`, el token pasa de `PENDING` a `APPROVED` casi de
inmediato en sandbox (sin push real necesario ahí -- en producción sí hace
falta), y la fuente resultante también cobró bien.

⚠️ **Hallazgo real que cambia el diseño de "eliminar" (sección 9.4)**:
`PUT /payment-sources/{id}/void` devolvió un error real de Wompi contra una
fuente `AVAILABLE` normal -- *"Únicamente se pueden anular fuentes de pago
con el tipo de operación financiera 'PREAUTHORIZATION'"*. El endpoint del
Core está bien (llega a Wompi, maneja el error), pero **no sirve para
"eliminar" una fuente de pago común**. Ver ajuste en 9.4.

### 9.4 Diseño: `nexolu-pos-api`

- Nueva tabla `business_payment_sources` (parche SQL en
  `database/legacy-schema/patches/`, mismo mecanismo que
  `2026_08_14_000001_add_pos_payment_methods.sql`): `business_id`,
  `provider_slug`, `payment_source_id`, `type` (`CARD`/`NEQUI`), `label`
  (ej. "Visa •••• 4242" o "Nequi 310•••4321", para mostrar en UI sin
  guardar datos sensibles), `status` (`active`/`removed`, columna propia,
  NO depende de que Wompi confirme un void), `created_at`.
- `PaymentsCoreService`: `createPaymentSource()`. **No** se agrega
  `voidPaymentSource()` en esta fase -- Wompi lo rechaza para fuentes
  normales (ver hallazgo arriba), así que no hay nada útil que llamar del
  lado del Core para "eliminar".
- Nuevo `BusinessPaymentSourceController` (o método en
  `PaymentMethodsController`): `POST /v1/payment-sources` (guarda el token
  ya tokenizado por el frontend, crea la fuente en el Core, persiste el
  `payment_source_id`), `GET /v1/payment-sources` (lista las del negocio
  autenticado), `DELETE /v1/payment-sources/{id}` (**soft-delete local
  únicamente** -- marca `status = 'removed'` en `business_payment_sources`
  y deja de ofrecerla en la UI; NO llama a Wompi. Si más adelante Wompi
  soporta anular fuentes normales, ahí sí se conecta).
- `SubscriptionService::chargeCheckout()` ya acepta cualquier
  `payment_method` tal cual (no requiere cambios): el frontend simplemente
  manda `{"type": "PAYMENT_SOURCE", "payment_source_id": X}`.

### 9.5 Diseño: `nexolu-pos-front`

Dos secciones separadas en la UI de pago de suscripción, tal como pidió el
usuario:

- **"Pagos recurrentes" (Tarjeta / Nequi)**: si el negocio ya tiene una
  fuente guardada (`GET /v1/payment-sources`), mostrarla con un botón
  "Pagar con esta tarjeta/Nequi" (un click, sin reingresar nada). Si no
  tiene ninguna, ofrecer "Agregar tarjeta" o "Agregar Nequi", que dispara el
  flujo de 3 pasos (tokenizar → esperar aprobación si es Nequi → guardar).
- **"Pagos normales" (PSE / Botón Bancolombia)**: el selector que ya
  diseñamos en la sección 6, sin cambios -- siempre de una sola vez, sin
  "guardar" nada.

### 9.6 Pruebas end-to-end -- estado real (2026-08-15)

**Hecho, contra Wompi sandbox real (no mocks)**: tokenizar tarjeta (llave
pública) → crear fuente de pago (Core, llave privada) → **cobrar DOS VECES
con el mismo `payment_source_id` sin volver a tokenizar** (la prueba de que
es genuinamente recurrente) → intentar `void` (falla con el error real de
Wompi documentado en 9.3). Repetido con Nequi: tokenizar con el celular de
prueba `3991111111` → el token pasa a `APPROVED` casi de inmediato en
sandbox (sin push real necesario ahí) → fuente creada → cobrada
exitosamente. Todo esto verificado con llamadas HTTP reales contra el Core
corriendo local + Wompi sandbox, no con `pytest`/mocks (que también pasan,
aparte).

**No hecho, por limitación de entorno**: el smoke test de
`nexolu-pos-front` en navegador real (Playwright/Cypress, o manual) contra
`nexolu-pos-api` corriendo. Esta máquina no tiene **PHP ni Node/npm
instalados** (se intentó levantar Docker Desktop como alternativa para
`nexolu-pos-api`, pero no llegó a arrancar en un tiempo razonable) -- sin
poder correr el backend Laravel ni compilar/servir el frontend Vue, no hay
forma de ejecutar un E2E real de las tres piezas juntas en este entorno.
`nexolu-pos-front` tampoco tiene Playwright/Cypress instalado todavía (repo
sin tooling de e2e).

**Recomendación para quien retome esto con un entorno completo**:
1. `php artisan test` en `nexolu-pos-api` (Fase 2 + `BusinessPaymentSourceTest`).
2. `npm run build` en `nexolu-pos-front` (type-check con `vue-tsc`, primera
   verificación real del código nuevo).
3. Levantar los 3 servicios juntos (Core + pos-api con MySQL + pos-front
   con `npm run dev`) contra Wompi sandbox y probar en un navegador real:
   agregar tarjeta y pagar, agregar Nequi y pagar, PSE, Botón Bancolombia,
   y confirmar que el flujo Widget legado (botón "Pagar con el widget de
   Wompi") sigue funcionando sin cambios.
