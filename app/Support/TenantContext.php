<?php

namespace App\Support;

use App\Models\Business;

/**
 * Autoridad de tenant cuando NO hay usuario autenticado.
 *
 * Todo el aislamiento multi-tenant de este repo cuelga de
 * `auth()->user()->business_id` (ver App\Traits\BelongsToBusiness). Eso
 * alcanzaba mientras cada request venia de un empleado con sesion, pero la
 * tienda online es el primer consumidor PUBLICO de esta API: sus visitantes
 * son anonimos, y sin usuario el global scope no filtra nada - un
 * `Product::find($id)` en un endpoint publico devolveria el producto de
 * cualquier negocio.
 *
 * Este contexto es el sustituto explicito de esa autoridad: lo setea
 * App\Http\Middleware\ResolveStorefrontTenant despues de resolver el negocio
 * por su slug, y BelongsToBusiness lo consulta como fallback. Asi el
 * aislamiento sigue siendo una garantia del modelo y no algo que cada query
 * publica tenga que acordarse de escribir.
 *
 * Se guarda en el contenedor y no en una propiedad estatica a proposito: un
 * worker de colas vive entre jobs, y una estatica arrastraria el tenant de
 * un job al siguiente. El contenedor se reinicia por request/por job.
 */
final class TenantContext
{
    private const KEY = 'nexolu.tenant.business';

    public static function set(Business $business): void
    {
        app()->instance(self::KEY, $business);
    }

    public static function current(): ?Business
    {
        return app()->bound(self::KEY) ? app()->make(self::KEY) : null;
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::KEY);
    }

    /**
     * Negocio por el que hay que filtrar: el tenant explicito si se fijo, y
     * si no el del usuario autenticado.
     *
     * El tenant explicito GANA sobre la sesion, y el orden importa: un
     * empleado con sesion abierta que navega la tienda publica de otro
     * comercio tiene que ver ESE catalogo, no el suyo. Con la prioridad al
     * reves veia su propio inventario dentro de la tienda ajena (bug real,
     * lo detecto StorefrontCatalogTest).
     *
     * Invertirlo es seguro porque el contexto no se puede colar desde fuera:
     * lo fija unicamente ResolveStorefrontTenant, en rutas publicas de
     * storefront y despues de validar el slug. En las rutas normales del POS
     * nadie lo setea, asi que manda la sesion como siempre.
     *
     * Devuelve null cuando no hay ninguno de los dos (comandos, jobs,
     * seeders, tests sin actingAs), donde a proposito no se scopea - ver el
     * docblock de BelongsToBusiness.
     */
    public static function businessId(): ?int
    {
        if ($explicit = self::current()) {
            return $explicit->id;
        }

        if (auth()->check() && auth()->user()->business_id) {
            return (int) auth()->user()->business_id;
        }

        return null;
    }
}
