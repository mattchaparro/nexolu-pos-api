<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * La tasa del dolar de un dia (ver App\Services\ExchangeRateService). Tabla
 * ya existia en el schema compartido - legacy tambien le escribe.
 *
 * Sin BelongsToBusiness a proposito: la tasa es de la plataforma, no de un
 * negocio, y ese trait depende de auth() - un no-op silencioso fuera de un
 * request con sesion (comandos, jobs, reportes de costo), justo donde vive
 * esta tabla.
 */
#[Fillable(['date', 'usd_cop', 'source', 'fetched_at'])]
class ExchangeRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'usd_cop' => 'decimal:4',
            'fetched_at' => 'datetime',
        ];
    }
}
