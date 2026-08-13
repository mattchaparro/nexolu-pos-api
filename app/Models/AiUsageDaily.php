<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila por negocio+dia con el consumo de mensajes de IA (ver
 * App\Services\AiQuotaService): permite sumar el consumo del mes vigente sin
 * escanear una tabla de conversaciones/mensajes completa. Deliberadamente SIN
 * BelongsToBusiness, igual que WhatsAppUsageDaily: ese scope depende de
 * auth() y las escrituras aca tambien vienen de jobs de WhatsApp sin sesion.
 */
#[Fillable(['business_id', 'date', 'messages_count', 'input_tokens', 'output_tokens', 'cost_micros'])]
class AiUsageDaily extends Model
{
    use HasFactory;

    protected $table = 'ai_usage_daily';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'messages_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
        ];
    }
}
