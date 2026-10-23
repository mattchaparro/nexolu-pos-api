<?php

namespace App\Support;

use Sentry\Tracing\SamplingContext;

/**
 * Que porcentaje de trazas se guarda, segun que sea.
 *
 * Antes era un 20% parejo para todo: las tareas programadas (cada 5 min,
 * 1-3 s cada una) llenaban Explore > Traces de ruido y gastaban cuota, y los
 * flujos del mostrador valian lo mismo que una pantalla de configuracion.
 *
 * Las metricas de RecordApiMetrics no dependen de esto (cuentan todo); esto
 * solo decide de cuantas peticiones se guarda el detalle.
 */
final class SentryTracesSampler
{
    /**
     * Lo que se usa con el cliente esperando en el mostrador. Se compara con
     * el path (al muestrear todavia no hay ruta resuelta).
     */
    private const COUNTER_PATHS = [
        '#^/api/v1/products/(sellable|frequent)$#',
        '#^/api/v1/open-tabs#',
        '#^/api/v1/sales$#',
        '#^/api/v1/cash-(closings|shifts)#',
        '#^/api/v1/dashboard/summary$#',
    ];

    public static function sample(SamplingContext $context): float
    {
        // Si el front ya decidio (la traza viene del navegador), se respeta:
        // partir una traza a la mitad deja el lado del navegador sin el del
        // servidor, que es justo lo que se quiere ver junto.
        $parentSampled = $context->getParentSampled();
        if ($parentSampled !== null) {
            return $parentSampled ? 1.0 : 0.0;
        }

        $transaction = $context->getTransactionContext();
        $op = $transaction?->getOp();
        $name = (string) $transaction?->getName();
        $default = (float) config('sentry.traces_sample_rate', 0.2);

        if ($op === 'http.server' && $name === '/up') {
            return 0.0;
        }

        if ($op === 'console.command.scheduled') {
            return min($default, 0.05);
        }

        if ($op === 'http.server') {
            foreach (self::COUNTER_PATHS as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return max($default, 0.5);
                }
            }
        }

        return $default;
    }
}
