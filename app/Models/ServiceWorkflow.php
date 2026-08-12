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
 * unico). La ejecucion real de estas etapas vive en ServiceOrderService
 * (ver setStage()) - esto administra las plantillas y resuelve sus etapas
 * especiales (inicial, la que marca pago completo).
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

    /** Etapa en la que cae una orden nueva - la marcada is_initial, o la primera si ninguna lo esta. */
    public function initialStage(): ?ServiceWorkflowStage
    {
        return $this->stages()->where('is_initial', true)->first() ?? $this->stages()->first();
    }

    /** Etapa a la que se mueve una orden automaticamente al quedar pagada. */
    public function stageForPaymentComplete(): ?ServiceWorkflowStage
    {
        return $this->stages->first(fn (ServiceWorkflowStage $stage) => $stage->hasAction('trigger_on_payment_complete'));
    }
}
