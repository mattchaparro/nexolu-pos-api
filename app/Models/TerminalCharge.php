<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro disparado contra un datafono, mientras se espera al cliente.
 *
 * Existe porque el cobro NO es instantaneo: el backend le dice al aparato
 * que muestre el monto y despues hay que esperar a que pasen la tarjeta.
 * Esta fila es lo que la caja consulta mientras espera y lo que el webhook
 * encuentra cuando llega la respuesta.
 *
 * La venta no se crea aca (ver STATUS_CONSUMED).
 */
#[Fillable([
    'business_id', 'user_id', 'business_payment_terminal_id', 'reference',
    'provider_slug', 'provider_charge_id', 'amount', 'status', 'sale_id',
    'failure_reason', 'resolved_at',
])]
class TerminalCharge extends Model
{
    use BelongsToBusiness, HasFactory;

    /** Esperando que el cliente pase la tarjeta. */
    public const STATUS_PENDING = 'pending';

    /** Cobrado, pero todavia sin venta: la caja tiene que cerrarla. */
    public const STATUS_APPROVED = 'approved';

    /**
     * Ya se convirtio en venta. Sin este estado un cobro aprobado podria
     * facturarse dos veces (dos pestañas, un reintento del navegador).
     */
    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_ERROR = 'error';

    public const STATUS_VOIDED = 'voided';

    /** Nadie lo cerro y se dejo de esperar. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Cuanto se espera antes de dar por perdido un cobro que nunca respondio.
     * Generoso a proposito: Bold encola el cobro si el datafono esta
     * bloqueado, y no promete un maximo.
     */
    public const EXPIRE_AFTER_MINUTES = 30;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(BusinessPaymentTerminal::class, 'business_payment_terminal_id');
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Cobrado y todavia sin facturar: se puede cerrar la venta con el. */
    public function isRedeemable(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
