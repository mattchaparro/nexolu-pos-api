<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\StockMovementReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ajuste masivo desde la pantalla de "edicion masiva" del Catalogo (doble
 * clic/tab por celda, un lote de productos o insumos donde cada fila puede
 * traer nombre/costo/precio/stock nuevos). Puerto de
 * Admin\StockController::bulkStore()/bulkStoreIngredients() del legacy - el
 * stock pasa por StockService (auditoria via StockMovement), nombre/costo/
 * precio se actualizan directo, y un cambio de costo deja rastro en
 * ProductCostHistory igual que una compra.
 */
class BulkStockUpdateService
{
    public function __construct(private StockService $stockService) {}

    /**
     * @param  list<array{product_id:int, new_name?:string|null, new_stock?:int|null, new_cost?:float|null, new_price?:float|null}>  $items
     * @return array{name_count:int, stock_count:int, cost_count:int, price_count:int}
     */
    public function updateProducts(User $user, array $items, ?string $notes): array
    {
        $notes = $notes ?: 'Ajuste manual';
        $adjustmentReasonId = StockMovementReason::systemIdForCode(StockMovementReason::CODE_ADJUSTMENT);
        $counts = ['name_count' => 0, 'stock_count' => 0, 'cost_count' => 0, 'price_count' => 0];

        DB::transaction(function () use ($items, $notes, $adjustmentReasonId, $user, &$counts) {
            foreach ($items as $row) {
                $product = Product::whereKey($row['product_id'])->lockForUpdate()->firstOrFail();
                $directUpdates = [];

                if (! empty($row['new_name'] ?? null)) {
                    $newName = trim($row['new_name']);
                    if ($newName !== $product->name) {
                        $directUpdates['name'] = $newName;
                        $counts['name_count']++;
                    }
                }

                if (($row['new_stock'] ?? null) !== null) {
                    $target = (int) $row['new_stock'];
                    if ($target !== (int) $product->stock) {
                        $this->stockService->adjust($user, $product, $target, $notes, $adjustmentReasonId);
                        $counts['stock_count']++;
                    }
                }

                if (($row['new_cost'] ?? null) !== null) {
                    $newCost = round((float) $row['new_cost'], 4);
                    $costBefore = (float) $product->cost_price;
                    if (abs($costBefore - $newCost) >= 0.00001) {
                        $directUpdates['cost_price'] = $newCost;
                        ProductCostHistory::create([
                            'business_id' => $user->business_id,
                            'product_id' => $product->id,
                            'cost_before' => $costBefore,
                            'cost_after' => $newCost,
                            'source' => ProductCostHistory::SOURCE_MANUAL_ADJUSTMENT,
                            'user_id' => $user->id,
                            'notes' => $notes,
                        ]);
                        $counts['cost_count']++;
                    }
                }

                if (($row['new_price'] ?? null) !== null) {
                    $newPrice = round((float) $row['new_price'], 4);
                    if (abs((float) $product->price - $newPrice) >= 0.00001) {
                        $directUpdates['price'] = $newPrice;
                        $counts['price_count']++;
                    }
                }

                if ($directUpdates !== []) {
                    $product->update($directUpdates);
                }
            }
        });

        return $counts;
    }

    /**
     * @param  list<array{ingredient_id:int, new_name?:string|null, new_stock?:float|null, new_cost?:float|null}>  $items
     * @return array{name_count:int, stock_count:int, cost_count:int}
     */
    public function updateIngredients(User $user, array $items, ?string $notes): array
    {
        $notes = $notes ?: 'Ajuste manual';
        $adjustmentReasonId = StockMovementReason::systemIdForCode(StockMovementReason::CODE_ADJUSTMENT);
        $counts = ['name_count' => 0, 'stock_count' => 0, 'cost_count' => 0];

        DB::transaction(function () use ($items, $notes, $adjustmentReasonId, $user, &$counts) {
            foreach ($items as $row) {
                $ingredient = Ingredient::whereKey($row['ingredient_id'])->lockForUpdate()->firstOrFail();

                if (! empty($row['new_name'] ?? null)) {
                    $newName = trim($row['new_name']);
                    if ($newName !== $ingredient->name) {
                        $ingredient->update(['name' => $newName]);
                        $counts['name_count']++;
                    }
                }

                if (($row['new_stock'] ?? null) !== null) {
                    $target = (float) $row['new_stock'];
                    if (abs($target - (float) $ingredient->stock) >= 0.0001) {
                        $this->stockService->ingredientAdjust($user, $ingredient, $target, $notes, $adjustmentReasonId);
                        $counts['stock_count']++;
                    }
                }

                if (($row['new_cost'] ?? null) !== null) {
                    $newCost = round((float) $row['new_cost'], 4);
                    if (abs((float) $ingredient->cost_price - $newCost) >= 0.00001) {
                        $ingredient->update(['cost_price' => $newCost]);
                        $ingredient->syncLinkedProductCosts();
                        $counts['cost_count']++;
                    }
                }
            }
        });

        return $counts;
    }
}
