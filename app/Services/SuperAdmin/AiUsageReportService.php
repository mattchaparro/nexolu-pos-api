<?php

namespace App\Services\SuperAdmin;

use App\Models\AiUnansweredQuestion;
use App\Models\AiUsageDaily;
use App\Models\Business;
use App\Support\AiQuotaSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reporte de uso del Asistente de IA para el panel de SuperAdmin.
 *
 * De donde sale cada cifra, que no es obvio y equivocarse cambia el sentido:
 *
 * - Los MENSAJES salen de `ai_usage_daily`, que es contra lo que el POS
 *   descuenta el cupo del negocio (ver AiQuotaService). Es la verdad de
 *   "cuanto uso" y de lo que se factura.
 * - El COSTO sale del IA Core, que es quien llama al proveedor del modelo y
 *   el unico que sabe cuantos tokens costo. Las columnas de tokens y costo de
 *   `ai_usage_daily` existen (vienen del esquema del legacy, donde el POS si
 *   hacia la llamada) pero en esta arquitectura NADIE las escribe: siempre
 *   valen 0. Leerlas y presentarlas como costo mostraria "$0" para todos los
 *   negocios, que se lee como "la IA no cuesta nada" en vez de como "este
 *   dato no vive aca".
 *
 * Si el IA Core no responde, el costo va en null y la pantalla lo dice. Un
 * costo desconocido nunca se presenta como cero.
 */
class AiUsageReportService
{
    /** Cuantos negocios lista la tabla de uso. */
    private const MAX_BUSINESSES = 100;

    /**
     * Totales de mensajes por periodo mas la economia del addon.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = Carbon::today();

        $aggregate = fn (?string $from) => AiUsageDaily::query()
            ->when($from !== null, fn ($query) => $query->where('date', '>=', $from))
            ->selectRaw('COALESCE(SUM(messages_count), 0) as messages')
            ->selectRaw('COUNT(DISTINCT business_id) as businesses')
            ->first();

        $todayRow = $aggregate($today->toDateString());
        $monthRow = $aggregate($today->copy()->startOfMonth()->toDateString());
        $totalRow = $aggregate(null);

        $monthCost = $this->costUsdBetween($today->copy()->startOfMonth(), $today);
        $monthMessages = (int) $monthRow->messages;

        return [
            'today' => ['messages' => (int) $todayRow->messages, 'businesses' => (int) $todayRow->businesses],
            'month' => ['messages' => $monthMessages, 'businesses' => (int) $monthRow->businesses],
            'total' => ['messages' => (int) $totalRow->messages, 'businesses' => (int) $totalRow->businesses],
            'month_cost_usd' => $monthCost,
            // Costo unitario real: es el numero sobre el que se fija el precio
            // del pack. Null (no cero) si no se pudo consultar el IA Core.
            'cost_per_message_usd' => ($monthCost !== null && $monthMessages > 0)
                ? round($monthCost / $monthMessages, 6)
                : null,
            'monthly_included_messages' => AiQuotaSettings::monthlyIncludedMessages(),
            'pack_size' => AiQuotaSettings::packSize(),
            'pack_price_cop' => AiQuotaSettings::packPriceCop(),
            // Lo que cuesta atender un pack completo contra lo que se cobra
            // por el: es la cifra que dice si el pack deja margen.
            'pack_cost_usd' => ($monthCost !== null && $monthMessages > 0)
                ? round(($monthCost / $monthMessages) * AiQuotaSettings::packSize(), 4)
                : null,
        ];
    }

    /**
     * Negocios ordenados por uso del mes en curso, con su costo real.
     *
     * @return list<array<string, mixed>>
     */
    public function perBusiness(): array
    {
        $from = Carbon::today()->startOfMonth();

        $rows = AiUsageDaily::query()
            ->where('date', '>=', $from->toDateString())
            ->groupBy('business_id')
            ->selectRaw('business_id')
            ->selectRaw('SUM(messages_count) as messages')
            ->selectRaw('MAX(date) as last_used_on')
            ->orderByDesc('messages')
            ->limit(self::MAX_BUSINESSES)
            ->get();

        $businesses = Business::withTrashed()
            ->whereIn('id', $rows->pluck('business_id'))
            ->get(['id', 'name', 'subscription_plan', 'ai_chat_blocked', 'ai_chat_billable', 'ai_message_pack_balance'])
            ->keyBy('id');

        $costByBusiness = $this->costUsdPerBusinessBetween($from, Carbon::today());
        $monthlyQuota = AiQuotaSettings::monthlyIncludedMessages();

        return $rows->map(function ($row) use ($businesses, $costByBusiness, $monthlyQuota) {
            $business = $businesses->get($row->business_id);
            $messages = (int) $row->messages;

            return [
                'business_id' => (int) $row->business_id,
                'name' => $business?->name ?? 'Negocio eliminado',
                'plan' => $business?->subscription_plan,
                'state' => match (true) {
                    (bool) $business?->ai_chat_blocked => 'bloqueado',
                    (bool) $business?->ai_chat_billable => 'contratado',
                    default => 'incluido',
                },
                'messages' => $messages,
                'monthly_quota' => $monthlyQuota,
                'quota_used_pct' => $monthlyQuota > 0 ? round(($messages / $monthlyQuota) * 100, 1) : null,
                'pack_balance' => (int) ($business?->ai_message_pack_balance ?? 0),
                // null = el IA Core no respondio, no "no gasto nada".
                'cost_usd' => $costByBusiness === null ? null : ($costByBusiness[(string) $row->business_id] ?? 0.0),
                'last_used_on' => $row->last_used_on,
            ];
        })->values()->all();
    }

