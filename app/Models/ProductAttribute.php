<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Atributo combinable (Talla, Color, ...) reutilizable por todos los
 * productos del negocio - ver ProductVariant::attributeValues() para como
 * un producto elige valores concretos de estos atributos al armar sus
 * variantes.
 */
#[Fillable(['business_id', 'name'])]
class ProductAttribute extends Model
{
    use BelongsToBusiness, HasFactory;

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort_order');
    }
}
