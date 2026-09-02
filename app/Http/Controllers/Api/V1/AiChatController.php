<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AiChatBlockedException;
use App\Exceptions\AiQuotaExceededException;
use App\Exceptions\AiSubscriptionExpiredException;
use App\Http\Controllers\Controller;
use App\Models\AiUnansweredQuestion;
use App\Models\User;
use App\Services\AiChatService;
use App\Services\AiQuotaService;
use App\Support\AiTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        if (! $user->hasBusinessPermission('ai_chat.use')) {
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

        $this->recordIfUnanswered($user, $validated['message'], $result);

        return response()->json($result);
    }

    /**
     * Variante streaming de send(): mismo gate de permiso/cuota/contexto,
     * pero la respuesta se relayea byte a byte desde IA Core como
     * Server-Sent Events en vez de esperar el JSON completo. La cuota ya se
     * consume antes de llamar a IA Core (igual que send()), asi que este
     * proxy no necesita entender el formato SSE para funcionar.
     *
     * Si mira el evento final, pero solo para una cosa: saber si la
     * respuesta uso alguna herramienta. Cuando no uso ninguna, la pregunta
     * queda registrada como sin responder (ver recordIfUnanswered) - es la
     * unica forma de enterarse de que a un dueño le falto un dato sin que
     * mande una captura de pantalla.
     */
    public function stream(Request $request): JsonResponse|StreamedResponse
    {
        $user = $request->user();

        if (! $user->hasBusinessPermission('ai_chat.use')) {
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

        return response()->stream(function () use ($upstream, $user, $validated) {
            set_time_limit(0);
            $body = $upstream->toPsrResponse()->getBody();
            $aborted = false;
            // Solo el ULTIMO evento interesa (el que trae done:true con
            // tools_used), pero llega partido en trozos de 1KB, asi que hay
            // que acumular. Es una respuesta de chat: cabe de sobra.
            $raw = '';

            while (! $body->eof()) {
                if (connection_aborted()) {
                    $aborted = true;
                    break;
                }

                $chunk = $body->read(1024);
                $raw .= $chunk;
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            // Si el navegador corto, no hay evento final que mirar y la
            // pregunta no se puede clasificar: registrarla seria inventar.
            if (! $aborted) {
                $this->recordIfUnanswered($user, $validated['message'], $this->finalEventOf($raw));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * El evento final del stream (`data: {...,"done":true,...}`), que es el
     * unico que trae tools_used y el texto completo.
     *
     * @return array<string, mixed>
     */
    private function finalEventOf(string $raw): array
    {
        foreach (array_reverse(preg_split('/\n\n/', trim($raw)) ?: []) as $event) {
            $json = json_decode(ltrim(trim($event), 'data:'), true);

            if (is_array($json) && ($json['done'] ?? false)) {
                return $json;
            }
        }

        return [];
    }

    /**
     * Registra la pregunta cuando el Asistente respondio sin usar ninguna
     * herramienta: o no existe una que sirva, o el modelo no la encontro.
     *
     * Sin esto, un hueco de cobertura solo se descubre si alguien manda una
     * captura de pantalla - fue exactamente lo que paso con el chat del
     * legacy, donde el unico rastro de "no tengo herramienta para crear
     * proveedores" fue esta misma tabla.
     *
     * Nunca interrumpe la respuesta: el usuario ya recibio su texto, y que
     * falle un insert de telemetria no puede convertirse en un error visible.
     *
     * @param  array<string, mixed>  $result
     */
    private function recordIfUnanswered(User $user, string $question, array $result): void
    {
        if ($result === [] || ($result['tools_used'] ?? []) !== []) {
            return;
        }

        try {
            AiUnansweredQuestion::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'pregunta' => $question,
                // El texto tal cual lo respondio el modelo: para entender por
                // que no uso una herramienta hace falta ver que contesto.
                'respuesta' => Str::limit((string) ($result['text'] ?? ''), 1000),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai_unanswered: no se pudo registrar la pregunta', ['error' => $e->getMessage()]);
        }
    }
}