    /**
     * Preguntas que el Asistente no supo responder, agrupadas por texto para
     * que la misma necesidad repetida por varios negocios se vea como una
     * linea con su conteo - que es lo que permite priorizar.
     *
     * @return list<array<string, mixed>>
     */
    public function unansweredQuestions(int $limit = 40, bool $includeReviewed = false): array
    {
        return AiUnansweredQuestion::query()
            ->withoutGlobalScopes()
            ->when(! $includeReviewed, fn ($query) => $query->where('revisada', false))
            ->groupByRaw('LOWER(pregunta)')
            ->selectRaw('MIN(id) as id')
            ->selectRaw('MAX(pregunta) as pregunta')
            ->selectRaw('MAX(respuesta) as respuesta')
            ->selectRaw('COUNT(*) as times')
            ->selectRaw('COUNT(DISTINCT business_id) as businesses')
            ->selectRaw('MAX(created_at) as last_seen_at')
            ->orderByDesc('times')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'question' => (string) $row->pregunta,
                'answer' => $row->respuesta,
                'times' => (int) $row->times,
                'businesses' => (int) $row->businesses,
                'last_seen_at' => $row->last_seen_at,
            ])->values()->all();
    }

    /**
     * Marca como revisadas TODAS las filas con ese mismo texto, no solo la
     * que se clickeo: la pantalla agrupa por texto, asi que marcar una sola
     * dejaria la linea ahi con el conteo bajado en uno - que se lee como si
     * no hubiera funcionado.
     */
    public function markQuestionReviewed(int $id): int
    {
        $question = AiUnansweredQuestion::withoutGlobalScopes()->find($id);

        if (! $question) {
            return 0;
        }

        return AiUnansweredQuestion::withoutGlobalScopes()
            ->whereRaw('LOWER(pregunta) = ?', [mb_strtolower($question->pregunta)])
            ->update(['revisada' => true]);
    }

    private function costUsdBetween(Carbon $from, Carbon $to): ?float
    {
        $usage = $this->fetchUsage($from, $to);

        return $usage === null ? null : (float) ($usage['summary']['cost_usd'] ?? 0);
    }

    /**
     * @return array<string, float>|null business_id => costo USD, o null si
     *                                   no se pudo consultar
     */
    private function costUsdPerBusinessBetween(Carbon $from, Carbon $to): ?array
    {
        $usage = $this->fetchUsage($from, $to);

        if ($usage === null) {
            return null;
        }

        $byBusiness = [];
        foreach ($usage['by_business'] ?? [] as $row) {
            $byBusiness[(string) ($row['key'] ?? '')] = (float) ($row['cost_usd'] ?? 0);
        }

        return $byBusiness;
    }

    /**
     * GET /v1/usage/summary del IA Core con la API key de ESTA app (no la de
     * plataforma): devuelve el gasto del POS desglosado por negocio.
     *
     * Nunca lanza. Que el IA Core este caido no puede tumbar el panel: el
     * resto del reporte (mensajes, preguntas sin responder) sale de la base
     * local y sigue sirviendo.
     *
     * @return array<string, mixed>|null
     */
    private function fetchUsage(Carbon $from, Carbon $to): ?array
    {
        $baseUrl = config('services.ia_core.base_url');
        $apiKey = config('services.ia_core.api_key');

        if (! $baseUrl || ! $apiKey) {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->get(rtrim($baseUrl, '/').'/v1/usage/summary', [
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('ai_usage_report: no se pudo contactar al IA Core', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('ai_usage_report: el IA Core rechazo la consulta', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
