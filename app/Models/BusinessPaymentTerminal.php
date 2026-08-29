<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un datafono fisico del negocio, sincronizado desde el proveedor.
 *
 * No se crea a mano: sale de `GET /payments/binded-terminals`, que solo
 * devuelve los que el comerciante expuso habilitando "Conexiones API" en su
 * app de Bold. Ese paso es manual suyo y no se puede automatizar.
 */
#[Fillable(['business_id', 'serial', 'model', 'name', 'status', 'is_active', 'last_synced_at'])]
class BusinessPaymentTerminal extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** Bold marca `BINDED` los que estan realmente vinculados. */
    public function isUsable(): bool
    {
        return $this->is_active && strtoupper((string) $this->status) === 'BINDED';
    }

    /** Lo que ve el cajero. El serial no le dice nada. */
    public function displayName(): string
    {
        return $this->name ?: $this->serial;
    }
}
