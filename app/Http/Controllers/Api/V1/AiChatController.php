<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AiChatBlockedException;
use App\Exceptions\AiQuotaExceededException;
use App\Exceptions\AiSubscriptionExpiredException;
use App\Http\Controllers\Controller;
use App\Services\AiChatService;
use App\Services\AiQuotaService;
use App\Support\AiTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Unico punto de entrada saliente hacia el Nexolu IA Core: recibe el mensaje
 * de un usuario autenticado (Sanctum), arma el TenantContext a partir de lo
 * que YA sabe Laravel de el (negocio, rol, permisos efectivos, features
 * habilitados) y proxea la llamada. El IA Core nunca ve la sesion del
 * usuario, solo esta afirmacion firmada con la API key de la aplicacion -
 * ver App\Services\AiChatService.
 */
class AiChatController extends Controller
{
    public function __construct(private AiChatService $aiChatService, private AiQuotaService $quota) {}

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('admin') && ! $user->hasPermissionTo('ai_chat.use', 'web')) {
            throw ValidationException::withMessages(['agent' => 'No tienes permiso para usar el Asistente de IA.']);
        }

        $validated = $request->validate([
            'agent' => ['required', 'string'],
            'message' => ['required', 'string', 'max:1000'],
            'conversation_id' => ['sometimes', 'nullable', 'string'],
        ]);

        try {
            $context = AiTenantContext::forUser($user);
            $this->quota->consumeMessage($user->business, $user);

            $result = $this->aiChatService->send(
                $validated['agent'],
                $validated['message'],
                $context,
                $validated['conversation_id'] ?? null
            );
        } catch (AiChatBlockedException|AiSubscriptionExpiredException|AiQuotaExceededException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
