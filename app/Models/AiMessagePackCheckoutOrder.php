<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden de cobro self-serve de un paquete de mensajes de IA, mismo patron que
 * SubscriptionCheckoutOrder: se crea en pending, se manda a Nexolu Payments
 * Core, y la confirmacion real llega por webhook (ver
 * PaymentsCoreWebhookController::approve()). Sin equivalente en el legacy -
 * ver comentario en database/legacy-schema/schema.sql.
 */
#[Fillable([
    'business_id',
    'order_key',
    'messages',
    'price_cop',
    'status',
    'provider',
    'provider_order_id',
    'created_by_user_id',
    'confirmed_at',
    'payload',
])]
class AiMessagePackCheckoutOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'messages' => 'integer',
            'price_cop' => 'integer',
            'confirmed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
