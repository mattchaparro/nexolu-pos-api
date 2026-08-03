<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\User;

/**
 * Único lugar de la app que crea StockMovement (y por lo tanto el único que
 * mueve products.stock). SaleService/OpenTabService pasan por aquí en vez de
 * mutar stock directamente, así toda venta/reverso/ajuste queda con
 * auditoría (quién, cuándo, motivo) en vez del machetazo legacy de un
 * decrement() suelto sin rastro.
 */
class StockService
{
    public function entry(User $user, Product $product, float $quantity, ?string $notes = null, ?int $reasonId = null, ?float $unitCostCop = null): StockMovement
    {
        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_ENTRY,
            'stock_movement_reason_id' => $reasonId ?? StockMovementReason::systemIdForCode(StockMovementReason::CODE_MANUAL_IN),
            'quantity' => abs($quantity),
            'unit_cost_cop' => $unitCostCop,
            'notes' => $notes,
            'user_id' => $user->id,
        ]);
    }

    public function exit(User $user, Product $product, float $quantity, ?string $notes = null, ?int $reasonId = null, ?float $unitCostCop = null): StockMovement
    {
        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_EXIT,
            'stock_movement_reason_id' => $reasonId ?? StockMovementReason::systemIdForCode(StockMovementReason::CODE_MANUAL_OUT),
            'quantity' => -abs($quantity),
            'unit_cost_cop' => $unitCostCop,
            'notes' => $notes,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Ajusta el stock a un valor absoluto ($newStock), registrando la
     * diferencia con el stock actual como el movimiento.
     */
    public function adjust(User $user, Product $product, float $newStock, ?string $notes = null, ?int $reasonId = null): StockMovement
    {
        $diff = $newStock - (float) $product->stock;

        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'stock_movement_reason_id' => $reasonId ?? StockMovementReason::systemIdForCode(StockMovementReason::CODE_ADJUSTMENT),
            'quantity' => $diff,
            'notes' => $notes ?? 'Ajuste manual',
            'user_id' => $user->id,
        ]);
    }

    /**
     * Descuenta stock por la venta de un item (venta directa o cuenta
     * abierta - ambas son Sale). No hace nada si el producto no rastrea
     * stock, igual que el legacy.
     */
    public function registerSale(User $user, Product $product, int $quantity, Sale $sale): ?StockMovement
    {
        if (! $product->track_stock) {
            return null;
        }

        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_SALE,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_SALE),
            'quantity' => -abs($quantity),
            'reference' => "Venta #{$sale->id}",
            'user_id' => $user->id,
        ]);
    }

    /**
     * Restaura stock por reverso/cancelación de venta, edición de cuenta
     * abierta a la baja, etc. $notes distingue el origen exacto para quien
     * lea el historial de movimientos.
     */
    public function registerSaleReversal(User $user, Product $product, int $quantity, Sale $sale, ?string $notes = null): ?StockMovement
    {
        if (! $product->track_stock) {
            return null;
        }

        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_ENTRY,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_SALE_REVERSAL),
            'quantity' => abs($quantity),
            'reference' => "Ajuste venta #{$sale->id}",
            'notes' => $notes,
            'user_id' => $user->id,
        ]);
    }
}
