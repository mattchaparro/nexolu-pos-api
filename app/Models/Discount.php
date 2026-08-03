<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'name', 'type', 'value', 'scope', 'product_id', 'is_active'])]
class Discount extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function computeAmount(float $subtotal): float
    {
        $amount = $this->type === 'percentage'
            ? round($subtotal * $this->value / 100, 2)
            : $this->value;

        return min($amount, $subtotal);
    }

    /**
     * Resuelve un descuento activo de un negocio/scope y calcula su monto sobre
     * $subtotal. Punto unico usado por SaleService y OpenTabService para no
     * repetir la misma consulta con la misma forma en cada lugar que aplica un
     * descuento (item o carrito) - ver la nota de "4 sitios duplicados" del
     * CONTEXT.md legacy sobre por que eso es justo lo que hay que evitar.
     *
     * @return array{0: ?int, 1: float} [discount_id resuelto, monto]
     */
    public static function resolveActive(int $businessId, string $scope, ?int $discountId, float $subtotal): array
    {
        if (! $discountId) {
            return [null, 0.0];
        }

        $discount = static::where('business_id', $businessId)
            ->where('scope', $scope)
            ->where('is_active', true)
            ->find($discountId);

        if (! $discount) {
            return [null, 0.0];
        }

        return [$discount->id, $discount->computeAmount($subtotal)];
    }
}
