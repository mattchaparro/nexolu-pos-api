<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBusinessPaymentSourceRequest;
use App\Models\BusinessPaymentSource;
use App\Services\PaymentsCoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Metodos de pago guardados" del negocio -- tarjeta o Nequi ya tokenizados
 * para reuso via Nexolu Payments Core (Fuentes de Pago de Wompi, ver
 * docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 9). Distinto de
 * SubscriptionController: esto administra el "wallet" del negocio, no un
 * cobro puntual -- una vez guardada, la fuente se reusa desde
 * SubscriptionController::charge() con `{type: "PAYMENT_SOURCE", payment_source_id}`.
 */
class BusinessPaymentSourceController extends Controller
{
    public function __construct(private PaymentsCoreService $paymentsCore) {}

    public function index(Request $request): JsonResponse
    {
        $sources = BusinessPaymentSource::where('business_id', $request->user()->business_id)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->get(['id', 'payment_source_id', 'type', 'label', 'created_at']);

        return response()->json(['payment_sources' => $sources]);
    }

    /**
     * Tokeniza (via el Core, que a su vez habla con Wompi usando la llave
     * privada) y guarda la fuente resultante contra el negocio autenticado.
     * El token en `$request->token` ya lo genero el frontend hablando
     * DIRECTO con Wompi (llave publica) -- este backend nunca ve el numero
     * de tarjeta ni tokeniza nada el mismo.
     */
    public function store(StoreBusinessPaymentSourceRequest $request): JsonResponse
    {
        try {
            $result = $this->paymentsCore->createPaymentSource(
                $request->validated('type'),
                $request->validated('token'),
                $request->user()->email,
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $source = BusinessPaymentSource::create([
            'business_id' => $request->user()->business_id,
            'provider_slug' => 'wompi',
            'payment_source_id' => $result['payment_source_id'],
            'type' => $result['type'],
            'label' => $request->validated('label'),
            'status' => 'active',
        ]);

        return response()->json($source, 201);
    }

    /**
     * Soft-delete local UNICAMENTE -- Wompi no permite anular ("void") una
     * fuente de pago normal (confirmado en sandbox, ver el plan), asi que
     * esto solo deja de ofrecerla en la UI. La fila nunca se borra.
     */
    public function destroy(Request $request, BusinessPaymentSource $paymentSource): JsonResponse
    {
        if ($paymentSource->business_id !== $request->user()->business_id) {
            return response()->json(['error' => 'No encontrado.'], 404);
        }

        $paymentSource->update(['status' => 'removed']);

        return response()->json(['ok' => true]);
    }
}
