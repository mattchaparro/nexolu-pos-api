<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos de facturacion del negocio (documento, nombre, telefono, direccion),
 * un unico perfil por negocio - se pide una sola vez (registro o primer pago
 * por PSE) y de ahi en adelante queda prellenado. Todo nullable a proposito:
 * completar esto nunca es un requisito duro. Tabla nueva, ver comentario en
 * database/legacy-schema/schema.sql.
 */
#[Fillable([
    'business_id',
    'document_type',
    'document_number',
    'full_name',
    'phone',
    'email',
    'address',
    'city',
])]
class BillingProfile extends Model
{
    use HasFactory;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
