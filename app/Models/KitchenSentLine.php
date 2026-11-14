<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que la cocina ya tiene impreso de una venta, por plato (producto +
 * variante + opciones). Ver App\Services\KitchenPrintService.
 */
#[Fillable(['sale_id', 'signature', 'label', 'options_label', 'components', 'quantity'])]
class KitchenSentLine extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'components' => 'array'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
