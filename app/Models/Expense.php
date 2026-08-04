<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'business_id',
    'date',
    'description',
    'value',
    'scope',
    'payment_method',
    'type_id',
    'linkable_type',
    'linkable_id',
])]
class Expense extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    /**
     * Medios de pago validos para un gasto. Es un enum cerrado, NO texto libre:
     * el legacy solo lo restringia en el dropdown del frontend y el backend
     * aceptaba cualquier string (via API o el bot de IA se colaba basura). Aqui
     * se enforza en el request.
     *
     * Se guarda el label capitalizado (no un id en minuscula como en `sales`) a
     * proposito: esta tabla la comparte el monolito legacy todavia en produccion,
     * que lee/escribe estos labels; unificar el vocabulario de payment_method
     * entre tablas (BUG CRITICO 1 del CONTEXT.md legacy) es una migracion de
     * datos coordinada para cuando el monolito se retire, no un cambio por modulo.
     */
    const PAYMENT_METHODS = ['Efectivo', 'Nequi', 'Daviplata', 'Transferencia', 'Tarjeta'];

    /**
     * Traduce un id de metodo de pago del vocabulario de `businesses.payment_methods`
     * (minuscula: cash/efectivo, transfer/transferencia...) al label capitalizado
     * que esta tabla exige. Necesario cuando un gasto se crea automaticamente a partir
     * de un pago que ya se registro en el otro vocabulario (ver PurchaseService::pay) -
     * sin este puente, ese Expense quedaria con un payment_method fuera del enum,
     * reintroduciendo a mano el mismo problema de CUTOVER_TODO.md #1.
     */
    public static function labelForPaymentMethodId(?string $id): string
    {
        return match (strtolower((string) $id)) {
            'cash', 'efectivo' => 'Efectivo',
            'transfer', 'transferencia' => 'Transferencia',
            'nequi' => 'Nequi',
            'daviplata' => 'Daviplata',
            'card', 'tarjeta', 'datafono', 'bold' => 'Tarjeta',
            default => self::PAYMENT_METHODS[0],
        };
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'decimal:2',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class, 'type_id');
    }

    /**
     * Producto opcional al que se asocia el gasto (reposicion de stock, etc.).
     * El vinculo a Ingredient de la app legacy no esta soportado aun: ese
     * modulo no existe en esta API todavia.
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
