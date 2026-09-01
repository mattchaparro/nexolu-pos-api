<?php

namespace App\Services;

use App\Models\BusinessPaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Unico cliente saliente hacia el Nexolu Payments Core (servicio Python
 * aparte, repo nexolu-payments-core): pasarela de pagos unificada del
 * ecosistema Nexolu. Este POS ya no le habla a Wompi directo - crea un
 * "intent" de cobro aca y el Core arma el checkout con SUS credenciales de
 * Wompi (una por app integradora, no un WOMPI_PUBLIC_KEY global). El
 * resultado del pago llega despues por webhook firmado, nunca por la
 * respuesta de estas llamadas - ver PaymentsCoreWebhookController.
 */
class PaymentsCoreService
{
    /**
     * Credenciales de la pasarela PROPIA de un negocio, cuando el cobro es
     * suyo y no de Nexolu.
     *
     * Nulo = ruta global: la `PAYMENTS_CORE_API_KEY` del `.env`, que es la
     * integracion de Nexolu y se usa para cobrar suscripciones y packs de
     * IA. Ese camino queda intacto -- confundirlos seria cobrarle al
     * comerciante lo que compro su cliente.
     */
    private ?BusinessPaymentGateway $gateway = null;

    /**
     * Devuelve una COPIA apuntando a la pasarela del negocio. Copia y no
     * mutacion porque el servicio se resuelve del contenedor y es
     * compartido: dejarlo apuntando al negocio de la request anterior
     * mandaria el cobro de un comercio con las credenciales de otro.
     */
    public function usingGateway(BusinessPaymentGateway $gateway): self
    {
        $clone = clone $this;
        $clone->gateway = $gateway;

        return $clone;
    }

    /**
     * Crea un link de pago hospedado y devuelve a donde mandar al comprador.
     *
     * Es el flujo de la tienda online: no hay datos de tarjeta pasando por
     * el storefront, solo una URL. Bold ademas no soporta otra cosa (no
     * tokeniza tarjetas).
     *
     * Como siempre, esta respuesta NO confirma el pago: el estado final
     * llega por webhook.
     *
     * @param  array{email: string, full_name: string}  $customer
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function createPaymentLink(
        int $amountCop,
        string $description,
        array $customer,
        string $redirectUrl,
        array $metadata = [],
        ?int $expiresInMinutes = null,
    ): array {
        $provider = $this->gateway?->provider_slug;
        if ($provider === null) {
            throw new RuntimeException('No hay una pasarela del negocio configurada para cobrar en linea.');
        }

        try {
            $response = $this->client()->post('/v1/payments/links', array_filter([
                'amount_cop' => $amountCop,
                'currency' => 'COP',
                'description' => $description,
                'redirect_url' => $redirectUrl,
                'customer' => $customer,
                'metadata' => $metadata,
                'provider' => $provider,
                'expires_in_minutes' => $expiresInMinutes,
            ], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('detail') ?? $response->json('error') ?? 'El Payments Core no pudo crear el link de pago.'
            );
        }

        return $response->json();
    }

    /**
     * Los datafonos que el comercio expuso a la API.
     *
     * Una lista vacia casi siempre significa que el comerciante no habilito
     * "Conexiones API" en su app del proveedor, no que no tenga aparatos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTerminals(): array
    {
        $provider = $this->gateway?->provider_slug;
        if ($provider === null) {
            throw new RuntimeException('No hay una pasarela del negocio configurada.');
        }

        try {
            $response = $this->client()->get('/v1/payments/terminals', ['provider' => $provider]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('detail') ?? 'El Payments Core no pudo consultar los datafonos.'
            );
        }

        return $response->json('terminals') ?? [];
    }

    /**
     * Hace aparecer el monto en la pantalla de un datafono.
     *
     * NO confirma el pago: el cliente todavia tiene que pasar la tarjeta y
     * el resultado llega por webhook. Tampoco hay tiempo maximo -- si el
     * aparato esta bloqueado, el proveedor encola el cobro.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function chargeOnTerminal(
        int $amountCop,
        string $description,
        string $terminalSerial,
        string $terminalModel,
        string $sellerEmail,
        array $metadata = [],
    ): array {
        $provider = $this->gateway?->provider_slug;
        if ($provider === null) {
            throw new RuntimeException('No hay una pasarela del negocio configurada.');
        }

        try {
            $response = $this->client()->post('/v1/payments/terminals/charge', [
                'amount_cop' => $amountCop,
                'currency' => 'COP',
                'description' => $description,
                'terminal_serial' => $terminalSerial,
                'terminal_model' => $terminalModel,
                'seller_email' => $sellerEmail,
                'metadata' => $metadata,
                'provider' => $provider,
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('detail') ?? 'El datafono no acepto el cobro.'
            );
        }

        return $response->json();
    }

    /**
     * El Core es quien genera y devuelve la `reference` (campo `reference`
     * de la respuesta) - este metodo nunca la envia, el Core la rechaza
     * silenciosamente si se manda (el schema no la acepta). El llamador
     * debe persistir la reference devuelta antes de usarla para consultar
     * estado o cobrar.
     *
     * @param  array{email: string, full_name: string}  $customer
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function createIntent(
        int $amountCop,
        array $customer,
        string $redirectUrl,
        array $metadata = [],
        string $flow = 'widget',
    ): array {
        try {
            $response = $this->client()->post('/v1/payments/intents', [
                'amount_cop' => $amountCop,
                'currency' => 'COP',
                'redirect_url' => $redirectUrl,
                'customer' => $customer,
                'metadata' => $metadata,
                'flow' => $flow,
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? 'El Payments Core no pudo crear el cobro.');
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransaction(string $reference): array
    {
        try {
            $response = $this->client()->get('/v1/payments/transactions/'.rawurlencode($reference));
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? 'El Payments Core no pudo consultar la transaccion.');
        }

        return $response->json();
    }

    /**
     * Le pide al Core que le pregunte al proveedor en que quedo el cobro.
     *
     * A diferencia de `getTransaction`, que solo lee lo que el Core ya sabe,
     * esto gasta una llamada de red contra la pasarela. Hace falta porque el
     * webhook no alcanza como unica fuente de verdad: Bold no lo manda en su
     * ambiente de pruebas y en produccion se toma hasta 10 minutos, y el
     * comprador vuelve a la tienda mucho antes.
     *
     * @return array<string, mixed>
     */
    public function refreshTransaction(string $reference): array
    {
        try {
            $response = $this->client()->post('/v1/payments/transactions/'.rawurlencode($reference).'/refresh');
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('detail') ?? 'El Payments Core no pudo consultar el pago.');
        }

