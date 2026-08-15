<?php

namespace App\Services;

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
     * @param  array{email: string, full_name: string}  $customer
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function createIntent(
        string $reference,
        int $amountCop,
        array $customer,
        string $redirectUrl,
        array $metadata = [],
        string $flow = 'widget',
    ): array {
        try {
            $response = $this->client()->post('/v1/payments/intents', [
                'reference' => $reference,
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
        $apiKey = config('services.payments_core.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw new RuntimeException('Payments Core no esta configurado (falta PAYMENTS_CORE_BASE_URL o PAYMENTS_CORE_API_KEY).');
        }

        return Http::withToken($apiKey)
            ->timeout(15)
            ->baseUrl(rtrim($baseUrl, '/'));
    }
}
