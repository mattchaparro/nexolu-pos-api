<?php

namespace App\Traits;

use App\Models\Branch;
use App\Services\BranchService;
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
 * Sin contexto de sede (comandos, jobs, seeders, la cola de la tienda online)
 * la fila aterriza en la sede principal. NO se deja en NULL: el global scope
 * de abajo filtra por sede, asi que una fila sin sede seria invisible para
 * todo el mundo - un gasto programado por cron dejaria de aparecer en la
 * pantalla de gastos. Aterrizar en la principal es la respuesta menos mala y
 * ademas la correcta para el monosede, que es el 100% de los negocios hoy.
 *
 * Al aplicarlo a un modelo hay que backfillear su branch_id primero (ver
 * App\Console\Commands\EnsureMainBranch): las filas que queden en NULL
 * desaparecerian de las consultas.
 */
trait BelongsToBranch
{
    /**
     * Si el modelo ademas FILTRA por sede al leer, o solo la registra.
     *
     * Sobrescribir con false cuando la fila pertenece al negocio y no al
     * local, pero interesa saber donde se origino. El caso real es
     * Receivable: el fiado es una deuda con el negocio, y el cliente tiene
     * que poder abonar en cualquier sede - filtrarlo por sede haria que la
     * caja de enfrente no encuentre la deuda que el cliente viene a pagar.
     */
    public static function scopesByBranch(): bool
    {
        return true;
    }

    protected static function bootBelongsToBranch(): void
    {
        static::creating(function ($model) {
            if ($model->branch_id !== null) {
                return;
            }

            $model->branch_id = BranchContext::branchId()
                ?? ($model->business_id ? app(BranchService::class)->mainBranchId((int) $model->business_id) : null);
        });

        static::addGlobalScope('branch', function (Builder $query) {
            if (! static::scopesByBranch()) {
                return;
            }

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
