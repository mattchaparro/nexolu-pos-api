<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** Foto de una opción elegida en una línea de venta (nombres y precio copiados). */
#[Fillable([
    'sale_item_id', 'product_option_id', 'group_name', 'name',
    'extra_price', 'ingredient_id', 'ingredient_quantity',
])]
class SaleItemOption extends Model
{
    protected function casts(): array
    {
        return [
            'extra_price' => 'decimal:2',
            'ingredient_quantity' => 'decimal:3',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /**
     * Junta las porciones repetidas de una línea (una fila por porción) para
     * mostrarlas: «Jack Daniels» dos veces sale una vez con quantity 2.
     *
     * @param  Collection<int, SaleItemOption>  $options
     * @return Collection<int, array{group: string, name: string, quantity: int, extra_price: float}>
     */
    public static function tally(Collection $options): Collection
    {
        return $options
            ->groupBy(fn (SaleItemOption $o) => $o->product_option_id ? 'id:'.$o->product_option_id : 'name:'.$o->group_name.'|'.$o->name)
            ->map(fn (Collection $same) => [
                'group' => (string) $same->first()->group_name,
                'name' => (string) $same->first()->name,
                'quantity' => $same->count(),
                'extra_price' => round((float) $same->sum(fn (SaleItemOption $o) => (float) $o->extra_price), 2),
            ])
            ->values();
    }

    /**
     * Texto corto de la elección: «Jack Daniels ×2, Tamarindo».
     *
     * @param  Collection<int, SaleItemOption>  $options
     */
    public static function label(Collection $options): string
    {
        return self::tally($options)
            ->map(fn (array $o) => $o['quantity'] > 1 ? "{$o['name']} ×{$o['quantity']}" : $o['name'])
            ->implode(', ');
    }
}
