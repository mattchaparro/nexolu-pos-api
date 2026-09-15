<?php

namespace App\Console\Commands;

use App\Jobs\SyncWhatsAppCatalogJob;
use App\Models\Business;
use App\Services\WhatsApp\NexoluCommsCatalogClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sync inicial y reconciliacion del catalogo de WhatsApp: manda el lote
 * completo de cada negocio (o de uno) a Nexolu Connect. Los guardados del
 * dia a dia ya disparan el sync solos (hook en Product); esto es para el
 * arranque de un negocio y para reconciliar despues de un incidente.
 */
#[Signature('whatsapp:sync-catalog {--business_id= : Sincronizar solo un negocio}')]
#[Description('Sincroniza el catalogo de WhatsApp (productos con available_on_whatsapp) via Nexolu Connect')]
class WhatsappSyncCatalog extends Command
{
    public function handle(NexoluCommsCatalogClient $catalog): int
    {
        if (! $catalog->isConfigured()) {
            $this->error('Connect no esta configurado (COMMS_CORE_API_KEY / COMMS_CORE_BASE_URL).');

            return self::FAILURE;
        }

        $query = Business::query();
        if ($this->option('business_id')) {
            $query->where('id', $this->option('business_id'));
        }

        $count = 0;
        foreach ($query->pluck('id') as $businessId) {
            SyncWhatsAppCatalogJob::dispatch((int) $businessId);
            $count++;
        }

        $this->info("Sync encolado para {$count} negocio(s).");

        return self::SUCCESS;
    }
}
