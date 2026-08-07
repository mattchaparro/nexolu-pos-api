<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de flujo de estados para ordenes de servicio (etapas propias del
 * negocio: "Recibido", "En reparacion", "Listo", etc.). Un negocio usa como
 * maximo un workflow a la vez (business_service_workflows.business_id es
 * unico). La ejecucion real de estas etapas dentro de ServiceOrderService
 * es un cambio aparte, mas grande - esto solo administra las plantillas.
 */
#[Fillable(['name', 'description'])]
class ServiceWorkflow extends Model
{
    use HasFactory;

    public function stages(): HasMany
    {
        return $this->hasMany(ServiceWorkflowStage::class, 'workflow_id')->orderBy('sort_order');
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_service_workflows', 'workflow_id', 'business_id');
    }
}
