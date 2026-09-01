<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\HasBranchStock;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Insumo de una receta (ver Product::ingredients()). Un negocio con el
 * feature 'ingredients' activo puede componer un Product de varios
 * Ingredient (ingredient_product, con quantity por unidad de producto); el
 * stock vendible de ese producto sale entonces de sus insumos, no de
 * products.stock - ver App\Support\ProductAvailability::effectiveStock().
 */
#[Fillable(['business_id', 'name', 'unit', 'stock', 'cost_price', 'min_stock', 'is_active'])]
class Ingredient extends Model
{
    use BelongsToBusiness, HasBranchStock, HasFactory;

    /** Columna de este modelo en branch_stocks (ver HasBranchStock). */
    public const BRANCH_STOCK_COLUMN = 'ingredient_id';

    protected function casts(): array
    {
        return [
            'stock' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'min_stock' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity')->withTimestamps();
    }

    public function linkedExpenses(): MorphMany
    {
        return $this->morphMany(Expense::class, 'linkable');
    }

    /**
     * Cuando cambia el cost_price de este ingrediente, recalcula el
     * cost_price de todos los productos que lo usan en su receta.
     */
    public function syncLinkedProductCosts(): void
    {
        $this->products->each(fn (Product $product) => $product->syncRecipeCost());
    }
}
