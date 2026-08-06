<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hace cumplir un permiso granular del catalogo (ver App\Support\PermissionCatalog)
 * en una ruta de negocio. El rol admin siempre pasa (hereda todo por rol,
 * igual que en EmployeeController/AiToolInvokeController); un empleado
 * necesita el permiso asignado directo a el.
 *
 * Uso: ->middleware('permission:clients.manage')
 * Con varios, basta con tener uno: ->middleware('permission:expenses.create,expenses.manage')
 */
class EnsureBusinessPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);

        if ($user->hasRole('admin')) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            // hasPermissionTo() lanza PermissionDoesNotExist si el permiso
            // todavia no existe en la base (p.ej. permissions:sync no corrio
            // despues de un deploy que agrego uno al catalogo) - eso es
            // "el empleado no lo tiene", no un error 500.
            try {
                if ($user->hasPermissionTo($permission, 'web')) {
                    return $next($request);
                }
            } catch (PermissionDoesNotExist) {
                continue;
            }
        }

        abort(403, 'No tienes permiso para esta accion.');
    }
}
