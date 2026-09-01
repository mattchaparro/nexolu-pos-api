<?php

namespace App\Support;

use App\Models\Branch;

/**
 * Sede activa del request, en paralelo a App\Support\TenantContext.
 *
 * Se guarda en el contenedor y no en una estatica por la misma razon que el
 * tenant: un worker de colas vive entre jobs y una estatica arrastraria la
 * sede de un job al siguiente.
 *
 * Tiene DOS modos y la diferencia importa:
 *
 *  - Una sede: el trait BelongsToBranch filtra por ella. Es el modo normal
 *    de operar (vender, abrir caja, mover inventario siempre ocurre en un
 *    local concreto).
 *  - Todas: el filtro por sede se apaga a proposito. Es el modo de los
 *    reportes consolidados, y solo lo puede pedir un admin o dueño (ver
 *    App\Http\Middleware\ResolveBranch).
 *
 * "Todas" NO es lo mismo que "sin sede". Sin sede (comandos, jobs, seeders,
 * tests sin actingAs) tampoco se filtra, pero porque no hay a que filtrar;
 * el modo "todas" es una decision explicita que ademas apaga el filtro aunque
 * el usuario tenga una sede resuelta.
 */
final class BranchContext
{
    private const KEY = 'nexolu.tenant.branch';

    private const ALL_KEY = 'nexolu.tenant.branch.all';

    public static function set(Branch $branch): void
    {
        app()->forgetInstance(self::ALL_KEY);
        app()->instance(self::KEY, $branch);
    }

    /** Consolidado: apaga el filtro por sede para este request. */
    public static function setAllBranches(): void
    {
        app()->forgetInstance(self::KEY);
        app()->instance(self::ALL_KEY, true);
    }

    public static function isAllBranches(): bool
    {
        return app()->bound(self::ALL_KEY);
    }

    public static function current(): ?Branch
    {
        return app()->bound(self::KEY) ? app()->make(self::KEY) : null;
    }

    /** Sede por la que hay que filtrar, o null si no hay que filtrar. */
    public static function branchId(): ?int
    {
        if (self::isAllBranches()) {
            return null;
        }

        return self::current()?->id;
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::KEY);
        app()->forgetInstance(self::ALL_KEY);
    }

    /**
     * Corre algo viendo todas las sedes y restaura el contexto anterior.
     *
     * Es lo que necesita un reporte consolidado que se calcula dentro de un
     * request que SI tiene una sede activa (el dashboard con el selector en
     * "todas" es un caso, pero tambien un job que agrega el dia entero).
     * Restaurar es obligatorio: dejar el contexto en "todas" despues de un
     * calculo haria que el resto del request dejara de filtrar en silencio.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withAllBranches(callable $callback): mixed
    {
        $previous = self::current();
        $wasAll = self::isAllBranches();

        self::setAllBranches();

        try {
            return $callback();
        } finally {
            self::forget();

            if ($previous) {
                self::set($previous);
            } elseif ($wasAll) {
                self::setAllBranches();
            }
        }
    }
}
