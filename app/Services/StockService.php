<?php

namespace App\Services;

use App\Models\Layaway;
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

    /**
     * Reserva stock para un apartado: sale del disponible pero NO es una
     * venta (no hay Sale de por medio - la deuda vive en LayawayPayment), por
     * eso es type=exit con motivo propio 'layaway' y no type=sale como hacia
     * el legacy. El legacy ademas usaba type='layaway' a secas, un valor que
     * nunca estuvo en el enum de la columna (entry/exit/adjustment/sale) -
     * confirmado que revienta con "Data truncated for column 'type'" en modo
     * estricto.
     */
    public function reserveForLayaway(User $user, Product $product, int $quantity, Layaway $layaway): ?StockMovement
    {
        if (! $product->track_stock) {
            return null;
        }

        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_EXIT,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_LAYAWAY),
            'quantity' => -abs($quantity),
            'reference' => "Apartado #{$layaway->id}",
            'user_id' => $user->id,
        ]);
    }

    /**
     * Libera una reserva de apartado (cancelacion o edicion de items a la
     * baja). $notes distingue el origen exacto para el historial.
     */
    public function releaseLayawayReservation(User $user, Product $product, int $quantity, Layaway $layaway, ?string $notes = null): ?StockMovement
    {
        if (! $product->track_stock) {
            return null;
        }

        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_ENTRY,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_LAYAWAY_CANCEL),
            'quantity' => abs($quantity),
            'reference' => "Apartado #{$layaway->id}",
            'notes' => $notes,
            'user_id' => $user->id,
        ]);
    }
}
