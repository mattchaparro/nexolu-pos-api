<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessPaymentGateway;
use App\Services\BusinessPaymentGatewayService;
use App\Services\PaymentsCoreService;
use App\Support\PaymentCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * La pasarela propia del negocio: conectarla, ver su estado, desconectarla.
 *
 * NO depende del modulo de tienda online. La misma llave de Bold habilita el
 * cobro con datafono, asi que conectar una pasarela le sirve a un negocio
 * que solo vende en mostrador. Por eso vive en Ajustes -> Ventas, junto a
 * los medios de pago, y no dentro de la tienda.
 *
 * Nunca devuelve secretos. Las llaves del proveedor se mandan una vez, se
 * guardan cifradas en el Payments Core y desde aca no se pueden volver a
 * leer -- ni siquiera por el dueño. Si las pierde, las saca de nuevo del
 * panel de su proveedor.
 */
class BusinessPaymentGatewayController extends Controller
{
    public function __construct(private BusinessPaymentGatewayService $gateways) {}

    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $connected = BusinessPaymentGateway::query()->get()
            ->keyBy('provider_slug');

        $providers = collect(BusinessPaymentGateway::PROVIDERS)->map(function (string $slug) use ($connected, $business) {
            $gateway = $connected->get($slug);

            // Solo las capacidades que el negocio puede usar: pedirle las
            // llaves del boton de pagos a quien no tiene tienda seria pedirle
            // credenciales para algo que no va a poder usar.
            $capacidades = [];
            foreach (PaymentCapabilities::capabilitiesOf($slug) as $capability) {
                if (! PaymentCapabilities::availableTo($business, $capability)) {
                    continue;
                }
                $capacidades[$capability] = PaymentCapabilities::fieldsFor($slug, $capability);
            }

            return [
                'provider_slug' => $slug,
                'capabilities' => $capacidades,
                'is_connected' => $gateway !== null && $gateway->isUsable(),
                'is_active' => (bool) $gateway?->is_active,
                'environment' => $gateway?->environment,
                'connected_at' => $gateway?->connected_at?->toIso8601String(),
                'last_error' => $gateway?->last_error,
                // La URL que el comerciante tiene que pegar en el panel de
                // SU proveedor. Sin esto conectaba las llaves, veia
                // "Conectado" y creia que habia terminado -- y su primer
                // pedido se quedaba esperando un pago que ya habia entrado.
                'webhook_url' => $gateway !== null ? $this->webhookUrl($gateway) : null,
            ];
        })->values();

