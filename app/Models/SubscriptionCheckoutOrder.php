<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden de cobro de la suscripcion mensual con Nexolu Payments Core (ver
 * App\Services\SubscriptionService::initiateCheckout()/checkoutStatus()):
 * se crea en pending y la confirmacion real llega por webhook firmado (ver
 * PaymentsCoreWebhookController::approve()).
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
    'origin',
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
