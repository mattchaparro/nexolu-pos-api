<?php

namespace App\Traits;

use App\Models\Branch;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dimension de sede para lo que ocurre fisicamente en un local. Se usa
 * SIEMPRE junto a BelongsToBusiness, nunca en su lugar: la sede acota dentro
 * del negocio, no reemplaza el aislamiento entre negocios.
 *
 * Diferencia deliberada con BelongsToBusiness: alli el business_id es
 * AUTORIDAD (sobrescribe lo que venga del cliente, porque un usuario jamas
 * escribe legitimamente en otro negocio). Aca solo se rellena si viene vacio,
 * porque escribir en otra sede SI es legitimo: un traslado de inventario
 * apunta a la sede destino, y un admin puede registrar un gasto de la sede 2
 * desde la vista consolidada.
 *
 * Consecuencia directa: cualquier endpoint que acepte un branch_id del
 * cliente TIENE que validar que sea una sede del negocio y que el usuario
 * tenga acceso. El trait no lo hace por ti.
 *
 * Al aplicarlo a un modelo, primero hay que backfillear su branch_id (ver
 * App\Console\Commands\EnsureMainBranch): el global scope filtra por sede, y
 * las filas que quedaran en NULL desaparecerian de las consultas.
 *
 * Sin sede resuelta (comandos, jobs, seeders, tests sin actingAs) no se
 * filtra nada, igual que BelongsToBusiness.
 */
trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        static::creating(function ($model) {
            if ($model->branch_id !== null) {
                return;
            }

            $model->branch_id = BranchContext::branchId();
        });

        static::addGlobalScope('branch', function (Builder $query) {
            $branchId = BranchContext::branchId();

            if ($branchId !== null) {
                $query->where($query->getModel()->getTable().'.branch_id', $branchId);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
