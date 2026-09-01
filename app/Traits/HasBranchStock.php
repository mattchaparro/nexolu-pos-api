<?php

namespace App\Traits;

use App\Models\BranchStock;
use App\Services\BranchService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lectura del stock por sede para los tres objetivos que lo tienen: producto,
 * variante e insumo. La escritura no pasa por aqui - sigue siendo exclusiva de
 * StockMovement.
 *
 * La clave de todo el diseño esta en stockAt(): cuando hay una sede activa
 * devuelve lo que hay EN ESA SEDE, y cuando no la hay (consolidado, comandos,
 * jobs, alertas por correo) devuelve el total del negocio leyendo la columna
 * agregada del catalogo. Por eso casi ningun sitio que ya preguntaba por el
 * stock tuvo que cambiar de forma: la misma llamada significa lo correcto en
 * los dos contextos.
 *
 * El modelo que lo use debe declarar la constante BRANCH_STOCK_COLUMN con su
 * columna en branch_stocks.
 */
trait HasBranchStock
{
    /**
     * Una fila creada YA con stock (la carga inicial de un insumo, un
     * producto dado de alta por superadmin, una factory) nunca pasa por
     * StockMovement, asi que su saldo no aterrizaria en ninguna sede y el
     * agregado quedaria descuadrado con la suma de sedes en el siguiente
     * movimiento. Aqui se siembra ese saldo inicial en la sede activa.
     *
     * No recalcula el agregado a proposito: la columna ya trae el valor
     * correcto, sembrarlo solo lo reparte.
     */
    protected static function bootHasBranchStock(): void
    {
        static::created(function ($model) {
            if (! $model->business_id || (float) $model->stock == 0.0) {
                return;
            }

            $branchId = BranchContext::branchId()
                ?? app(BranchService::class)->mainBranchId((int) $model->business_id);

            if ($branchId === null) {
                return;
            }

            BranchStock::add(
                (int) $model->business_id,
                $branchId,
                static::BRANCH_STOCK_COLUMN,
                (int) $model->getKey(),
                (float) $model->stock,
            );
        });
    }

    /** @return HasMany<BranchStock, $this> */
    public function branchStocks(): HasMany
    {
        return $this->hasMany(BranchStock::class, static::BRANCH_STOCK_COLUMN);
    }

    /**
     * Stock en la sede indicada, o en la activa si no se indica ninguna, o el
     * total del negocio si no hay sede en juego.
     *
     * Usa la relacion ya cargada cuando existe: en un listado de catalogo
     * (cientos de productos) consultar fila por fila serian cientos de
     * consultas. Ver scopeWithBranchStock().
     */
    public function stockAt(?int $branchId = null): float
    {
        $branchId ??= BranchContext::branchId();

        if ($branchId === null) {
            return (float) $this->stock;
        }

        if ($this->relationLoaded('branchStocks')) {
            return (float) ($this->branchStocks->firstWhere('branch_id', $branchId)?->stock ?? 0);
        }

        return BranchStock::quantity($branchId, static::BRANCH_STOCK_COLUMN, (int) $this->getKey());
    }

    /**
     * Precarga el saldo de la sede activa para toda la coleccion. Sin sede
     * activa no carga nada: stockAt() devolvera el agregado, que ya viene en
     * la propia fila.
     */
    public function scopeWithBranchStock(Builder $query, ?int $branchId = null): Builder
    {
        $branchId ??= BranchContext::branchId();

        if ($branchId === null) {
            return $query;
        }

        return $query->with([
            'branchStocks' => fn ($relation) => $relation->where('branch_id', $branchId),
        ]);
    }
}
