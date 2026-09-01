<?php

namespace App\Models;

use App\Services\BranchService;
use App\Support\ProductAvailability;
use App\Traits\BelongsToBranch;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToBranch, BelongsToBusiness, HasFactory;

    const TYPE_ENTRY = 'entry';

    const TYPE_EXIT = 'exit';

    const TYPE_ADJUSTMENT = 'adjustment';

    const TYPE_SALE = 'sale';

    const TYPES = [self::TYPE_ENTRY, self::TYPE_EXIT, self::TYPE_ADJUSTMENT, self::TYPE_SALE];

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'ingredient_id',
        'business_id',
        'branch_id',
        'type',
        'stock_movement_reason_id',
        'purchase_line_id',
        'stock_transfer_id',
        'quantity',
        'unit_cost_cop',
        'reference',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_cost_cop' => 'float',
        ];
    }

    /**
     * El stock del producto/ingrediente SIEMPRE se mueve a través de un
     * StockMovement, nunca con un update directo a products.stock/
     * ingredients.stock: así queda auditoria completa de cada entrada/salida
     * (quién, cuándo, por qué) en vez del machetazo legacy de mutar la
     * columna sin dejar rastro. product_id e ingredient_id son mutuamente
     * excluyentes (igual que en el schema compartido).
     *
     * Desde multisede el saldo real vive en branch_stocks (por sede) y la
     * columna del catálogo pasó a ser el AGREGADO de todas las sedes, que se
     * recalcula aquí mismo. Todo lo que pregunta "cuánto tiene el negocio en
     * total" (alertas de stock bajo, reportes, tienda online, la
     * desactivación automática de productos de venta única) sigue leyendo esa
     * columna y no se enteró del cambio.
     */
    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement) {
            // BelongsToBranch ya lo llenó desde el contexto del request. Sin
            // contexto (comandos, jobs, la cola de la tienda online) el
            // movimiento igual tiene que aterrizar en una sede concreta: un
            // saldo por sede incompleto haría que el agregado, que se calcula
            // sumando sedes, quedara por debajo del stock real.
            $movement->branch_id ??= self::resolveBranchId($movement);
        });

        static::created(function (StockMovement $movement) {
            // product_variant_id gana sobre product_id: una fila de venta/
            // movimiento de variante siempre trae ambos (product_id = padre,
            // para reportes/legacy; product_variant_id = la variante real),
            // pero el stock que se mueve es el de la variante, no
            // products.stock (columna "fantasma" para un producto con
            // variantes, mismo concepto que ya existe para receta).
            [$column, $targetId] = self::stockTarget($movement);

            if (! $column || ! $movement->branch_id) {
                return;
            }

            BranchStock::add(
                (int) $movement->business_id,
                (int) $movement->branch_id,
                $column,
                $targetId,
                (float) $movement->quantity,
            );
            BranchStock::syncAggregate($column, $targetId);

            // El stock cambió por SQL directo, que no dispara el evento saved
            // de Product - sin esto, el catálogo cacheado de Vender (ver
            // App\Support\ProductAvailability::forBusiness()) mostraría el
            // stock viejo hasta 10 min.
            if ($movement->business_id) {
                ProductAvailability::clearCache((int) $movement->business_id);
            }

            if ($column === 'product_id') {
                self::syncSingleSaleActivation($targetId);
            }
        });
    }

    /**
     * Qué objetivo mueve este movimiento, en el vocabulario de branch_stocks.
     *
     * @return array{0: ?string, 1: int}
     */
    private static function stockTarget(StockMovement $movement): array
    {
        return match (true) {
            (bool) $movement->product_variant_id => ['product_variant_id', (int) $movement->product_variant_id],
            (bool) $movement->product_id => ['product_id', (int) $movement->product_id],
            (bool) $movement->ingredient_id => ['ingredient_id', (int) $movement->ingredient_id],
            default => [null, 0],
        };
    }

    /**
     * Red de seguridad de la invariante "todo negocio tiene sede principal":
     * si el movimiento llega sin sede y el negocio todavía no tiene ninguna
     * (negocio anterior al módulo al que nunca se le corrió
     * branches:ensure-main), se la crea en vez de perder el movimiento.
     * Idempotente - ver BranchService::ensureMainBranch().
     */
    private static function resolveBranchId(StockMovement $movement): ?int
    {
        if (! $movement->business_id) {
            return null;
        }

        return app(BranchService::class)->mainBranchId((int) $movement->business_id);
    }

    /**
     * Un producto de venta única se activa/desactiva solo según su stock
     * TOTAL, no el de una sede: que se haya agotado en un local no lo saca
     * del catálogo si en el otro todavía queda.
     */
    private static function syncSingleSaleActivation(int $productId): void
    {
        $product = Product::withoutGlobalScopes()->find($productId);

        if (! $product || ! $product->is_single_sale || ! $product->track_stock) {
            return;
        }

        $product->refresh();

        if ((int) $product->stock <= 0 && $product->is_active) {
            $product->forceFill(['is_active' => false])->save();
        } elseif ((int) $product->stock > 0 && ! $product->is_active) {
            $product->forceFill(['is_active' => true])->save();
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(StockMovementReason::class, 'stock_movement_reason_id');
    }

    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
