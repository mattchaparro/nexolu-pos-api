<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rastro de auditoria de cada paquete de mensajes de IA acreditado a un
 * negocio (ver App\Services\AiMessagePackService::credit()). Solo se
 * registran paquetes ya acreditados - el estado "pendiente" de una compra
 * self-serve vive en AiMessagePackCheckoutOrder, no aca (mismo split que
 * SubscriptionCheckoutOrder vs SaasSubscriptionPayment).
 */
#[Fillable(['business_id', 'messages', 'price_cop', 'created_by_user_id', 'notes'])]
class AiMessagePackPurchase extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'messages' => 'integer',
            'price_cop' => 'integer',
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
