<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Layaway;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\User;
use App\Support\WeightedAverageCost;

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
     * stock, igual que el legacy. Tampoco mueve products.stock si el
     * producto gestiona su disponibilidad por receta (ver
     * Product::isStockManagedByIngredientsRecipe()) - en ese caso quien
     * llama debe usar registerIngredientsConsumption() en su lugar, esa
     * columna es "fantasma" para un producto con receta.
     */
    public function registerSale(User $user, Product $product, int $quantity, Sale $sale): ?StockMovement
    {
        if (! $product->track_stock || $product->isStockManagedByIngredientsRecipe()) {
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
        if (! $product->track_stock || $product->isStockManagedByIngredientsRecipe()) {
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
     * estricto. Tampoco mueve products.stock si el producto gestiona su
     * disponibilidad por receta (ver Product::isStockManagedByIngredientsRecipe) -
     * en ese caso quien llama debe usar reserveIngredientsForLayaway() en su
     * lugar.
     */
    public function reserveForLayaway(User $user, Product $product, int $quantity, Layaway $layaway): ?StockMovement
    {
        if (! $product->track_stock || $product->isStockManagedByIngredientsRecipe()) {
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
        if (! $product->track_stock || $product->isStockManagedByIngredientsRecipe()) {
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

    /**
     * Entrada de stock por una linea de compra recibida. A diferencia de
     * entry(), siempre queda enlazada a $line via purchase_line_id (asi el
     * historial de movimientos de un producto muestra de que compra vino
     * cada entrada), y el motivo es 'purchase' a proposito - nunca 'manual_in'.
     */
    public function registerPurchase(User $user, Product $product, float $quantity, PurchaseLine $line, float $unitCostCop): StockMovement
    {
        return StockMovement::create([
            'product_id' => $product->id,
            'business_id' => $product->business_id,
            'type' => StockMovement::TYPE_ENTRY,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_PURCHASE),
            'purchase_line_id' => $line->id,
            'quantity' => abs($quantity),
            'unit_cost_cop' => $unitCostCop,
            'reference' => "Compra #{$line->purchase_id}",
            'user_id' => $user->id,
        ]);
    }

    /**
     * Igual que registerPurchase(), para una linea de compra de ingrediente
     * (insumo). Los ajustes de costo promedio ponderado + propagacion a
     * productos con receta los hace PurchaseService, no este metodo - el
     * mismo reparto de responsabilidades que ya tiene el lado de producto.
     */
    public function registerIngredientPurchase(User $user, Ingredient $ingredient, float $quantity, PurchaseLine $line, float $unitCostCop): StockMovement
    {
        return StockMovement::create([
            'ingredient_id' => $ingredient->id,
            'business_id' => $ingredient->business_id,
            'type' => StockMovement::TYPE_ENTRY,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_PURCHASE),
            'purchase_line_id' => $line->id,
            'quantity' => abs($quantity),
            'unit_cost_cop' => $unitCostCop,
            'reference' => "Compra #{$line->purchase_id}",
            'user_id' => $user->id,
        ]);
    }

    /**
     * Entrada manual de stock de un ingrediente (reposicion, compra suelta).
     * Si viene $unitCostCop, recalcula el costo promedio ponderado del
     * ingrediente y lo propaga a las recetas que lo usan.
     */
    public function ingredientEntry(User $user, Ingredient $ingredient, float $quantity, ?string $notes = null, ?int $reasonId = null, ?float $unitCostCop = null): StockMovement
    {
        $previousStock = (float) $ingredient->stock;
        $previousCost = (float) $ingredient->cost_price;

        $movement = StockMovement::create([
            'ingredient_id' => $ingredient->id,
            'business_id' => $ingredient->business_id,
            'type' => StockMovement::TYPE_ENTRY,
            'stock_movement_reason_id' => $reasonId ?? StockMovementReason::systemIdForCode(StockMovementReason::CODE_MANUAL_IN),
            'quantity' => abs($quantity),
            'unit_cost_cop' => $unitCostCop,
            'notes' => $notes,
            'user_id' => $user->id,
        ]);

        if ($unitCostCop !== null && $unitCostCop > 0) {
            $newAverageCost = WeightedAverageCost::calculate($previousStock, $previousCost, abs($quantity), $unitCostCop);
            $ingredient->update(['cost_price' => $newAverageCost]);
            $ingredient->syncLinkedProductCosts();
        }

        return $movement;
    }

    public function ingredientExit(User $user, Ingredient $ingredient, float $quantity, ?string $notes = null, ?int $reasonId = null, ?float $unitCostCop = null): StockMovement
    {
        $cost = $unitCostCop ?? (float) $ingredient->cost_price;

        return StockMovement::create([
            'ingredient_id' => $ingredient->id,
            'business_id' => $ingredient->business_id,
            'type' => StockMovement::TYPE_EXIT,
            'stock_movement_reason_id' => $reasonId ?? StockMovementReason::systemIdForCode(StockMovementReason::CODE_MANUAL_OUT),
            'quantity' => -abs($quantity),
            'unit_cost_cop' => $cost > 0 ? $cost : null,
            'notes' => $notes,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Ajusta el stock de un ingrediente a un valor absoluto ($newStock),
     * registrando la diferencia con el stock actual como el movimiento.
     */
    public function ingredientAdjust(User $user, Ingredient $ingredient, float $newStock, ?string $notes = null, ?int $reasonId = null): StockMovement
    {
        $diff = $newStock - (float) $ingredient->stock;

        return StockMovement::create([
            'ingredient_id' => $ingredient->id,
            'business_id' => $ingredient->business_id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'stock_movement_reason_id' => $reasonId ?? StockMovementReason::systemIdForCode(StockMovementReason::CODE_ADJUSTMENT),
            'quantity' => $diff,
            'notes' => $notes ?? 'Ajuste manual',
            'user_id' => $user->id,
        ]);
    }

    /**
     * Descuenta el stock de cada ingrediente de la receta de $product por la
     * venta de $quantity unidades (pivot.quantity * $quantity por
     * ingrediente). A diferencia de legacy (que mutaba ingredients.stock
     * directo sin dejar rastro), aqui cada descuento queda como un
     * StockMovement auditable, igual que el resto de esta clase.
     *
     * No hace nada si el producto no gestiona su stock por receta (ver
     * Product::isStockManagedByIngredientsRecipe()) - StockService::registerSale()
     * ya cubre ese caso con un StockMovement normal de producto.
     */
    public function registerIngredientsConsumption(User $user, Product $product, int $quantity, Sale $sale): void
    {
        $this->adjustIngredientsForRecipeProduct(
            $user, $product, $quantity, StockMovement::TYPE_EXIT,
            StockMovementReason::CODE_SALE, "Venta #{$sale->id}"
        );
    }

    /**
     * Restaura el consumo de ingredientes de un reverso/edicion a la baja de
     * cuenta abierta. Contraparte de registerIngredientsConsumption().
     */
    public function restoreIngredientsConsumption(User $user, Product $product, int $quantity, Sale $sale, ?string $notes = null): void
    {
        $this->adjustIngredientsForRecipeProduct(
            $user, $product, $quantity, StockMovement::TYPE_ENTRY,
            StockMovementReason::CODE_SALE_REVERSAL, "Ajuste venta #{$sale->id}", $notes
        );
    }

    /**
     * Contraparte de reserveForLayaway() para un producto con receta: reserva
     * cada ingrediente en vez de products.stock (columna "fantasma" para
     * estos productos).
     */
    public function reserveIngredientsForLayaway(User $user, Product $product, int $quantity, Layaway $layaway): void
    {
        $this->adjustIngredientsForRecipeProduct(
            $user, $product, $quantity, StockMovement::TYPE_EXIT,
            StockMovementReason::CODE_LAYAWAY, "Apartado #{$layaway->id}"
        );
    }

    /**
     * Contraparte de releaseLayawayReservation() para un producto con receta.
     */
    public function releaseIngredientsLayawayReservation(User $user, Product $product, int $quantity, Layaway $layaway, ?string $notes = null): void
    {
        $this->adjustIngredientsForRecipeProduct(
            $user, $product, $quantity, StockMovement::TYPE_ENTRY,
            StockMovementReason::CODE_LAYAWAY_CANCEL, "Apartado #{$layaway->id}", $notes
        );
    }

    /**
     * Nucleo compartido por las 4 variantes de consumo/reserva de
     * ingredientes de arriba: mismo bucle "un StockMovement por ingrediente
     * de la receta", solo cambia direccion (type), motivo y referencia. No
     * hace nada si el producto no gestiona su stock por receta (ver
     * Product::isStockManagedByIngredientsRecipe()) - quien llama ya cubrio
     * ese caso con el StockMovement normal de producto (registerSale,
     * reserveForLayaway, etc.).
     */
    private function adjustIngredientsForRecipeProduct(
        User $user,
        Product $product,
        int $quantity,
        string $type,
        string $reasonCode,
        string $reference,
        ?string $notes = null,
    ): void {
        if (! $product->isStockManagedByIngredientsRecipe()) {
            return;
        }

        $product->loadMissing('ingredients');
        $sign = $type === StockMovement::TYPE_EXIT ? -1 : 1;
        $reasonId = StockMovementReason::systemIdForCode($reasonCode);

        foreach ($product->ingredients as $ingredient) {
            StockMovement::create([
                'ingredient_id' => $ingredient->id,
                'business_id' => $ingredient->business_id,
                'type' => $type,
                'stock_movement_reason_id' => $reasonId,
                'quantity' => $sign * abs((float) $ingredient->pivot->quantity * $quantity),
                'reference' => $reference,
                'notes' => $notes,
                'user_id' => $user->id,
            ]);
        }
    }
}
