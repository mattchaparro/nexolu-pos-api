<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarjeta o cuenta Nequi ya tokenizada PARA REUSO via Nexolu Payments Core
 * ("Fuentes de Pago" de Wompi, ver App\Services\PaymentsCoreService::
 * createPaymentSource()) -- distinto de un intent de cobro puntual
 * (SubscriptionCheckoutOrder). `payment_source_id` es lo que se reenvia al
 * Core para cobrar sin que el negocio tenga que volver a tokenizar.
 * `status='removed'` es un soft-delete local: Wompi no permite anular una
 * fuente de pago normal (confirmado en sandbox, ver
 * docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 9), asi que "eliminar" del
 * lado del usuario nunca borra la fila ni llama a Wompi.
 */
#[Fillable([
    'business_id',
    'provider_slug',
    'payment_source_id',
    'type',
    'label',
    'status',
])]
class BusinessPaymentSource extends Model
{
    use HasFactory;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
