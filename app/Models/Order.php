<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pedido de la tienda online.
 *
 * No es una venta: la Sale se crea cuando el comerciante CONFIRMA el pedido
 * (ahi sale el stock de verdad) y queda enlazada en `sale_id`. Ver
 * OrderService y el docblock de la migracion.
 */
#[Fillable([
    'business_id', 'number', 'status', 'subtotal', 'shipping_fee', 'total',
    'discount_id', 'coupon_code', 'discount_amount',
    'customer_name', 'customer_phone', 'customer_email',
    'is_pickup', 'shipping_address', 'shipping_city', 'shipping_notes',
    'public_token', 'expires_at', 'confirmed_at', 'sale_id', 'client_id',
    'payment_provider', 'payment_reference', 'payment_url', 'paid_at',
])]
class Order extends Model
{
    use BelongsToBranch, BelongsToBusiness, HasFactory;

    /**
     * Recien creado. Si el negocio no cobra en linea, espera a que el
     * comerciante lo confirme a mano; si cobra, espera el pago.
     */
    public const STATUS_PENDING = 'pending';

    /** Confirmado: existe la venta y el stock ya salio. */
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    /** Nadie lo confirmo y su reserva de stock vencio. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * A donde puede moverse un pedido desde cada estado. Tenerlo explicito
     * evita que la UI (o un cliente cualquiera) invente transiciones como
     * "entregado -> pendiente".
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_PREPARING, self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_PREPARING => [self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_SHIPPED => [self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXPIRED => [],
    ];

    /** Estados que todavia retienen stock por la reserva blanda. */
    public const RESERVING_STATUSES = [self::STATUS_PENDING];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'is_pickup' => 'boolean',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Ya no admite cambios: se cerro, se cancelo o vencio. */
    public function isClosed(): bool
    {
        return (self::TRANSITIONS[$this->status] ?? []) === [];
    }
}
