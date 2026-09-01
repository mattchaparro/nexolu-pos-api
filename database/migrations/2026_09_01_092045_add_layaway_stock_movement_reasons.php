<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los dos motivos de apartado que StockService lleva pidiendo desde siempre y
 * que nunca existieron en la base.
 *
 * StockMovementReason declara CODE_LAYAWAY y CODE_LAYAWAY_CANCEL, pero el
 * schema legacy solo trajo 8 motivos globales y estos dos no estaban entre
 * ellos. Resultado: systemIdForCode() devuelve null y cada movimiento creado
 * por reserveForLayaway() / releaseLayawayReservation() (y sus variantes de
 * variante e ingrediente) se guarda con stock_movement_reason_id NULL - en el
 * historial del producto queda una salida sin motivo, indistinguible de un
 * ajuste manual.
 *
 * Es un bug latente, no uno que ya este ensuciando datos: la bandera
 * 'layaway' viene apagada en los dos planes (ver BusinessFeaturePresets), asi
 * que ese camino todavia no ha corrido para nadie. Se arregla antes de
 * encenderla, no despues.
 *
 * No hay backfill. Los unicos movimientos con referencia de apartado que hay
 * hoy vienen del monolito, que los registraba con los motivos 'sale' y
 * 'sale_reversal'; reescribir historia migrada para "mejorarla" es
 * exactamente lo que CLAUDE.md pide no hacer con tablas compartidas, y esos
 * motivos tampoco son falsos.
 *
 * Tampoco hace falta limpiar la cache de systemIdForCode(): rememberForever()
 * vuelve a consultar cuando el valor guardado es null (ver Repository.php),
 * asi que el null que hay cacheado se resuelve solo en la primera llamada
 * despues de esta migracion.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const REASONS = [
        'layaway' => 'Reserva por apartado',
        'layaway_cancel' => 'Cancelacion de apartado',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::REASONS as $code => $label) {
            DB::table('stock_movement_reasons')->insertOrIgnore([
                'business_id' => null,
                'code' => $code,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Solo los globales: un negocio pudo crear un motivo propio con el
        // mismo code (el unique es por business_id + code) y ese no es nuestro.
        DB::table('stock_movement_reasons')
            ->whereNull('business_id')
            ->whereIn('code', array_keys(self::REASONS))
            ->delete();
    }
};