        return response()->json(['providers' => $providers]);
    }

    /**
     * A donde debe avisar el proveedor cuando alguien paga.
     *
     * Es POR COMERCIO y no una URL central: asi entran tambien los cobros
     * que el POS no origino -- el QR fisico pegado al datafono --, que sin
     * esto no habria forma de cuadrar (ver GatewayReconciliationService).
     * Los pagos de nuestros propios links llegan por la misma ruta y se
     * reconocen por su referencia.
     */
    private function webhookUrl(BusinessPaymentGateway $gateway): ?string
    {
        $merchantId = $gateway->payments_core_merchant_id;
        if (! $merchantId) {
            return null;
        }

        $base = rtrim((string) config('services.payments_core.base_url'), '/');

        return "{$base}/v1/webhooks/{$gateway->provider_slug}/merchants/{$merchantId}";
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $provider = (string) $request->input('provider_slug');

        // Solo se aceptan credenciales de capacidades que el negocio puede
        // usar. Sin esto, un negocio sin tienda podria mandar las llaves del
        // boton de pagos por API aunque la pantalla no se las pida.
        $grupos = [];
        foreach (PaymentCapabilities::capabilitiesOf($provider) as $capability) {
            if (PaymentCapabilities::availableTo($business, $capability)) {
                $grupos[$capability] = PaymentCapabilities::fieldsFor($provider, $capability);
            }
        }
        $fields = array_merge(...array_values($grupos ?: [[]]));

        $rules = [
            'provider_slug' => ['required', Rule::in(BusinessPaymentGateway::PROVIDERS)],
            'environment' => ['sometimes', Rule::in(['sandbox', 'production'])],
        ];
        foreach ($fields as $field) {
            // `present` + `nullable`, no `required`: la llave secreta de Bold
            // en sandbox es la cadena vacia, y es un valor legitimo. Y ademas
            // llega como NULL, no como "": el middleware
            // ConvertEmptyStringsToNull de Laravel la convierte antes de que
            // la validacion la vea. Con `string` a secas esto reventaba.
            // `sometimes`: cada juego de llaves se puede guardar por
            // separado. Un comercio configura el datafono hoy y el boton de
            // pagos el mes que viene, sin tener que mandar los dos.
            $rules["credentials.{$field}"] = ['sometimes', 'nullable', 'string', 'max:500'];
        }
        $validator = validator($request->all(), $rules);

        /*
         * Un juego de llaves se manda ENTERO o no se manda.
         *
         * Los campos son `sometimes` para poder configurar el datafono hoy y
         * el boton de pagos el mes que viene sin pisar el otro. Pero a medias
         * no sirve: con la llave de identidad y sin la secreta, la pasarela
         * queda conectada y falla al primer cobro, sin decir por que.
         */
        $validator->after(function ($validator) use ($request, $grupos) {
            foreach ($grupos as $campos) {
                $recibidos = array_filter(
                    $campos,
                    fn (string $campo) => $request->has("credentials.{$campo}"),
                );

                if ($recibidos === [] || count($recibidos) === count($campos)) {
                    continue;
                }

                foreach (array_diff($campos, $recibidos) as $faltante) {
                    $validator->errors()->add(
                        "credentials.{$faltante}",
                        'Faltan llaves de este juego: se guardan todas juntas o ninguna.',
                    );
                }
            }
        });

        $data = $validator->validate();

        try {
            $gateway = $this->gateways->connect(
                $business,
                $provider,
                // Se vuelve a "" lo que el middleware convirtio en null.
                array_map(fn ($value) => (string) $value, $data['credentials'] ?? []),
                $data['environment'] ?? 'production',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'provider_slug' => $gateway->provider_slug,
            'is_connected' => $gateway->isUsable(),
            'environment' => $gateway->environment,
            'connected_at' => $gateway->connected_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Comprobar que las llaves sirven, sin cobrarle a nadie.
     *
     * Existe porque conectar no daba ninguna señal: el comerciante guardaba
     * unas llaves equivocadas, veia "Conectado" -- que solo significa "hay
     * algo guardado" -- y se enteraba del error con el primer comprador que
     * no pudo pagar.
     *
     * Se pide un link de pago de prueba por un monto simbolico y NO se le
     * muestra a nadie: lo unico que interesa es si el proveedor lo acepta.
     * Es la unica forma de probar unas llaves de verdad; un endpoint de
     * "validar credenciales" no existe en ninguna de las dos pasarelas.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $business = $request->user()->business;
        $gateway = BusinessPaymentGateway::query()->where('provider_slug', $provider)->first();

        if ($gateway === null || ! $gateway->isUsable()) {
            return response()->json([
                'ok' => false,
                'message' => 'Todavía no has conectado esta pasarela.',
            ], 422);
        }

        try {
            app(PaymentsCoreService::class)->usingGateway($gateway)->createPaymentLink(
                amountCop: 1000,
                description: 'Prueba de conexión de '.($business->name ?? 'tu negocio'),
                customer: ['email' => (string) $request->user()->email],
                redirectUrl: rtrim((string) config('app.storefront_url'), '/'),
                metadata: ['test' => true],
                expiresInMinutes: 5,
            );
        } catch (\Throwable $e) {
            Log::warning('payment_gateway.test_failed', [
                'business_id' => $business->id,
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                // El mensaje del proveedor tal cual: "llave invalida" o
                // "comercio inactivo" le dice que corregir. Uno generico no.
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $gateway->environment === 'sandbox'
                ? 'Tus llaves de pruebas funcionan. Recuerda que en pruebas no entra dinero real.'
                : '¡Listo! Tus llaves funcionan y puedes cobrar.',
        ]);
    }

    public function destroy(string $provider): JsonResponse
    {
        $gateway = BusinessPaymentGateway::where('provider_slug', $provider)->firstOrFail();
        $this->gateways->disconnect($gateway);

        return response()->json(['provider_slug' => $provider, 'is_connected' => false]);
    }
}
