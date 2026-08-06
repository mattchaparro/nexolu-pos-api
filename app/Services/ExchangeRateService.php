<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve a cuanto estuvo el dolar cada dia, para poder leer en pesos un
 * costo que se paga en dolares (IA y WhatsApp).
 *
 * Fuente primaria: la TRM oficial de la Superintendencia Financiera publicada
 * en datos.gov.co. Es la tasa que Colombia usa para efectos contables, asi
 * que es la correcta para un reporte de costos, no una tasa de mercado
 * cualquiera. Si esa falla se cae a una tasa de mercado (open.er-api.com):
 * tener una tasa aproximada de hoy es mucho mejor que arrastrar la de la
 * semana pasada.
 */
class ExchangeRateService
{
    public const SOURCE_TRM = 'trm_superfinanciera';

    public const SOURCE_MARKET = 'open_er_api';

    public const SOURCE_FALLBACK = 'ultima_conocida';

    /**
     * Tasa con la que se debe valorar un gasto ocurrido en $date.
     *
     * Si ese dia no quedo tasa (el comando no corrio, o la API estaba caida),
     * se usa la ultima ANTERIOR conocida, nunca una posterior: valorar el
     * gasto del lunes con la tasa del viernes siguiente seria justo el error
     * que esta tabla existe para evitar.
     *
     * Devuelve null si no hay ninguna tasa registrada hasta esa fecha, y en
     * ese caso quien llama decide que hacer - no se inventa un numero, que
     * en un reporte de costos es peor que no mostrar nada.
     */
    public function rateForDate(CarbonImmutable|string $date): ?float
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        $rate = ExchangeRate::query()
            ->whereDate('date', '<=', $date)
            ->orderByDesc('date')
            ->value('usd_cop');

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * Consulta la tasa de hoy y la guarda. Idempotente: correrlo dos veces el
     * mismo dia actualiza la fila en vez de duplicarla.
     *
     * @return array{ok: bool, rate: ?float, source: string}
     */
    public function fetchAndStoreToday(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now();
        $date = $today->toDateString();

        [$rate, $source] = $this->fetchTrm($date);

        if ($rate === null) {
            [$rate, $source] = $this->fetchMarket();
        }

        if ($rate === null) {
            Log::warning('TRM: ninguna fuente respondio, se conserva la ultima conocida', [
                'date' => $date,
                'last_known' => $this->rateForDate($today),
            ]);

            return ['ok' => false, 'rate' => $this->rateForDate($today), 'source' => self::SOURCE_FALLBACK];
        }

        ExchangeRate::updateOrCreate(
            ['date' => $date],
            ['usd_cop' => $rate, 'source' => $source, 'fetched_at' => $today],
        );

        return ['ok' => true, 'rate' => $rate, 'source' => $source];
    }

    /**
     * TRM oficial (Superintendencia Financiera, via datos.gov.co).
     *
     * Se pide la vigente para la fecha dada en vez de "la ultima": la TRM se
     * publica el dia habil anterior y rige un dia concreto, asi que filtrar
     * por vigencia es lo que da la tasa que de verdad aplica hoy.
     *
     * @return array{0: ?float, 1: string}
     */
    private function fetchTrm(string $date): array
    {
        try {
            $response = Http::timeout(15)->get('https://www.datos.gov.co/resource/32sa-8pi3.json', [
                '$where' => "vigenciadesde <= '{$date}T00:00:00.000' AND vigenciahasta >= '{$date}T00:00:00.000'",
                '$limit' => 1,
            ]);

            if ($response->failed()) {
                return [null, self::SOURCE_TRM];
            }

            return [$this->normalize($response->json('0.valor')), self::SOURCE_TRM];
        } catch (\Throwable $e) {
            Log::warning('TRM: fallo la fuente oficial', ['error' => $e->getMessage()]);

            return [null, self::SOURCE_TRM];
        }
    }

    /**
     * Respaldo de mercado. No es la tasa oficial, pero una aproximacion de
     * hoy describe el costo real mucho mejor que la TRM de hace una semana.
     *
     * @return array{0: ?float, 1: string}
     */
    private function fetchMarket(): array
    {
        try {
            $response = Http::timeout(15)->get('https://open.er-api.com/v6/latest/USD');

            if ($response->failed() || $response->json('result') !== 'success') {
                return [null, self::SOURCE_MARKET];
            }

            return [$this->normalize($response->json('rates.COP')), self::SOURCE_MARKET];
        } catch (\Throwable $e) {
            Log::warning('TRM: fallo la fuente de respaldo', ['error' => $e->getMessage()]);

            return [null, self::SOURCE_MARKET];
        }
    }

    /**
     * Un valor solo se acepta si es un numero plausible para USD/COP. Sin
     * este filtro, una respuesta rara (null, 0, un string vacio, o la API
     * devolviendo otra moneda) entraria como tasa del dia y distorsionaria
     * todo el reporte de costos sin que nadie lo note.
     */
    private function normalize(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $rate = (float) $value;

        return ($rate >= 500 && $rate <= 50000) ? $rate : null;
    }
}
