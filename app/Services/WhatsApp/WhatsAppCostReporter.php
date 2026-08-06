<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppUsageDaily;
use App\Services\Messaging\Contracts\MessagingCostReporter;

/**
 * Implementacion de hoy de MessagingCostReporter: suma la tabla local
 * `whatsapp_usage_daily`, que WhatsAppCloudClient alimenta con cada envio
 * aceptado por Meta.
 */
class WhatsAppCostReporter implements MessagingCostReporter
{
    public function costUsdForPeriod(string $dateFrom, string $dateTo): ?float
    {
        $micros = (int) WhatsAppUsageDaily::query()
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->sum('cost_micros');

        return round($micros / 1_000_000, 6);
    }
}
