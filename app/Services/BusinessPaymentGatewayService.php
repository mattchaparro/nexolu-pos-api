<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessPaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Conecta la pasarela PROPIA de un negocio contra el Payments Core.
 *
 * El comerciante pega las llaves que le dio su proveedor (Bold o Wompi) y
 * este servicio hace todo lo demas: crea su merchant y su integracion en el
 * Core si no existen, guarda alli las llaves del proveedor, y se queda de
 * este lado con la `api_key` y el `webhook_secret` de ESA integracion.
 *
 * Las llaves del proveedor **no se guardan en este repo**: viven cifradas en
 * el Core, que es quien las usa para hablar con Bold o Wompi. Aca solo queda
 * la credencial que nos identifica contra el Core.
 *
 * Usa la PROVISIONING KEY, no la api_key de una integracion: dar de alta
 * merchants es una operacion administrativa. Por eso este servicio nunca se
 * expone a una ruta publica.
 */
class BusinessPaymentGatewayService
{
    /**
     * @param  array<string, string>  $providerCredentials  llaves del proveedor, tal como las dio
     */
    public function connect(Business $business, string $provider, array $providerCredentials, string $environment = 'production'): BusinessPaymentGateway
    {
        if (! in_array($provider, BusinessPaymentGateway::PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider_slug' => 'Pasarela no soportada.']);
        }

        $gateway = BusinessPaymentGateway::firstOrNew([
            'business_id' => $business->id,
            'provider_slug' => $provider,
        ]);

        try {
            $merchantId = $gateway->payments_core_merchant_id ?: $this->ensureMerchant($business);
            // Una integracion por negocio y proveedor: el webhook_secret que
            // devuelve es el que va a firmar los eventos de sus cobros, y es
            // lo que hace que el webhook pueda distinguir un pago suyo de uno
            // de otro comercio (ver PaymentsCoreWebhookController).
            [$apiKey, $webhookSecret] = $gateway->integration_api_key
                ? [$gateway->integration_api_key, $gateway->webhook_secret]
                : $this->ensureIntegration($merchantId, $business, $provider, $environment);

            $this->storeProviderCredentials($merchantId, $provider, $providerCredentials, $environment);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Se deja anotado el motivo para que el comerciante vea por que no
            // le funciona, en vez de un interruptor que no hace nada.
            $gateway->fill([
                'environment' => $environment,
                'is_active' => false,
                'last_error' => mb_substr($e->getMessage(), 0, 300),
            ])->save();

            throw $e;
        }

        $gateway->fill([
            'environment' => $environment,
            'payments_core_merchant_id' => $merchantId,
            'integration_api_key' => $apiKey,
            'webhook_secret' => $webhookSecret,
            'is_active' => true,
            'connected_at' => now(),
            'last_error' => null,
        ])->save();

        return $gateway;
    }

    public function disconnect(BusinessPaymentGateway $gateway): void
    {
        // Solo se apaga de este lado: las credenciales siguen en el Core por
        // si el comerciante vuelve, y las transacciones viejas necesitan que
        // su integracion siga existiendo para poder consultarse.
        $gateway->fill(['is_active' => false])->save();
    }

    private function ensureMerchant(Business $business): string
    {
        $slug = 'neg-'.$business->slug;
        $response = $this->client()->post('/v1/admin/merchants', [
            'name' => mb_substr((string) $business->name, 0, 128),
            'slug' => mb_substr($slug, 0, 64),
        ]);

        if ($response->status() === 409) {
            // Ya existia (reconexion): el Core no expone "buscar por slug",
            // asi que se recupera de la lista.
            return $this->findMerchantIdBySlug($slug);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->detail($response, 'No se pudo registrar el comercio en la pasarela.'));
        }

        return (string) $response->json('id');
    }

    private function findMerchantIdBySlug(string $slug): string
    {
        $response = $this->client()->get('/v1/admin/merchants');
        if ($response->failed()) {
            throw new RuntimeException($this->detail($response, 'No se pudo consultar el comercio en la pasarela.'));
        }

        foreach ($response->json('merchants') ?? [] as $merchant) {
            if (($merchant['slug'] ?? null) === $slug) {
                return (string) $merchant['id'];
            }
        }

        throw new RuntimeException('El comercio existe en la pasarela pero no se pudo recuperar.');
    }

    /** @return array{0: string, 1: string} api_key y webhook_secret */
    private function ensureIntegration(string $merchantId, Business $business, string $provider, string $environment): array
    {
        $response = $this->client()->post("/v1/admin/merchants/{$merchantId}/integrations", [
            'name' => mb_substr(($business->name ?? 'Tienda').' - tienda online', 0, 128),
            'slug' => mb_substr("tienda-{$business->slug}-{$provider}", 0, 64),
            'environment' => $environment,
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/webhooks/payments-core',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->detail($response, 'No se pudo crear la integracion en la pasarela.'));
        }

        return [(string) $response->json('api_key'), (string) $response->json('webhook_secret')];
    }

    /** @param  array<string, string>  $credentials */
    private function storeProviderCredentials(string $merchantId, string $provider, array $credentials, string $environment): void
    {
        $response = $this->client()->post("/v1/admin/merchants/{$merchantId}/providers/{$provider}", [
            'environment' => $environment,
            'credentials' => $credentials,
        ]);

        if ($response->status() === 409) {
            // Ya habia credenciales para ese entorno. No es un error: es el
            // caso normal de COMPLETAR un juego -- conectaste el boton de
            // pagos y meses despues agregas las llaves de datafono. El Core
            // fusiona sobre lo guardado, asi que mandar solo las nuevas no
            // borra las viejas.
            $response = $this->client()->put("/v1/admin/merchants/{$merchantId}/providers/{$provider}", [
                'environment' => $environment,
                'credentials' => $credentials,
            ]);
        }

        if ($response->status() === 422) {
            throw ValidationException::withMessages([
                'credentials' => $this->detail($response, 'Faltan credenciales para esta pasarela.'),
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->detail($response, 'La pasarela rechazo las credenciales.'));
        }
    }

    private function detail(Response $response, string $fallback): string
    {
        return (string) ($response->json('detail') ?? $response->json('error') ?? $fallback);
    }

    private function client(): PendingRequest
    {
        $baseUrl = config('services.payments_core.base_url');
        $provisioningKey = config('services.payments_core.provisioning_key');

        if (! $baseUrl || ! $provisioningKey) {
            throw new RuntimeException('Payments Core no esta configurado para aprovisionar (falta PAYMENTS_CORE_BASE_URL o PAYMENTS_CORE_PROVISIONING_KEY).');
        }

        try {
            return Http::withHeaders(['X-Payments-Provisioning-Key' => $provisioningKey])
                ->timeout(20)
                ->baseUrl(rtrim($baseUrl, '/'));
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar a Payments Core.', previous: $e);
        }
    }
}
