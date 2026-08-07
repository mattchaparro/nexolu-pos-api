<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache de un insight ya redactado (ver App\Services\AiInsightService). Los
 * nombres de columna (`tipo`, `texto`, `datos`, `generado_en`, `expira_en`)
 * son los del schema compartido con legacy - no se traducen, la tabla ya
 * existe en produccion.
 */
#[Fillable(['business_id', 'tipo', 'texto', 'datos', 'input_tokens', 'output_tokens', 'cost_micros', 'generado_en', 'expira_en'])]
class AiInsight extends Model
{
    use BelongsToBusiness;

    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'generado_en' => 'datetime',
            'expira_en' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return $this->expira_en !== null && $this->expira_en->isFuture();
    }
}
