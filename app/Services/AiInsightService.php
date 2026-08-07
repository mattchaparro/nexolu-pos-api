<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\Business;
use App\Services\Ai\Contracts\AiInsightDefinition;
use App\Services\Ai\Contracts\ValidatesGeneratedText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Genera y cachea los insights embebidos en pantallas.
 *
 * El patron es cache-aside con generacion perezosa: el primero que abre la
 * pantalla ese periodo dispara la generacion; todos los demas reciben el
 * texto cacheado gratis. Asi solo se paga por los negocios que de verdad usan
 * la pantalla.
 *
 * A diferencia del legacy, no hay cupo de mensajes ni add-on que verificar
 * aqui: el acceso a IA en esta API se resuelve una sola vez, al permiso
 * `ai_chat.use` (ver AiChatController), no por insight.
 */
class AiInsightService
{
    public function __construct(private AiCompletionService $completions) {}

    /**
     * Devuelve el insight vigente, generandolo si hace falta.
     *
     * @param  array<string, mixed>  $context  TenantContext para el IA Core (ver AiChatController::send())
     * @return array{text: string, generated_at: string, from_cache: bool, data: array<string, mixed>}|null
     *                                                                                                      null si el negocio no tiene la feature, no hay datos que valgan
     *                                                                                                      la pena, o la generacion fallo. Nunca lanza: un insight es un
     *                                                                                                      extra y jamas debe romper la pantalla que lo muestra.
     */
    public function get(Business $business, AiInsightDefinition $definition, array $context, bool $forceRefresh = false): ?array
    {
        $feature = $definition->requiredFeature();
        if ($feature !== null && ! $business->hasFeature($feature)) {
            return null;
        }

        $existing = AiInsight::query()
            ->where('business_id', $business->id)
            ->where('tipo', $definition->type())
            ->first();

        if (! $forceRefresh && $existing && $existing->isCurrent() && $existing->texto !== null) {
            return $this->fromRecord($existing, cached: true);
        }

        return $this->generate($business, $definition, $context, $existing);
    }

    /**
     * Marca vencido el insight de un tipo, para que se regenere a la
     * proxima. Gancho de invalidacion por evento: cuando entra un gasto se
     * invalida el resumen de gastos, cuando se registra un abono se invalida
     * el de fiados. Sin esto un "vas muy bien" cacheado sobreviviria a una
     * caida de ventas.
     */
    public function invalidate(int $businessId, string $type): void
    {
        AiInsight::query()
            ->where('business_id', $businessId)
            ->where('tipo', $type)
            ->update(['expira_en' => now()->subSecond()]);
    }

    /** @param  array<string, mixed>  $context */
    private function generate(Business $business, AiInsightDefinition $definition, array $context, ?AiInsight $existing): ?array
    {
        try {
            $data = $definition->gatherData($business->id);

            if (! $definition->isWorthShowing($data)) {
                return null;
            }

            $result = $this->completions->complete(
                $definition->systemPrompt(),
                $definition->userPrompt($data),
                $context,
                // Un insight es una o dos frases. Un techo bajo evita que el
                // modelo se extienda y de paso acota el costo de salida.
                200,
            );

            $text = trim((string) ($result['text'] ?? ''));
            if ($text === '') {
                return $this->fallbackOrNull($existing);
            }

            // Red de seguridad deterministica: un modelo puede redactar algo
            // que contradiga los datos que se le dieron, aunque el prompt se
            // los haya dado explicitos. Si pasa, no se cachea el texto malo.
            if ($definition instanceof ValidatesGeneratedText && ! $definition->isTextValid($text, $data)) {
                Log::warning('Insight de IA descartado: el texto contradice los datos', [
                    'business_id' => $business->id,
                    'type' => $definition->type(),
                    'text' => $text,
                ]);

                return $this->fallbackOrNull($existing);
            }

            $now = CarbonImmutable::now();

            $insight = AiInsight::query()->updateOrCreate(
                ['business_id' => $business->id, 'tipo' => $definition->type()],
                [
                    'texto' => $text,
                    'datos' => $data,
                    'input_tokens' => $result['input_tokens'] ?? 0,
                    'output_tokens' => $result['output_tokens'] ?? 0,
                    'cost_micros' => $result['cost_micros'] ?? 0,
                    'generado_en' => $now,
                    'expira_en' => $now->addMinutes($definition->ttlMinutes()),
                ],
            );

            return $this->fromRecord($insight, cached: false);
        } catch (\Throwable $e) {
            // Un insight que falla no puede tumbar la pantalla que lo
            // muestra. Se registra y se devuelve lo que hubiera cacheado, o nada.
            Log::warning('Fallo al generar insight de IA', [
                'business_id' => $business->id,
                'type' => $definition->type(),
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackOrNull($existing);
        }
    }

    private function fallbackOrNull(?AiInsight $existing): ?array
    {
        return $existing && $existing->texto !== null ? $this->fromRecord($existing, cached: true) : null;
    }

    /** @return array{text: string, generated_at: string, from_cache: bool, data: array<string, mixed>} */
    private function fromRecord(AiInsight $insight, bool $cached): array
    {
        return [
            'text' => $insight->texto,
            'generated_at' => $insight->generado_en?->toIso8601String() ?? '',
            'from_cache' => $cached,
            'data' => $insight->datos ?? [],
        ];
    }
}
