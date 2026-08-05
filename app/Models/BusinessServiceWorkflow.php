<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un negocio usa como maximo un workflow (business_id es UNIQUE). */
#[Fillable(['business_id', 'workflow_id'])]
class BusinessServiceWorkflow extends Model
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ServiceWorkflow::class, 'workflow_id');
    }
}
