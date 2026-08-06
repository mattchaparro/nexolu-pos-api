<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Deliberadamente SIN BelongsToBusiness: ese scope depende de auth() y es un
 * no-op silencioso fuera de un request con sesion (jobs, comandos) -
 * exactamente donde se escribe esta tabla (WhatsAppUsageRecorder).
 * business_id es nullable a proposito: un OTP puede salir a un numero que
 * todavia no esta vinculado a ningun negocio.
 */
#[Fillable(['business_id', 'date', 'category', 'messages_count', 'cost_micros'])]
class WhatsAppUsageDaily extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_usage_daily';

    const CATEGORY_MARKETING = 'marketing';

    const CATEGORY_UTILITY = 'utility';

    const CATEGORY_AUTHENTICATION = 'authentication';

    // Respuestas dentro de la ventana de 24h: Meta no cobra, pero igual se
    // registra por volumen.
    const CATEGORY_SERVICE = 'service';

    const CATEGORIES = [
        self::CATEGORY_MARKETING,
        self::CATEGORY_UTILITY,
        self::CATEGORY_AUTHENTICATION,
        self::CATEGORY_SERVICE,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'messages_count' => 'integer',
            'cost_micros' => 'integer',
        ];
    }
}
