<?php

namespace App\Console\Commands;

use App\Mail\LowStockAlertMail;
use App\Models\Business;
use App\Support\LowStockAlertReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

/**
 * Envia una alerta por correo a los negocios con productos por debajo de su
 * umbral de inventario. Solo canal correo por ahora - el canal WhatsApp de
 * legacy depende de la integracion con WhatsApp Cloud API, que todavia no
 * existe en esta API (ver docs/CUTOVER_TODO.md).
 */
#[Signature('inventory:send-low-stock-alerts {--business_id= : Enviar solo para un negocio}')]
#[Description('Envia por correo una alerta de inventario bajo a los negocios configurados')]
class InventorySendLowStockAlerts extends Command
{
    public function handle(): int
    {
        $businessId = $this->option('business_id');

        $query = Business::query()->where('low_stock_email_enabled', true);

        if ($businessId) {
            $query->where('id', $businessId);
        }

        $sent = 0;

        foreach ($query->get() as $business) {
            if (! $business->hasFeature('inventory')) {
                continue;
            }

            if ($business->low_stock_snoozed_until?->isFuture()) {
                continue;
            }

            $recipient = $business->low_stock_email ?: $business->users()
                ->whereHas('roles', fn (Builder $q) => $q->where('name', 'admin'))
                ->value('email');

            if (! $recipient) {
                continue;
            }

            $report = LowStockAlertReport::forBusiness($business);
            if ($report['count'] === 0) {
                continue;
            }

            try {
                Mail::to($recipient)->send(new LowStockAlertMail($business, $report['items']));
                $sent++;
            } catch (\Throwable) {
                continue;
            }
        }

        $this->info("Alertas de inventario bajo enviadas: {$sent}");

        return self::SUCCESS;
    }
}
