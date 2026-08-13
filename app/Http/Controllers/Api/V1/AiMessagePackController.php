<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InitiateAiMessagePackCheckoutRequest;
use App\Services\AiMessagePackCheckoutService;
use App\Services\AiQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Cupo de mensajes de IA (para la card de Ajustes) + compra self-serve de un
 * paquete adicional, mismo patron de checkout que SubscriptionController.
 */
class AiMessagePackController extends Controller
{
    public function __construct(
        private AiQuotaService $quota,
        private AiMessagePackCheckoutService $checkout,
    ) {}

    public function state(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->quota->state($user->business, $user));
    }

    public function initiate(InitiateAiMessagePackCheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->checkout->initiateCheckout(
                $request->user()->business,
                $request->user(),
                $request->validated('redirect_url'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($result, 201);
    }

    public function checkoutStatus(Request $request, string $reference): JsonResponse
    {
        return response()->json(
            $this->checkout->checkoutStatus($request->user()->business, $reference)
        );
    }
}
