<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AiChatBlockedException;
use App\Exceptions\AiSubscriptionExpiredException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Ai\Contracts\AiInsightDefinition;
use App\Services\Ai\Contracts\HasSuggestedAction;
use App\Services\Ai\InsightCatalog;
use App\Services\AiInsightService;
use App\Support\AiTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Insights embebidos para el dashboard: una tarjeta por tipo, redactada con
 * IA a partir de numeros calculados en PHP (ver InsightCatalog). Mismo
 * gate de permiso que el chat (`ai_chat.use`): es el mismo servicio de IA,
 * solo una forma de consumo distinta.
 */
class AiInsightController extends Controller
{
    public function __construct(private AiInsightService $insights) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request);

        try {
            $context = AiTenantContext::forUser($user);
        } catch (AiChatBlockedException|AiSubscriptionExpiredException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        // Filtro por tipo: el dashboard embebe UNA tarjeta, y sin esto
        // pintarla generaria los siete insights -- seis llamadas de IA
        // (con su costo real, ver ai_usage_daily.cost_micros) para tarjetas
        // que nadie va a ver. Un tipo desconocido devuelve lista vacia, no
        // error: la tarjeta se oculta sola y no rompe la pantalla que la
        // embebe.
        $catalog = InsightCatalog::all();

        if ($type = $request->string('type')->toString()) {
            $catalog = array_filter($catalog, fn (string $key) => $key === $type, ARRAY_FILTER_USE_KEY);
        }

        $data = collect($catalog)
            ->map(fn (AiInsightDefinition $definition) => $this->buildEntry($user->business, $definition, $context))
            ->filter()
            ->values();

        return response()->json(['data' => $data]);
    }

    public function refresh(Request $request, string $type): JsonResponse
    {
        $user = $this->authorizedUser($request);

        $definition = InsightCatalog::find($type);
        if (! $definition) {
            abort(404, 'Tipo de insight desconocido.');
        }

        try {
            $entry = $this->buildEntry($user->business, $definition, AiTenantContext::forUser($user), forceRefresh: true);
        } catch (AiChatBlockedException|AiSubscriptionExpiredException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $entry]);
    }

    /** @param  array<string, mixed>  $context */
    private function buildEntry(Business $business, AiInsightDefinition $definition, array $context, bool $forceRefresh = false): ?array
    {
        $result = $this->insights->get($business, $definition, $context, $forceRefresh);
        if ($result === null) {
            return null;
        }

        return [
            'type' => $definition->type(),
            'text' => $result['text'],
            'generated_at' => $result['generated_at'],
            'from_cache' => $result['from_cache'],
            'suggested_question' => $definition->suggestedQuestion(),
            'suggested_action' => $definition instanceof HasSuggestedAction
                ? $definition->suggestedAction($result['data'])
                : null,
        ];
    }

    private function authorizedUser(Request $request)
    {
        $user = $request->user();

        if (! $user->hasRole('admin') && ! $user->hasPermissionTo('ai_chat.use', 'web')) {
            throw ValidationException::withMessages(['insight' => 'No tienes permiso para usar el Asistente de IA.']);
        }

        return $user;
    }
}
