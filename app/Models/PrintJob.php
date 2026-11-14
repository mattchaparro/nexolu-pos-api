<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ticket en la cola de impresion.
 *
 * pending -> claimed (un agente lo aparto por unos segundos) -> printed. Si
 * el agente no confirma a tiempo, vuelve a estar disponible; si falla,
 * queda `failed` despues de MAX_ATTEMPTS y el POS lo muestra para
 * reintentar. Reimprimir es un ticket NUEVO, nunca reabrir uno impreso.
 */
#[Fillable([
    'business_id', 'printer_id', 'sale_id', 'type', 'payload', 'status', 'attempts', 'dedupe_key',
    'claimed_until', 'printed_at', 'last_error', 'created_by_user_id',
])]
#[Hidden(['payload'])]
class PrintJob extends Model
{
    use BelongsToBusiness;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    public const TYPE_KITCHEN = 'kitchen_ticket';

    public const TYPE_KITCHEN_ADDITION = 'kitchen_addition';

    public const TYPE_KITCHEN_VOID = 'kitchen_void';

    public const TYPE_RECEIPT = 'receipt';

    /** Factura + corte + ticket de domicilio, en un solo trabajo. */
    public const TYPE_DELIVERY = 'delivery';

    public const TYPE_TEST = 'test';

    /** Tickets de cocina: al confirmarse uno de un pedido online, el pedido pasa a "en preparacion". */
    public const KITCHEN_TYPES = [self::TYPE_KITCHEN, self::TYPE_KITCHEN_ADDITION];

    /** Segundos que un agente tiene un ticket apartado antes de que vuelva a la cola. */
    public const CLAIM_SECONDS = 30;

    /**
     * Espera antes de cada reintento, en segundos. Cambiar el rollo o
     * reiniciar la impresora toma mas que los 2 s del ciclo del agente: sin
     * espera, los intentos se gastaban en 4 s. Con esta, un ticket insiste
     * cerca de dos minutos antes de quedar fallido.
     */
    public const RETRY_DELAYS = [5, 15, 30, 60];

    /** Intentos antes de dejar de reintentar solo. */
    public const MAX_ATTEMPTS = 5;

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_until' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
