<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un solo nivel de subcategorias: parent_id nulo es categoria de nivel raiz,
 * parent_id apuntando a otra es subcategoria. Una subcategoria nunca tiene
 * hijas propias - se valida en Store/UpdateProductCategoryRequest, no aca,
 * para poder dar un mensaje de error especifico por campo.
 */
#[Fillable(['name', 'description', 'icon', 'parent_id', 'business_id'])]
class ProductCategory extends Model
{
    use BelongsToBusiness, HasFactory;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isSubcategory(): bool
    {
        return $this->parent_id !== null;
    }
}
