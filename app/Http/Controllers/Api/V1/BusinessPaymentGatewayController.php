<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessPaymentGateway;
use App\Services\BusinessPaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * La pasarela propia del negocio: conectarla, ver su estado, desconectarla.
 *
 * Nunca devuelve secretos. Las llaves del proveedor se mandan una vez, se
 * guardan cifradas en el Payments Core y desde aca no se pueden volver a
 * leer -- ni siquiera por el dueño. Si las pierde, las saca de nuevo del
 * panel de su proveedor.
 */
class BusinessPaymentGatewayController extends Controller
{
    public function __construct(private BusinessPaymentGatewayService $gateways) {}

    /**
     * Que credenciales pide cada proveedor. El frontend dibuja el formulario
     * desde aca en vez de tener los nombres escritos a mano.
     */
    private const CREDENTIAL_FIELDS = [
        BusinessPaymentGateway::PROVIDER_BOLD => ['identity_key', 'secret_key'],
        BusinessPaymentGateway::PROVIDER_WOMPI => ['public_key', 'private_key', 'integrity_secret', 'events_secret'],
    ];

    public function index(Request $request): JsonResponse
    {
        $connected = BusinessPaymentGateway::query()->get()
            ->keyBy('provider_slug');

        $providers = collect(BusinessPaymentGateway::PROVIDERS)->map(function (string $slug) use ($connected) {
            $gateway = $connected->get($slug);

            return [
                'provider_slug' => $slug,
                'credential_fields' => self::CREDENTIAL_FIELDS[$slug] ?? [],
                'is_connected' => $gateway !== null && $gateway->isUsable(),
                'is_active' => (bool) $gateway?->is_active,
                'environment' => $gateway?->environment,
                'connected_at' => $gateway?->connected_at?->toIso8601String(),
                'last_error' => $gateway?->last_error,
            ];
        })->values();

        return response()->json(['providers' => $providers]);
    }

    public function store(Request $request): JsonResponse
    {
        $provider = (string) $request->input('provider_slug');
        $fields = self::CREDENTIAL_FIELDS[$provider] ?? [];

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
            $rules["credentials.{$field}"] = ['present', 'nullable', 'string', 'max:500'];
        }
        $data = $request->validate($rules);

        try {
            $gateway = $this->gateways->connect(
                $request->user()->business,
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

    public function destroy(string $provider): JsonResponse
    {
        $gateway = BusinessPaymentGateway::where('provider_slug', $provider)->firstOrFail();
        $this->gateways->disconnect($gateway);

        return response()->json(['provider_slug' => $provider, 'is_connected' => false]);
    }
}
