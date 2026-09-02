<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id', 'name', 'code', 'type', 'value', 'scope', 'product_id', 'is_active',
    'starts_at', 'ends_at', 'max_uses', 'used_count', 'min_order_amount',
])]
class Discount extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'min_order_amount' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Un cupon es un descuento con codigo: lo redime el COMPRADOR
     * escribiendolo, no el cajero eligiendolo de una lista.
     *
     * La distincion importa para el tope de usos: si el cajero pudiera
     * aplicarlo desde el mostrador sin codigo, `used_count` dejaria de
     * reflejar las redenciones reales y un cupon de 50 usos podria
     * canjearse 200 veces.
     */
    public function isCoupon(): bool
    {
        return $this->code !== null && $this->code !== '';
    }

    /**
     * Por que este cupon no se puede usar HOY para esta compra.
     *
     * Devuelve el motivo en palabras del comprador, o null si es valido. Se
     * devuelve el motivo y no un booleano porque "cupon invalido" a secas
     * hace que la gente lo intente cinco veces: si vencio, o si le falta
     * llegar al minimo, hay que decirlo.
     */
    public function rejectionReason(float $subtotal): ?string
    {
        if (! $this->is_active) {
            return 'Este cupón ya no está disponible.';
        }

        $ahora = now();
        if ($this->starts_at !== null && $ahora->lt($this->starts_at)) {
            return 'Este cupón todavía no está vigente.';
        }

        if ($this->ends_at !== null && $ahora->gt($this->ends_at)) {
            return 'Este cupón ya venció.';
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'Este cupón ya se agotó.';
        }

        if ($this->min_order_amount !== null && $subtotal < (float) $this->min_order_amount) {
            return 'Este cupón aplica desde $'.number_format((float) $this->min_order_amount, 0, ',', '.').'.';
        }

        return null;
    }

    /**
     * Busca un cupon por su codigo. Comparacion en MAYUSCULAS: nadie
     * escribe un cupon respetando el uso de mayusculas del volante.
     */
    public static function findCoupon(int $businessId, string $code): ?self
    {
        $normalizado = strtoupper(trim($code));
        if ($normalizado === '') {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->whereRaw('UPPER(code) = ?', [$normalizado])
            ->first();
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
