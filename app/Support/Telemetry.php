<?php

namespace App\Support;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Unit;
use Throwable;

use function Sentry\traceMetrics;

/**
 * Visibilidad de los flujos pesados del POS en Sentry, sin ensuciar el codigo
 * de negocio.
 *
 * Dos herramientas distintas, para dos preguntas distintas:
 *
 * - span(): "en que se fue el tiempo de ESTA peticion". Aparece dentro de la
 *   traza (Explore > Traces) con sus datos (cuantos productos, si vino del
 *   cache...). Las trazas se muestrean (SENTRY_TRACES_SAMPLE_RATE), asi que
 *   sirven para diagnosticar, no para contar.
 * - distribution()/count(): "como se comporta esto en el tiempo y por
 *   negocio". Las metricas NO se muestrean: cada peticion suma, y se pueden
 *   graficar y alertar por endpoint o por negocio (Explore > Metrics).
 *
 * Todo es no-op si Sentry no esta configurado (local, tests): sin DSN no hay
 * cliente ni span activo, y aqui no se paga nada mas que un if.
 *
 * Convencion de nombres: `op` y metricas en minusculas con puntos
 * (catalog.load, sale.create); atributos con el mismo prefijo del flujo.
 */
final class Telemetry
{
    /**
     * Ejecuta $callback dentro de un span hijo del span activo y devuelve lo
     * que devuelva. Si $callback recibe un parametro, le llega el span (o
     * null sin tracing) para agregarle datos que solo se conocen al final.
     *
     * @template T
     *
     * @param  callable(?Span): T  $callback
     * @param  array<string, int|float|string|bool|null>  $data
     * @return T
     */
    public static function span(string $op, string $description, callable $callback, array $data = []): mixed
    {
        $hub = SentrySdk::getCurrentHub();
        $parent = $hub->getSpan();

        if ($parent === null) {
            return $callback(null);
        }

        $context = SpanContext::make()
            ->setOp($op)
            ->setDescription($description)
            ->setOrigin('auto.function.nexolu')
            ->setData(array_filter($data, fn ($value) => $value !== null));

        $span = $parent->startChild($context);
        $hub->setSpan($span);

        try {
            $result = $callback($span);
            $span->setStatus(SpanStatus::ok());

            return $result;
        } catch (Throwable $error) {
            $span->setStatus(SpanStatus::internalError());
            $span->setData(['error.type' => $error::class]);

            throw $error;
        } finally {
            $span->finish();
            $hub->setSpan($parent);
        }
    }

    /**
     * Agrega datos al span activo (el de span() en curso, o la transaccion de
     * la peticion). Para lo que se averigua en medio del flujo, ej. si el
     * catalogo salio del cache.
     *
     * @param  array<string, int|float|string|bool|null>  $data
     */
    public static function annotate(array $data): void
    {
        SentrySdk::getCurrentHub()->getSpan()?->setData(array_filter($data, fn ($value) => $value !== null));
    }

    /**
     * Valor medido (duracion, tamaño). Con $unit 'millisecond' se grafica
     * como tiempo; sin unidad, como numero (productos, items).
     *
     * @param  array<string, int|float|string|bool>  $attributes
     */
    public static function distribution(string $name, int|float $value, array $attributes = [], ?string $unit = null): void
    {
        if (! self::enabled()) {
            return;
        }

        traceMetrics()->distribution($name, $value, $attributes, self::unit($unit));
    }

    /**
     * Ocurrencia de algo (una venta, un cierre, un fallo controlado).
     *
     * @param  array<string, int|float|string|bool>  $attributes
     */
    public static function count(string $name, int|float $value = 1, array $attributes = []): void
    {
        if (! self::enabled()) {
            return;
        }

        traceMetrics()->count($name, $value, $attributes);
    }

    public static function enabled(): bool
    {
        return SentrySdk::getCurrentHub()->getClient() !== null;
    }

    private static function unit(?string $unit): ?Unit
    {
        return match ($unit) {
            'millisecond' => Unit::millisecond(),
            default => null,
        };
    }
}
