<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class StockMovementReason extends Model
{
    protected $fillable = [
        'business_id',
        'code',
        'label',
    ];

    /**
     * Motivos "de sistema" usados por StockService al registrar movimientos
     * que no vienen de un ajuste manual con motivo elegido por el usuario.
     */
    const CODE_MANUAL_IN = 'manual_in';

    const CODE_MANUAL_OUT = 'manual_out';

    const CODE_ADJUSTMENT = 'adjustment';

    const CODE_SALE = 'sale';

    const CODE_SALE_REVERSAL = 'sale_reversal';

    const CODE_PURCHASE = 'purchase';

    const CODE_WASTE = 'waste';

    const CODE_DAMAGE = 'damage';

    const CODE_LAYAWAY = 'layaway';

    const CODE_LAYAWAY_CANCEL = 'layaway_cancel';

    /** Las dos mitades de un traslado entre sedes (ver StockTransferService). */
    const CODE_TRANSFER_OUT = 'transfer_out';

    const CODE_TRANSFER_IN = 'transfer_in';

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'stock_movement_reason_id');
    }

    /**
     * ID del motivo global (business_id null) por código estable. Cacheado
     * indefinidamente porque los motivos de sistema no cambian en caliente.
     */
    public static function systemIdForCode(string $code): ?int
    {
        return Cache::rememberForever('stock_movement_reason_id_'.$code, function () use ($code) {
            return static::query()
                ->whereNull('business_id')
                ->where('code', $code)
                ->value('id');
        });
    }
}
