<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Filtro de sede para las consultas que NO pasan por el modelo.
 *
 * Casi todos los reportes consultan via Eloquent (`Sale::where(...)`), asi que
 * heredan el global scope de BelongsToBranch y se volvieron multisede sin
 * tocarlos. Las que agregan con joins crudos sobre `sale_items` o `DB::table`
 * se lo saltan: el scope vive en el modelo Sale, no en la tabla `sales`
 * cuando se la joinea desde otro lado.
 *
 * Esa mezcla es peor que no tener el filtro en ninguna: el dueño veria la
 * venta del dia de UNA sede junto a la rotacion de productos de TODAS, dos
 * cifras del mismo tablero que no cuadran entre si y ninguna pista de por que.
 *
 * Sin sede activa (consolidado, comandos, correos) no filtra nada, igual que
 * el resto del sistema.
 */
final class BranchFilter
{
    /**
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @param  string  $qualifier  tabla o alias que lleva branch_id ('sales', 's', 'so'...)
     * @return TQuery
     */
    public static function apply(mixed $query, string $qualifier): mixed
    {
        $branchId = BranchContext::branchId();

        if ($branchId === null) {
            return $query;
        }

        return $query->where("{$qualifier}.branch_id", $branchId);
    }
}
