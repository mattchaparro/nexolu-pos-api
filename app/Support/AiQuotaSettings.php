<?php

namespace App\Support;

/**
 * Defaults globales del cupo de mensajes de IA: lee de SystemConfigStore
 * (editable en caliente sin redeploy, aunque este repo todavia no tiene una
 * pantalla de SuperAdmin que escriba esas claves) con fallback a
 * config/ai.php. Puerto de AiSettings del legacy - mismas claves, para que
 * una futura pantalla de "Ajustes de IA" en SuperAdmin (ver
 * docs/MIGRATION_BACKLOG.md) pueda reusarlas sin migrar datos.
 *
 * `employeeQuotaShare()` es la excepcion: en el legacy tampoco es editable
 * desde la UI de SuperAdmin, solo por config/env - se mantiene igual aqui.
 */
class AiQuotaSettings
{
    private const KEY_MONTHLY_INCLUDED = 'ai.monthly_included_messages';

    private const KEY_PACK_SIZE = 'ai.pack_size';

    private const KEY_PACK_PRICE = 'ai.pack_price_cop';

    public static function monthlyIncludedMessages(): int
    {
        return (int) SystemConfigStore::get(
            self::KEY_MONTHLY_INCLUDED,
            (string) config('ai.addon.monthly_included_messages', 300)
        );
    }

    public static function packSize(): int
    {
        return (int) SystemConfigStore::get(
            self::KEY_PACK_SIZE,
            (string) config('ai.addon.pack_size', 1000)
        );
    }

    public static function packPriceCop(): int
    {
        return (int) SystemConfigStore::get(
            self::KEY_PACK_PRICE,
            (string) config('ai.addon.pack_price_cop', 15000)
        );
    }

    /** Fraccion del cupo agregado reservada por empleado (el resto queda para el dueno). Rango [0.1, 1.0]. */
    public static function employeeQuotaShare(): float
    {
        $share = (float) config('ai.addon.employee_daily_share', 0.6);

        return max(0.1, min(1.0, $share));
    }
}
