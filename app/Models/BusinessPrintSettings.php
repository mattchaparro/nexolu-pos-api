<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Como imprime el negocio la factura cuando tiene impresora de mostrador.
 *
 * Sin fila, valen los defaults de la columna (ver forBusiness()): un negocio
 * que vincula su impresora no tiene que pasar por esta pantalla para que la
 * factura salga sola.
 */
#[Fillable(['business_id', 'auto_print_receipts', 'receipt_copies', 'open_cash_drawer'])]
class BusinessPrintSettings extends Model
{
    use BelongsToBusiness;

    protected $table = 'business_print_settings';

    protected $attributes = [
        'auto_print_receipts' => true,
        'receipt_copies' => 1,
        'open_cash_drawer' => true,
    ];

    protected function casts(): array
    {
        return [
            'auto_print_receipts' => 'boolean',
            'receipt_copies' => 'integer',
            'open_cash_drawer' => 'boolean',
        ];
    }

    /** La configuracion guardada o, si el negocio nunca la toco, los defaults sin persistir. */
    public static function forBusiness(int $businessId): self
    {
        return self::withoutGlobalScope('business')->where('business_id', $businessId)->first()
            ?? new self(['business_id' => $businessId]);
    }
}
