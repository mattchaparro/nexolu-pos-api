<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago de suscripcion de un negocio a Nexolu (no confundir con `payments`/
 * `receivables`, que son cobros del negocio a SUS clientes). Cada fila la crea
 * el superadmin (manual o via activate()) o, eventualmente, la pasarela de pago.
 */
#[Fillable([
    'business_id',
    'amount_cop',
    'period_label',
    'days_granted',
    'paid_at',
    'payment_method',
    'notes',
    'recorded_by_user_id',
])]
class SaasSubscriptionPayment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'paid_at' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
