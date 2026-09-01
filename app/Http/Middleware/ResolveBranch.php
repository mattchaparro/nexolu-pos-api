<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve con que sede opera este request y la deja en BranchContext.
 *
 * Corre para TODO negocio, tambien el monosede: asi las escrituras nacen con
 * su branch_id desde el primer dia y encender multisede despues no obliga a
 * backfillear nada nuevo. Para el monosede es transparente - siempre resuelve
 * su unica sede y el filtro no cambia lo que ve.
 *
 * Orden de resolucion:
 *   1. Header X-Branch-Id con un id  -> esa sede, si el usuario tiene acceso.
 *   2. Header X-Branch-Id: all       -> consolidado, solo admin/dueño.
 *   3. users.default_branch_id       -> su sede habitual, si sigue siendo accesible.
 *   4. La sede principal del negocio -> el caso comun (monosede y primer login).
 *   5. Cualquier sede activa a la que tenga acceso.
 *
 * Si el negocio todavia no tiene sedes (antes de correr branches:ensure-main)
 * no se fija contexto y nada filtra por sede, igual que TenantContext cuando
 * no hay tenant. Degradar asi es a proposito: el despliegue del esquema y el
 * backfill no son atomicos entre si.
 *
 * Un id de otra empresa y uno inaccesible responden lo mismo (403): distinguir
 * "no existe" de "no es tuya" permitiria enumerar sedes de otros negocios.
 */
class ResolveBranch
{
    public const HEADER = 'X-Branch-Id';

    public const ALL = 'all';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->business_id) {
            return $next($request);
        }

        $requested = trim((string) $request->header(self::HEADER, ''));

        if ($requested !== '' && strcasecmp($requested, self::ALL) === 0) {
            abort_unless(
                $user->canManageAllBranches(),
                403,
                'Solo un administrador puede ver la informacion consolidada de todas las sedes.'
            );

            BranchContext::setAllBranches();

            return $next($request);
        }

        if ($requested !== '') {
            abort_unless(ctype_digit($requested), 400, 'El identificador de sede no es valido.');

            $branch = Branch::withoutGlobalScope('business')
                ->where('business_id', $user->business_id)
                ->find((int) $requested);

            abort_unless(
                $branch && $branch->is_active && $user->canAccessBranch($branch),
                403,
                'No tienes acceso a esa sede.'
            );

            BranchContext::set($branch);

            return $next($request);
        }

        if ($branch = $this->fallbackBranch($user)) {
            BranchContext::set($branch);
        }

        return $next($request);
    }

    /**
     * Suelta la sede al terminar. Misma razon que ResolveStorefrontTenant:
     * bajo Octane o en un worker el contenedor sobrevive al request, y una
     * sede colgada haria que lo siguiente consultara como si fuera ese local.
     */
    public function terminate(Request $request, Response $response): void
    {
        BranchContext::forget();
    }

    private function fallbackBranch(mixed $user): ?Branch
    {
        $accessible = Branch::withoutGlobalScope('business')
            ->where('business_id', $user->business_id)
            ->active();

        if (! $user->canManageAllBranches()) {
            $accessible->whereIn('id', $user->branches()->select('branches.id'));
        }

        if ($user->default_branch_id) {
            $preferred = (clone $accessible)->find((int) $user->default_branch_id);

            if ($preferred) {
                return $preferred;
            }
        }

        return (clone $accessible)->orderByDesc('is_main')->orderBy('id')->first();
    }
}
