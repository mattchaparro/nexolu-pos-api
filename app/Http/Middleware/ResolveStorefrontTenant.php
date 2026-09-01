<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Business;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de entrada de la tienda online: resuelve el negocio por el `slug`
 * de la URL y lo deja como tenant activo para el resto del request.
 *
 * Es el unico punto donde un request SIN usuario autenticado adquiere un
 * tenant, asi que aca vive toda la decision de "esta tienda es visible":
 * despues de este middleware, el global scope de BelongsToBusiness ya filtra
 * solo y ninguna consulta del storefront puede cruzarse de negocio.
 *
 * Todo lo que no es visible responde 404, nunca 403: un negocio inexistente,
 * uno inactivo y uno con el modulo apagado tienen que ser indistinguibles
 * desde afuera. Con 403 se podria enumerar que slugs existen en la
 * plataforma y quien es cliente de Nexolu.
 */
class ResolveStorefrontTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $business = $this->resolveBusiness($request);

        if (! $business || ! $business->active || ! $business->hasFeature('online_store')) {
            abort(404);
        }

        // Dos interruptores distintos: el feature flag lo enciende SuperAdmin
        // (decision comercial) y `is_active` lo publica el comerciante
        // (decision operativa). Un negocio puede tener el modulo habilitado y
        // la tienda todavia cerrada mientras la arma.
        //
        // withoutGlobalScope('business') es imprescindible: BusinessStoreSettings
        // usa BelongsToBusiness, asi que con un empleado de OTRO negocio
        // logueado el scope filtraria por el negocio del usuario y no
        // encontraria la configuracion de la tienda que se esta visitando -
        // la tienda respondia 404 solo para ese visitante. Este middleware es
        // la autoridad que resuelve el tenant, y corre ANTES de fijarlo.
        $settings = $business->storeSettings()->withoutGlobalScope('business')->first();
        if (! $settings || ! $settings->is_active) {
            abort(404);
        }

        TenantContext::set($business);

        // La tienda despacha desde UNA sede, y fijarla aca hace que todo lo
        // de adentro la herede sin saber que existe: el catalogo muestra el
        // stock de ese local (ver HasBranchStock::stockAt), los precios usan
        // sus overrides, el pedido nace con esa sede y el descuento de stock
        // al confirmarlo sale de ahi. La alternativa era acordarse de pasarla
        // en cuatro sitios distintos.
        //
        // Sin ella el comprador veria el total del negocio y podria comprar
        // algo que solo existe en el otro local, a dos horas.
        if ($branchId = $settings->fulfillmentBranchId()) {
            if ($branch = Branch::withoutGlobalScopes()->find($branchId)) {
                BranchContext::set($branch);
            }
        }

        $request->attributes->set('storeSettings', $settings);

        return $next($request);
    }

    /**
     * Suelta el tenant al terminar la peticion.
     *
     * Con php-fpm cada peticion arranca con un contenedor limpio y esto no
     * haria falta, pero el contexto tiene prioridad sobre la sesion (ver
     * TenantContext::businessId()): si sobrevive, lo siguiente que corra en
     * el mismo proceso consulta como si fuera esa tienda. Se detecto en un
     * test donde el listado de pedidos del POS devolvia los de OTRO negocio
     * despues de un checkout publico, y seria un fallo real bajo Octane o en
     * un worker de colas.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
        BranchContext::forget();
    }

    /**
     * Acepta tanto el modelo ya resuelto por route model binding
     * (`{business:slug}`) como el slug crudo, para no depender del orden en
     * que corra SubstituteBindings.
     */
    private function resolveBusiness(Request $request): ?Business
    {
        $parameter = $request->route('business');

        if ($parameter instanceof Business) {
            return $parameter;
        }

        if (! is_string($parameter) || $parameter === '') {
            return null;
        }

        return Business::where('slug', $parameter)->first();
    }
}
