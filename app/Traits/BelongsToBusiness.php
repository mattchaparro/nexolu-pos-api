<?php

namespace App\Traits;

use App\Models\Business;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenancy foundation: auto-assigns business_id from the current tenant
 * on create, and scopes every query to that tenant by default.
 *
 * El tenant sale del usuario autenticado, y si no hay sesion, del
 * App\Support\TenantContext que setea el middleware de la tienda online para
 * los visitantes anonimos (ver el docblock de TenantContext).
 *
 * Queries made with neither an authenticated user nor a tenant context
 * (console commands, queued jobs, tests without acting as a user) are NOT
 * scoped - use ->withoutGlobalScope('business') explicitly in those
 * contexts, or set business_id manually before creating.
 */
trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            // Autoridad, no sugerencia: con un tenant resuelto el business_id
            // SIEMPRE es el suyo, sobrescribiendo cualquier valor que venga del
            // cliente. Cierra la inyeccion de tenant (business_id esta en
            // fillable; un create() con input crudo podria colar otro id). Sin
            // tenant (seeders, jobs, tests) se respeta el business_id explicito.
            $businessId = TenantContext::businessId();

            if ($businessId !== null) {
                $model->business_id = $businessId;
            }
        });

        static::addGlobalScope('business', function (Builder $query) {
            $businessId = TenantContext::businessId();

            if ($businessId !== null) {
                $query->where($query->getModel()->getTable().'.business_id', $businessId);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
