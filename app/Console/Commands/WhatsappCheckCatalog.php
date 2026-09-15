<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\NexoluCommsCatalogClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Resuelve los items `pending` del catalogo contra Meta (el items_batch es
 * asincrono: Connect guarda el handle y este check pregunta en que quedo).
 * Pensado para el scheduler (cada ~15 min) o a mano tras un sync grande.
 */
#[Signature('whatsapp:check-catalog')]
#[Description('Verifica contra Meta los lotes de catalogo pendientes (via Nexolu Connect)')]
class WhatsappCheckCatalog extends Command
{
    public function handle(NexoluCommsCatalogClient $catalog): int
    {
        // Sin Connect configurado no hay nada que verificar: eso es exito,
        // no fallo - este comando corre cada 15 min en el scheduler y un
        // FAILURE perpetuo ensuciaria el monitoreo de cron jobs de los
        // ambientes donde el catalogo (aun) no aplica.
        if (! $catalog->isConfigured()) {
            $this->info('Connect no esta configurado; nada que verificar.');

            return self::SUCCESS;
        }

        $result = $catalog->check();

        if ($result === null) {
            // Connect configurado pero sin catalogo conectado (o caido):
            // tampoco es un fallo del cron - el detalle queda en el log del
            // cliente y el panel de Connect muestra el estado real.
            $this->info('Sin lotes que verificar (sin catalogo conectado, o Connect no respondio).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Catalogo: %d sincronizados, %d con error, %d aun en proceso.',
            $result['synced'] ?? 0,
            $result['errors'] ?? 0,
            $result['still_pending'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
