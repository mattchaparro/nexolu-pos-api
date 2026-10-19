<?php

use App\Models\CashClosing;
use App\Services\CashClosingService;
use Illuminate\Database\Migrations\Migration;

/**
 * Correccion de datos puntual (Central Cell, negocio 29).
 *
 * El 18-oct-2026 a las 10:37 el dueño quiso cerrar los dias que traia
 * pendientes, pero la pantalla le propuso HOY (18) en vez del ultimo dia con
 * movimiento sin cerrar (17), y confirmo sin cambiar la fecha. El cierre
 * quedo fechado el 18 cuando en realidad cuadraba el 17: tomo como base los
 * $3.294.000 que conto el turno del 17 y no incluyo ninguna venta del 18 (la
 * primera fue a las 10:38, un minuto despues).
 *
 * Las consecuencias fueron dos: el 18 quedo "cerrado" sin sus 43 ventas, y al
 * existir un cierre de hoy nadie pudo abrir turno el resto del dia (ver
 * CashShiftService::openShift), asi que el dueño vendio desde su perfil de
 * admin, que esta exento de turno.
 *
 * Esta migracion le pone al cierre la fecha que le corresponde (17) y le
 * recalcula los totales reales de ese dia. El arqueo no cambia: esperado
 * $3.294.000, contado $3.276.000, diferencia -$18.000 y base $159.000 son los
 * mismos numeros, porque un cierre bien hecho del 17 da exactamente ese
 * esperado ($819.000 de base + $2.475.000 cobrados en efectivo).
 *
 * Al liberar el 18, el dueño puede cerrarlo esa misma noche con sus datos
 * reales: base $159.000 + $2.278.000 = $2.437.000 esperados.
 *
 * Idempotente: si el cierre ya no esta como lo dejo el error, no toca nada.
 */
return new class extends Migration
{
    private const BUSINESS_ID = 29;

    private const WRONG_DATE = '2026-10-18';

    private const RIGHT_DATE = '2026-10-17';

    /** La base con la que arranco el 17: lo que conto el turno del 16. */
    private const OPENING_CASH_17 = 819000.0;

    public function up(): void
    {
        $closing = $this->targetClosing(self::WRONG_DATE);

        if (! $closing) {
            return;
        }

        // Si el 17 ya tiene cierre, mover este chocaria con el unique
        // (business_id, date): algo cambio desde el diagnostico, mejor no tocar.
        if ($this->targetClosing(self::RIGHT_DATE)) {
            return;
        }

        $totals = app(CashClosingService::class)
            ->calculateTotals(self::RIGHT_DATE, self::BUSINESS_ID, self::OPENING_CASH_17);

        $closing->update([
            'date' => self::RIGHT_DATE,
            'opening_cash' => $totals['opening_cash'],
            'total_sales' => $totals['total_sales'],
            'total_cash' => $totals['total_cash'],
            'total_other_methods' => $totals['total_other'],
            'payment_breakdown' => $totals['payment_breakdown'],
            'total_expenses' => $totals['total_expenses'],
            'expected_cash' => $totals['expected_cash'],
            'difference' => round((float) $closing->actual_cash - (float) $totals['expected_cash'], 2),
        ]);
    }

    /** Devuelve el cierre a como lo dejo el error del 18. */
    public function down(): void
    {
        $closing = $this->targetClosing(self::RIGHT_DATE);

        if (! $closing || $this->targetClosing(self::WRONG_DATE)) {
            return;
        }

        $closing->update([
            'date' => self::WRONG_DATE,
            'opening_cash' => 3294000,
            'total_sales' => 0,
            'total_cash' => 0,
            'total_other_methods' => 0,
            'payment_breakdown' => [],
            'total_expenses' => 0,
            'expected_cash' => 3294000,
            'difference' => -18000,
        ]);
    }

    /**
     * El cierre de ese dia, solo si sigue siendo el que hizo el error:
     * mismo arqueo ($3.276.000 contados, $159.000 de base).
     */
    private function targetClosing(string $date): ?CashClosing
    {
        return CashClosing::withoutGlobalScopes()
            ->where('business_id', self::BUSINESS_ID)
            ->whereDate('date', $date)
            ->where('actual_cash', 3276000)
            ->where('base_for_next_day', 159000)
            ->first();
    }
};
