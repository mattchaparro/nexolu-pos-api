<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\StoreCart;
use App\Services\AbandonedCartReminder;
use Illuminate\Console\Command;

/**
 * Le escribe a quien dejo el carrito lleno, y limpia los que ya no sirven.
 *
 * Las dos cosas en un solo comando a proposito: la poda es lo que hace
 * aceptable tener carritos anonimos en el servidor. Sin ella, esta tabla se
 * convierte exactamente en la basura que nadie limpia por la que el carrito
 * vivia solo en el navegador.
 */
class SendAbandonedCartReminders extends Command
{
    protected $signature = 'carts:send-abandoned-reminders';

    protected $description = 'Recuerda los carritos abandonados y poda los viejos';

    /**
     * Cuanto se conserva un carrito sin tocar.
     *
     * Mas alla de la ventana de recuperacion (48h) ya no sirve para
     * escribirle a nadie: solo queda como dato anonimo de alguien que no
     * compro.
     */
    private const PRUNE_DAYS = 30;

    public function handle(AbandonedCartReminder $reminder): int
    {
        $total = 0;

        // Solo negocios con tienda: el resto no tiene carritos y recorrerlos
        // seria una consulta por negocio para nada.
        Business::withoutGlobalScopes()
            ->get()
            ->filter(fn (Business $b) => $b->hasFeature('online_store'))
            ->each(function (Business $business) use ($reminder, &$total) {
                $enviados = $reminder->run($business);
                $total += $enviados;

                if ($enviados > 0) {
                    $this->line("{$business->slug}: {$enviados} recordatorio(s)");
                }
            });

        $podados = StoreCart::withoutGlobalScopes()
            ->where('last_activity_at', '<', now()->subDays(self::PRUNE_DAYS))
            ->delete();

        $this->info("Recordatorios enviados: {$total}. Carritos podados: {$podados}.");

        return self::SUCCESS;
    }
}
