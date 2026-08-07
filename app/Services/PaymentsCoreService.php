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
    public function createIntent(string $reference, int $amountCop, array $customer, string $redirectUrl, array $metadata = []): array
    {
        try {
            $response = $this->client()->post('/v1/payments/intents', [
                'reference' => $reference,
                'amount_cop' => $amountCop,
                'currency' => 'COP',
                'redirect_url' => $redirectUrl,
                'customer' => $customer,
                'metadata' => $metadata,
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
