<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un clic en un enlace de contacto de la tienda.
 *
 * Sin IP ni user agent a proposito: para "cuanta gente escribe desde mi
 * tienda" alcanza con la cuenta y el contexto.
 */
#[Fillable(['business_id', 'type', 'context'])]
class BusinessStoreInteraction extends Model
{
    use BelongsToBusiness, HasFactory;

    public const TYPE_WHATSAPP = 'whatsapp';

    /** Solo `created_at`: una interaccion no se edita. */
    public const UPDATED_AT = null;
}
