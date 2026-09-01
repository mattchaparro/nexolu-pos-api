<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Saldo de un producto/variante/insumo en UNA sede. Ver la migracion
 * create_branch_stocks_table para por que la columna del catalogo dejo de ser
 * la verdad y paso a ser el agregado.
 *
 * Nadie escribe aqui a mano: el unico camino es StockMovement, igual que
 * antes era el unico camino para products.stock. Los helpers estaticos de
 * esta clase son ese camino.
 */
#[Fillable(['business_id', 'branch_id', 'product_id', 'product_variant_id', 'ingredient_id', 'stock'])]
class BranchStock extends Model
{
    use BelongsToBusiness;

    /**
     * Las tres columnas que identifican QUE se esta contando. Exactamente una
     * lleva valor en cada fila.
     *
     * @var list<string>
     */
    public const TARGET_COLUMNS = ['product_id', 'product_variant_id', 'ingredient_id'];

    /**
     * Tabla del catalogo donde vive el agregado de cada tipo de objetivo.
     *
     * @var array<string, string>
     */
    private const AGGREGATE_TABLES = [
        'product_id' => 'products',
        'product_variant_id' => 'product_variants',
        'ingredient_id' => 'ingredients',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Suma $quantity (con signo) al saldo de esa sede y devuelve el saldo
     * resultante.
     *
     * insertOrIgnore + increment y no un read-modify-write: dos ventas
     * simultaneas del mismo producto en la misma caja tienen que sumar, no
     * pisarse. El insert se apoya en el indice unico para que la carrera la
     * resuelva la base y no la aplicacion.
     */
    public static function add(int $businessId, int $branchId, string $column, int $targetId, float $quantity): float
    {
        self::assertTargetColumn($column);

        DB::table('branch_stocks')->insertOrIgnore([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            $column => $targetId,
            'stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('branch_stocks')
            ->where('branch_id', $branchId)
            ->where($column, $targetId)
            ->increment('stock', $quantity, ['updated_at' => now()]);

        return self::quantity($branchId, $column, $targetId);
    }

    /** Saldo de esa sede. 0 si nunca se movio nada ahi. */
    public static function quantity(int $branchId, string $column, int $targetId): float
    {
        self::assertTargetColumn($column);

        return (float) DB::table('branch_stocks')
            ->where('branch_id', $branchId)
            ->where($column, $targetId)
            ->value('stock');
    }

    /**
     * Reescribe la columna del catalogo con la suma de todas las sedes.
     *
     * Se recalcula en vez de incrementarse en paralelo a proposito: si una
     * fila de branch_stocks se corrige a mano o un backfill entra tarde, el
     * agregado se auto-corrige en el siguiente movimiento en lugar de
     * arrastrar el desfase para siempre.
     */
    public static function syncAggregate(string $column, int $targetId): void
    {
        self::assertTargetColumn($column);

        $table = self::AGGREGATE_TABLES[$column];

        DB::table($table)->where('id', $targetId)->update([
            'stock' => DB::table('branch_stocks')
                ->selectRaw('COALESCE(SUM(stock), 0)')
                ->where($column, $targetId),
        ]);
    }

    private static function assertTargetColumn(string $column): void
    {
        if (! in_array($column, self::TARGET_COLUMNS, true)) {
            throw new \InvalidArgumentException("Columna de inventario no valida: {$column}");
        }
    }
}
