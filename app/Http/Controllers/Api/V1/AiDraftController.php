<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AiChatBlockedException;
use App\Http\Controllers\Controller;
use App\Services\AiDraftService;
use App\Support\AiTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Proxy hacia la confirmacion humana de un borrador de escritura del chat de
 * IA (ver App\Services\AiDraftService). Mismo gate de permiso que el chat
 * (`ai_chat.use`): confirmar un borrador ES usar el asistente, no una accion
 * aparte.
 */
class AiDraftController extends Controller
{
    public function __construct(private AiDraftService $drafts) {}

    public function confirm(Request $request, string $draftId): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $values = $request->validate(['values' => ['sometimes', 'nullable', 'array']])['values'] ?? null;

        try {
            $response = $this->drafts->confirm($draftId, AiTenantContext::forUser($user), $values);
        } catch (AiChatBlockedException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($response->json(), $response->status());
    }

    public function discard(Request $request, string $draftId): JsonResponse
    {
        $user = $this->authorizedUser($request);

        try {
            $response = $this->drafts->discard($draftId, AiTenantContext::forUser($user));
        } catch (AiChatBlockedException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($response->json(), $response->status());
    }

    private function authorizedUser(Request $request)
    {
        $user = $request->user();

        if (! $user->hasRole('admin') && ! $user->hasPermissionTo('ai_chat.use', 'web')) {
            throw ValidationException::withMessages(['draft' => 'No tienes permiso para usar el Asistente de IA.']);
        }

        return $user;
    }
}
