<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Rastro de cada corrida de un job programado (ver App\Support\CronJobLogger).
 * Sin BelongsToBusiness: es dato de plataforma, no de un negocio en particular.
 * Sin timestamps de Eloquent: usa `ran_at` (default CURRENT_TIMESTAMP de la
 * propia columna), no created_at/updated_at.
 *
 * A diferencia de legacy, `ran_at` se castea normal a datetime: esta API
 * corre con config('app.timezone')='UTC' (igual que la columna), asi que no
 * hace falta el accessor manual que legacy necesitaba para no reinterpretar
 * la hora UTC guardada como si fuera America/Bogota (su config('app.timezone')).
 */
#[Fillable(['job_key', 'status', 'output', 'triggered_by', 'ran_at'])]
class CronJobLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    const STATUS_SUCCESS = 'success';

    const STATUS_ERROR = 'error';

    const TRIGGERED_BY_SCHEDULER = 'scheduler';

    const TRIGGERED_BY_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
        ];
    }
}
