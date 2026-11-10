<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de opciones de la biblioteca del negocio (ej. «Salsa», mínimo 1, máximo 2), enlazable a varios productos.
 * Si es repetible (allow_repeat) la misma opción se puede elegir varias veces (doble porción de licor) y el
 * máximo cuenta porciones; si no, cada opción entra una sola vez.
 */
#[Fillable(['business_id', 'name', 'min_choices', 'max_choices', 'allow_repeat', 'sort_order'])]
class ProductOptionGroup extends Model
{
    use BelongsToBusiness;

    protected function casts(): array
    {
        return [
            'min_choices' => 'integer',
            'max_choices' => 'integer',
            'allow_repeat' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_group_product');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
