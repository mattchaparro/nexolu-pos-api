<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden de cobro con una pasarela externa (Wompi/Wava en el legacy). Este API
 * no implementa el flujo de checkout en si (requiere integracion real con el
 * proveedor) - solo expone lectura de lo que ya exista en la tabla
 * compartida, para que el superadmin pueda auditarlo.
 */
#[Fillable([
    'business_id',
    'order_key',
    'amount_cop',
    'ai_addon_included',
    'ai_addon_amount_cop',
    'subscription_days',
    'status',
    'provider',
    'provider_order_id',
    'confirmed_at',
    'payload',
])]
class SubscriptionCheckoutOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ai_addon_included' => 'boolean',
            'confirmed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
