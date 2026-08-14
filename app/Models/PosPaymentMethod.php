<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catalogo global de medios de pago del POS, administrado por SuperAdmin -
 * ver la nota completa en database/legacy-schema/schema.sql sobre por que
 * se llama "pos_payment_methods" y no "payment_methods" (esa tabla ya
 * existe, es del cobro de la suscripcion de Nexolu al negocio, algo
 * totalmente distinto). Nunca se borra un registro de aca, solo se
 * desactiva (`is_active`) - ver PosPaymentMethodController.
 */
#[Fillable(['key', 'label', 'is_active', 'sort_order'])]
class PosPaymentMethod extends Model
{
    use HasFactory;

    // Defaults a nivel de modelo (no solo columna DB) para que un create()
    // sin 'is_active'/'sort_order' explicitos (StorePosPaymentMethodRequest
    // los marca 'sometimes') devuelva el valor real sin necesitar un
    // ->fresh() extra despues de crear.
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsToMany<Business, $this> */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_pos_payment_methods')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }
}
