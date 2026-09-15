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
        $result = $catalog->check();

        if ($result === null) {
            $this->warn('Sin resultado (Connect no configurado o inalcanzable).');

            return self::FAILURE;
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
