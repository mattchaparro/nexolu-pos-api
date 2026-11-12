<?php

namespace App\Capabilities\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Busqueda de productos por como los nombra la gente, no por como estan
 * escritos en el catalogo.
 *
 * El asistente recibe «reno 14f» y el catalogo dice «Reno 14 F 256GB»: un
 * `LIKE '%reno 14f%'` no lo encuentra. Aca cada palabra tiene que aparecer en
 * el nombre o el SKU (en cualquier orden), o el texto sin espacios tiene que
 * estar en el nombre sin espacios («reno14f» dentro de «reno14f256gb»).
 * La intercalacion de MySQL ya ignora mayusculas y tildes.
 */
trait SearchesProducts
{
    private function whereProductMatches(Builder $query, string $search): Builder
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($search)) ?: [], fn ($w) => $w !== ''));
        $compact = preg_replace('/\s+/', '', $search) ?? '';

        if ($words === []) {
            return $query;
        }

        return $query->where(function (Builder $match) use ($words, $compact) {
            $match->where(function (Builder $allWords) use ($words) {
                foreach ($words as $word) {
                    $allWords->where(fn (Builder $one) => $one
                        ->where('products.name', 'like', '%'.$word.'%')
                        ->orWhere('products.sku', 'like', '%'.$word.'%'));
                }
            })->orWhereRaw("REPLACE(products.name, ' ', '') LIKE ?", ['%'.$compact.'%']);
        });
    }
}
