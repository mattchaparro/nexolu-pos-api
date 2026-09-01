<?php

namespace App\Traits;

use App\Models\BranchProductPrice;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Precio por sede para productos y variantes. Mismo contrato que
 * HasBranchStock: con una sede activa responde el precio DE ESA SEDE, y sin
 * ella el del catalogo.
 *
 * La diferencia con el stock es que aqui la ausencia de fila es lo normal,
 * no la excepcion: casi ningun producto cambia de precio entre locales, asi
 * que priceAt() cae al precio base y no consulta nada cuando no hay override
 * precargado.
 *
 * El modelo que lo use debe declarar BRANCH_PRICE_COLUMN con su columna en
 * branch_product_prices.
 */
trait HasBranchPrice
{
    /** @return HasMany<BranchProductPrice, $this> */
    public function branchPrices(): HasMany
    {
        return $this->hasMany(BranchProductPrice::class, static::BRANCH_PRICE_COLUMN);
    }

    /**
     * Precio en la sede indicada, en la activa si no se indica ninguna, o el
     * del catalogo si no hay sede o esa sede no lo personalizo.
     */
    public function priceAt(?int $branchId = null): float
    {
        $branchId ??= BranchContext::branchId();

        if ($branchId === null) {
            return (float) $this->price;
        }

        if ($this->relationLoaded('branchPrices')) {
            $override = $this->branchPrices->firstWhere('branch_id', $branchId);

            return $override ? (float) $override->price : (float) $this->price;
        }

        $override = BranchProductPrice::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where(static::BRANCH_PRICE_COLUMN, $this->getKey())
            ->value('price');

        return $override !== null ? (float) $override : (float) $this->price;
    }

    /** Precarga los overrides de la sede activa para toda la coleccion. */
    public function scopeWithBranchPrice(Builder $query, ?int $branchId = null): Builder
    {
        $branchId ??= BranchContext::branchId();

        if ($branchId === null) {
            return $query;
        }

        return $query->with([
            'branchPrices' => fn ($relation) => $relation->where('branch_id', $branchId),
        ]);
    }
}
