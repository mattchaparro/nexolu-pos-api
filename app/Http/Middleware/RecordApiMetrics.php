<?php

namespace App\Http\Middleware;

use App\Support\Telemetry;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sentry\SentrySdk;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una metrica por peticion del API: cuanto tardo, cuantas consultas hizo y
 * cuanto tiempo paso en MySQL, por endpoint y por negocio.
 *
 * Existe porque las trazas se muestrean (20%) y no sirven para responder
 * "¿Central Cell esta mas lento hoy que ayer?" o "¿que endpoint hace mas
 * consultas?". Las metricas cuentan todas las peticiones. Ademas deja el
 * total de consultas en la transaccion, para buscar en Explore > Traces las
 * peticiones con db.query_count alto (la huella de un N+1).
 *
 * Va primero en el grupo `api` para que la duracion incluya autenticacion,
 * sede y serializacion de la respuesta.
 */
class RecordApiMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Telemetry::enabled()) {
            return $next($request);
        }

        $queries = 0;
        $dbTimeMs = 0.0;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$dbTimeMs) {
            $queries++;
            $dbTimeMs += $query->time;
        });

        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $response = $next($request);
        $durationMs = (microtime(true) - $start) * 1000;

        $route = $request->route();
        $user = $request->user();
        $attributes = [
            // La plantilla de la ruta (api/v1/open-tabs/{sale}/items), no la
            // URL real: con ids en el nombre cada cuenta seria una serie.
            'route' => $route?->uri() ?? 'unmatched',
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'business_id' => (string) ($user?->business_id ?? 'none'),
        ];

        Telemetry::distribution('api.request.duration', round($durationMs, 1), $attributes, 'millisecond');
        Telemetry::distribution('api.request.db_queries', $queries, $attributes);
        Telemetry::distribution('api.request.db_time', round($dbTimeMs, 1), $attributes, 'millisecond');

        SentrySdk::getCurrentHub()->getTransaction()?->setData([
            'db.query_count' => $queries,
            'db.time_ms' => round($dbTimeMs, 1),
        ]);

        return $response;
    }
}
