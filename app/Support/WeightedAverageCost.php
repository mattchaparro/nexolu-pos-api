<?php

namespace App\Support;

/**
 * Costo promedio ponderado al recibir mercancia (compra, entrada manual de
 * producto o ingrediente). Unico calculo compartido por PurchaseService y
 * StockService en vez de reimplementarlo en cada uno.
 */
class WeightedAverageCost
{
    public static function calculate(float $previousQuantity, float $previousUnitCost, float $incomingQuantity, float $incomingUnitCost): float
    {
        $denominator = $previousQuantity + $incomingQuantity;

        if ($denominator <= 0 || $previousQuantity <= 0) {
            return round(max(0, $incomingUnitCost), 4);
        }

        return round((($previousQuantity * $previousUnitCost) + ($incomingQuantity * $incomingUnitCost)) / $denominator, 4);
    }
}
