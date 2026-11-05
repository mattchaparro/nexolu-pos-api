<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correccion de datos puntual (Las Banquitas, negocio 24), revisada con
 * Alejandro el 2026-11-05: el margen de octubre salia en -22,7%.
 *
 * La compra 588 (26-ago-2026) registro 37 bolsas de Papas (producto 2296)
 * por $59.447.000 en vez de $59.447 - tres ceros de mas: el costo unitario
 * quedo en $1.606.676 cuando todas las demas compras rondan $1.600. El
 * costo promedio salto de $1.791 a $408.508 y fue bajando con cada compra
 * normal, pero cada venta desde entonces congelo ese costo inflado
 * (sale_items.unit_cost_at_sale): 260 lineas, $11,5 millones de costo falso
 * solo en octubre.
 *
 * Los costos correctos salen de rehacer el promedio ponderado con la misma
 * formula (WeightedAverageCost) sobre product_cost_history: el stock previo
 * de cada compra se despeja de su costo antes/despues y da entero en todas,
 * asi que la reconstruccion es exacta. En el periodo solo las compras
 * movieron el costo (no hubo ajustes manuales). Marzo es el unico mes con
 * cierre contable y no se toca.
 *
 * Idempotente y reversible: solo cambia filas que siguen con el valor errado.
 */
return new class extends Migration
{
    private const PRODUCT_ID = 2296;

    private const PURCHASE_LINE_ID = 1592;

    /** Costo errado congelado en las ventas => costo correcto, por compra (product_cost_history). */
    private const COSTS = [
        // history_id => [cost_after errado, cost_after correcto]
        1875 => [408508.2373, 1744.1757],
        1906 => [370993.3455, 1732.3510],
        1928 => [304562.3878, 1715.2375],
        2045 => [271891.3767, 1725.5716],
        2055 => [219963.6117, 1683.9519],
        2084 => [200727.1150, 1707.4267],
        2100 => [120286.3696, 1640.0140],
        2572 => [98717.9542, 1643.6630],
        2604 => [85167.2810, 1650.8553],
        2639 => [65830.9468, 1665.8888],
        2654 => [49968.8602, 1672.5776],
        2674 => [44160.5927, 1670.4207],
    ];

    public function up(): void
    {
        $this->apply(fn (array $pair) => $pair, wrongLine: [1606675.6757, 59447000.00], rightLine: [1606.6757, 59447.00]);
    }

    public function down(): void
    {
        $this->apply(fn (array $pair) => array_reverse($pair), wrongLine: [1606.6757, 59447.00], rightLine: [1606675.6757, 59447000.00]);
    }

    /**
     * @param  callable(array{0: float, 1: float}): array{0: float, 1: float}  $direction  [desde, hacia]
     * @param  array{0: float, 1: float}  $wrongLine  [unit_cost_cop, line_total_cop] a reemplazar
     * @param  array{0: float, 1: float}  $rightLine
     */
    private function apply(callable $direction, array $wrongLine, array $rightLine): void
    {
        if (! DB::table('products')->where('id', self::PRODUCT_ID)->where('business_id', 24)->exists()) {
            return; // otro ambiente (local, tests): nada que corregir
        }

        DB::transaction(function () use ($direction, $wrongLine, $rightLine) {
            DB::table('purchase_lines')->where('id', self::PURCHASE_LINE_ID)->where('product_id', self::PRODUCT_ID)
                ->where('unit_cost_cop', $wrongLine[0])
                ->update(['unit_cost_cop' => $rightLine[0], 'line_total_cop' => $rightLine[1], 'updated_at' => now()]);

            DB::table('stock_movements')->where('purchase_line_id', self::PURCHASE_LINE_ID)->where('product_id', self::PRODUCT_ID)
                ->where('unit_cost_cop', $wrongLine[0])
                ->update(['unit_cost_cop' => $rightLine[0]]);

            $previousAfter = [1790.85, 1790.85]; // [errado, correcto] antes de la compra 588
            foreach (self::COSTS as $historyId => $pair) {
                [$from, $to] = $direction($pair);
                [$beforeFrom, $beforeTo] = $direction($previousAfter);

                DB::table('product_cost_history')->where('id', $historyId)->where('product_id', self::PRODUCT_ID)
                    ->whereBetween('cost_after', [$from - 0.01, $from + 0.01])
                    ->update(['cost_before' => $beforeTo, 'cost_after' => $to]);

                // Las ventas guardan el costo redondeado a 2 decimales.
                DB::table('sale_items')->where('product_id', self::PRODUCT_ID)
                    ->whereBetween('unit_cost_at_sale', [round($from, 2) - 0.01, round($from, 2) + 0.01])
                    ->update(['unit_cost_at_sale' => round($to, 2)]);

                $previousAfter = $pair;
            }

            [$currentFrom, $currentTo] = $direction(self::COSTS[2674]);
            DB::table('products')->where('id', self::PRODUCT_ID)
                ->whereBetween('cost_price', [round($currentFrom, 2) - 0.01, round($currentFrom, 2) + 0.01])
                ->update(['cost_price' => round($currentTo, 2), 'updated_at' => now()]);
        });
    }
};
