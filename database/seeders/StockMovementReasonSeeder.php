<?php

namespace Database\Seeders;

use App\Models\StockMovementReason;
use Illuminate\Database\Seeder;

/**
 * Motivos globales (business_id null) que StockService resuelve por código
 * vía StockMovementReason::systemIdForCode(). Idempotente a propósito: en
 * producción el monolito legacy ya insertó estas mismas filas hace tiempo,
 * así que aquí solo se completan si faltan (p.ej. en una BD de pruebas
 * cargada desde el schema-only dump).
 */
class StockMovementReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['code' => StockMovementReason::CODE_PURCHASE, 'label' => 'Compra / proveedor'],
            ['code' => StockMovementReason::CODE_WASTE, 'label' => 'Salida por desperdicio'],
            ['code' => StockMovementReason::CODE_DAMAGE, 'label' => 'Salida por daño'],
            ['code' => StockMovementReason::CODE_MANUAL_IN, 'label' => 'Entrada manual'],
            ['code' => StockMovementReason::CODE_MANUAL_OUT, 'label' => 'Salida manual'],
            ['code' => StockMovementReason::CODE_ADJUSTMENT, 'label' => 'Ajuste de inventario'],
            ['code' => StockMovementReason::CODE_SALE, 'label' => 'Venta'],
            ['code' => StockMovementReason::CODE_SALE_REVERSAL, 'label' => 'Reverso de venta'],
        ];

        foreach ($reasons as $reason) {
            StockMovementReason::firstOrCreate([
                'business_id' => null,
                'code' => $reason['code'],
            ], [
                'label' => $reason['label'],
            ]);
        }
    }
}
