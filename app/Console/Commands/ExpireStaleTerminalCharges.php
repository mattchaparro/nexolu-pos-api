<?php

namespace App\Console\Commands;

use App\Services\TerminalChargeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cierra los cobros de datafono que nadie resolvio.
 *
 * Pasa cuando el cajero abandona la venta o el aparato nunca respondio. Sin
 * esto quedan "esperando al cliente" para siempre y aparecen en la caja del
 * dia siguiente como si el cobro siguiera vivo.
 */
#[Signature('terminals:expire-stale')]
#[Description('Vence los cobros de datafono que quedaron esperando')]
class ExpireStaleTerminalCharges extends Command
{
    public function handle(TerminalChargeService $charges): int
    {
        $count = $charges->expireStale();
        $this->info("Cobros de datafono vencidos: {$count}");

        return self::SUCCESS;
    }
}
