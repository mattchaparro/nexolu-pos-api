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
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Variante streaming de send(): mismo gate de permiso/cuota/contexto,
     * pero la respuesta se relayea byte a byte desde IA Core como
     * Server-Sent Events en vez de esperar el JSON completo. La cuota ya se
     * consume antes de llamar a IA Core (igual que send()), asi que este
     * proxy no necesita entender el formato SSE ni mirar el evento final -
     * es un cano tonto entre IA Core y el navegador.
     */
    public function stream(Request $request): JsonResponse|StreamedResponse
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

            $upstream = $this->aiChatService->stream(
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

        return response()->stream(function () use ($upstream) {
            set_time_limit(0);
            $body = $upstream->toPsrResponse()->getBody();

            while (! $body->eof()) {
                if (connection_aborted()) {
                    break;
                }

                echo $body->read(1024);

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
