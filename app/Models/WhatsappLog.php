<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envio saliente de WhatsApp, sin importar el proveedor real
 * (WhatsAppCloudClient o NexoluCommsChannel - ver LoggingMessagingChannel,
 * el unico punto que escribe aca). Espejo de EmailLog para la pantalla de
 * Comunicaciones de SuperAdmin, que combina ambas tablas.
 */
#[Fillable(['business_id', 'type', 'to_phone', 'status', 'error'])]
class WhatsappLog extends Model
{
    use HasFactory;

    const STATUS_SENT = 'sent';

    const STATUS_FAILED = 'failed';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
