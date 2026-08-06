<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Guarda la tasa del dolar del dia.
 *
 * Corre temprano en la manana para que todo gasto del dia (IA y WhatsApp) se
 * pueda valorar con la tasa de ESE dia (ver PlatformFinanceService) - sin
 * esto, el costo historico se recalcularia solo cada vez que se mueve el
 * dolar y el margen del mes pasado cambiaria sin que nadie tocara nada.
 */
#[Signature('exchange-rate:fetch')]
#[Description('Consulta la TRM del dia y la guarda para valorar los costos en pesos')]
class ExchangeRateFetch extends Command
{
    public function handle(ExchangeRateService $service): int
    {
        $result = $service->fetchAndStoreToday();

        if (! $result['ok']) {
            $this->warn('No se pudo obtener la tasa del dia; se conserva la ultima conocida ('
                .($result['rate'] !== null ? number_format($result['rate'], 2) : 'ninguna').')');

            // Exito de proceso: no hay nada que reparar y el reporte sigue
            // funcionando con la ultima tasa. Fallar aqui llenaria el monitor
            // de alertas rojas por una API de terceros caida un rato.
            return self::SUCCESS;
        }

        $this->info('Tasa del dia: '.number_format($result['rate'], 2).' COP/USD ('.$result['source'].')');

        return self::SUCCESS;
    }
}