        return $response->json();
    }

    /**
     * Cobra un intent creado con flow="api" (tarjeta ya tokenizada por el
     * frontend, o Nequi/PSE/Boton Bancolombia). El resultado que devuelve
     * esto es solo el ACK inmediato del proveedor - la confirmacion real
     * sigue llegando por PaymentsCoreWebhookController, igual que en el
     * flujo Widget. Ver docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 3 para
     * la forma exacta de $paymentMethod segun el tipo.
     *
     * @param  array<string, mixed>  $paymentMethod
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

    /**
     * Catalogo de metodos de pago que el comercio de Wompi de esta
     * integracion tiene habilitados (interseccion con lo que el Core sabe
     * orquestar) - ver docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 4.5.
     *
     * @return array<string, mixed>
     */
    public function paymentMethods(): array
    {
        try {
            $response = $this->client()->get('/v1/payments/payment-methods');
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? 'El Payments Core no pudo listar los metodos de pago.');
        }

        return $response->json();
    }

    /**
     * Lista de bancos disponibles para PSE, proxeada desde Wompi via el
     * Core - ver docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 4.5.
     *
     * @return array<string, mixed>
     */
    public function pseFinancialInstitutions(): array
    {
        try {
            $response = $this->client()->get('/v1/payments/pse/financial-institutions');
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? 'El Payments Core no pudo listar los bancos PSE.');
        }

        return $response->json();
    }

    /**
     * Tokeniza tarjeta/Nequi PARA REUSO ("Fuentes de Pago" de Wompi) -- a
     * diferencia de `charge()`, que cobra una sola vez. `$token` ya lo
     * genero el frontend hablando directo con el proveedor (llave publica);
     * el Core exige la llave PRIVADA para este paso especifico, por eso no
     * se puede hacer directo desde el frontend.
     *
     * @return array<string, mixed>
     */
    public function createPaymentSource(string $type, string $token, string $customerEmail): array
    {
        try {
            $response = $this->client()->post('/v1/payments/payment-sources', [
                'type' => $type,
                'token' => $token,
                'customer_email' => $customerEmail,
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? 'El Payments Core no pudo crear la fuente de pago.');
        }

        return $response->json();
    }

    private function client(): PendingRequest
    {
        $baseUrl = config('services.payments_core.base_url');
        // La llave del negocio gana sobre la global. La URL base es la misma
        // para los dos: un solo Payments Core, integraciones distintas.
        $apiKey = $this->gateway?->integration_api_key ?: config('services.payments_core.api_key');

        if (! $baseUrl) {
            throw new RuntimeException('Payments Core no esta configurado (falta PAYMENTS_CORE_BASE_URL).');
        }

        if (! $apiKey) {
            throw new RuntimeException($this->gateway !== null
                ? 'La pasarela de este negocio no tiene credenciales configuradas.'
                : 'Payments Core no esta configurado (falta PAYMENTS_CORE_API_KEY).');
        }

        return Http::withToken($apiKey)
            ->timeout(15)
            ->baseUrl(rtrim($baseUrl, '/'));
    }
}
