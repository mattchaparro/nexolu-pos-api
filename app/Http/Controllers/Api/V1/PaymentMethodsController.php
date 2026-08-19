<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentsCoreService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Catalogo de metodos de pago habilitados y bancos PSE, proxeado desde
 * Nexolu Payments Core (repo Python aparte) - ver
 * docs/PLAN_METODOS_PAGO_ALTERNOS.md secciones 4.5 y 5.4. No es especifico
 * de suscripcion: el mismo catalogo aplica a cualquier checkout que use
 * flow="api" (packs de mensajes de IA, futuros checkouts).
 *
 * El frontend no puede llamar al Core directo para esto: requiere el
 * api_key de la integracion, un secreto de plataforma que nunca debe llegar
 * al navegador (a diferencia de la public_key de Wompi, esa si es segura de
 * exponer y ya se usa directo desde el frontend para tokenizar tarjetas).
 */
class PaymentMethodsController extends Controller
{
    public function __construct(private PaymentsCoreService $paymentsCore) {}

    public function index(): JsonResponse
    {
        try {
            $result = $this->paymentsCore->paymentMethods();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }

    public function pseFinancialInstitutions(): JsonResponse
    {
        try {
            $result = $this->paymentsCore->pseFinancialInstitutions();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
